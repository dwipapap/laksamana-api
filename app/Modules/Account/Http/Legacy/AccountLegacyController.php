<?php

namespace App\Modules\Account\Http\Legacy;

use App\Modules\Account\Services\AccountService;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use App\Support\Modules;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for account-mysql/api.php — `/account-api-mysql/api.php`.
 * Flat payloads ({ok,user}, {ok,users,…}); only ping/stats use {ok,data}.
 * Unknown action -> {ok:false,error:'unknown_action'} (exact legacy code).
 */
class AccountLegacyController extends LegacyController
{
    public function __construct(private readonly AccountService $account) {}

    protected function dispatch(LegacyRequest $req): Response
    {
        $b = $req->body;
        $a = $this->account;

        // A freshly installed database has nobody who could create the first account.
        // A cutover freeze must keep even this bootstrap write disabled.
        if (! Modules::isInMaintenance('account')) {
            $a->seedIfEmpty();
        }

        $flat = match ($req->action) {
            'login' => $a->login((string) ($b['name'] ?? ''), (string) ($b['pin'] ?? '')),
            'whoami' => $a->whoami($b),
            'logout' => $a->logout($b),
            'changePin' => $a->changePin($b),
            'setUsername' => $a->setUsername($b),
            'listUsers' => $a->listUsers($b),
            'saveUser' => $a->saveUser($b),
            'saveUsers' => $a->saveUsers($b),
            'deleteUser' => $a->deleteUser($b),
            'listModules' => $a->listModules($b),
            'syncModules' => $a->syncModules($b),
            'saveModule' => $a->saveModule($b),
            'setAdmin' => $a->setAdmin($b),
            'listAccess' => $a->listAccess($b),
            'setModuleAccess' => $a->setModuleAccess($b),
            'listModuleMembers' => $a->listModuleMembers($b),
            'listModuleRoster' => $a->listModuleRoster($b),
            'listDivisiRoster' => $a->listDivisiRoster(),
            'rosterSaveUser' => $a->rosterSaveUser($b),
            'rosterSetActive' => $a->rosterSetActive($b),
            'rosterHapusUser' => $a->rosterDeleteUser($b),
            'import' => $a->import($b),
            'sessionRefresh' => $a->sessionRefresh($b),
            'ping' => ['ok' => true, 'data' => $a->ping()],
            'stats' => ['ok' => true, 'data' => $a->stats()],
            default => ['ok' => false, 'error' => 'unknown_action'],
        };

        return Envelope::flat($flat);
    }
}
