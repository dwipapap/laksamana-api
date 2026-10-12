<?php

declare(strict_types=1);

/*
 * Denah forwarder for the PUBLIC domain.
 *
 * Guests open https://laksamanamuda.id/d/<token>; the API that renders the
 * map lives on api.laksamanamuda.id. This one file relays the request so the
 * address bar stays on the customer domain. It is NOT part of the API app:
 * drop it (with the .htaccess next to it) at public_html/d/ on the customer
 * site's hosting, then set DENAH_PUBLIC_BASE=https://laksamanamuda.id on the
 * API so POST /api/v1/automation/denah-link hands out this domain.
 *
 * The token is the only credential and it expires by itself; nothing here is
 * stored and no PII passes through. Errors are passed through unchanged
 * (403 invalid / 410 expired), so the guest still sees the API's message.
 */

/** Where the map is actually rendered. */
const DENAH_API_BASE = 'https://api.laksamanamuda.id';

/** How long to wait for the API before giving up. */
const DENAH_TIMEOUT = 15;

const DENAH_FALLBACK_HTML = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
    .'<meta name="viewport" content="width=device-width, initial-scale=1"><title>Denah</title></head>'
    .'<body style="font-family:system-ui,sans-serif;padding:24px">'
    .'<p>Denah sedang tidak bisa dibuka. Coba lagi sebentar, atau minta link baru ke asisten reservasi.</p>'
    .'</body></html>';

/** Accept only characters a base64url token may contain. */
function denah_token(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (preg_match('~^/d/([A-Za-z0-9._-]+)~', $path, $m)) {
        return $m[1];
    }
    $q = isset($_GET['t']) ? (string) $_GET['t'] : '';

    return preg_replace('~[^A-Za-z0-9._-]~', '', $q) ?? '';
}

$token = denah_token();
if ($token === '') {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo DENAH_FALLBACK_HTML;
    exit;
}

$url = DENAH_API_BASE.'/d/'.rawurlencode($token);
$status = 502;
$contentType = 'text/html; charset=utf-8';
$body = false;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => DENAH_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ]);
    $body = curl_exec($ch);
    if ($body !== false) {
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if (is_string($ct) && $ct !== '') {
            $contentType = $ct;
        }
    }
    curl_close($ch);
} else {
    $ctx = stream_context_create(['http' => ['timeout' => DENAH_TIMEOUT, 'ignore_errors' => true, 'header' => "Accept: text/html\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body !== false) {
        $status = 200;
        foreach ((array) ($http_response_header ?? []) as $h) {
            if (stripos($h, 'Content-Type:') === 0) {
                $contentType = trim(substr($h, strlen('Content-Type:')));
            }
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $mm)) {
                $status = (int) $mm[1];
            }
        }
    }
}

if ($body === false) {
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo DENAH_FALLBACK_HTML;
    exit;
}

http_response_code($status > 0 ? $status : 200);
header('Content-Type: '.$contentType);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo $body;
