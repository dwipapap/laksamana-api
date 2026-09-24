<?php

namespace App\Support\Legacy;

use Illuminate\Http\Request;

/**
 * The request as the old `<modul>-mysql/api.php` backends saw it.
 *
 * Frontends POST JSON with Content-Type text/plain (to dodge the CORS
 * preflight), so Laravel does not parse it — the raw body is decoded here.
 * `action` comes from the body on POST and from the query string on GET,
 * exactly like every legacy api.php (finance reads the query string first;
 * pass $queryFirst for that one).
 */
final class LegacyRequest
{
    /** @param array<string,mixed> $body */
    private function __construct(
        public readonly Request $http,
        public readonly array $body,
        public readonly string $action,
        public readonly string $method,
    ) {}

    public static function from(Request $request, string $defaultGetAction = '', bool $queryFirst = false): self
    {
        $method = strtoupper($request->getMethod());
        $body = [];
        if ($method === 'POST') {
            $decoded = json_decode((string) $request->getContent(), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $fromQuery = $request->query('action');
        $fromBody = $body['action'] ?? null;
        if ($queryFirst) {
            $action = $fromQuery ?? $fromBody ?? $defaultGetAction;
        } else {
            $action = $method === 'POST'
                ? ($fromBody ?? '')
                : ($fromQuery ?? $defaultGetAction);
        }

        return new self($request, $body, (string) $action, $method);
    }

    /** body value first, then query string — the `isset($body[x]) ? … : $_GET[x]` idiom. */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }

        return $this->http->query($key, $default);
    }

    /** trim((string)$v) — the `s()` helper every legacy lib defines. */
    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);

        return is_array($v) ? $default : trim((string) $v);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }
}
