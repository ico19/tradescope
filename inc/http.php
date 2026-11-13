<?php
/**
 * TradeScope - client HTTP con cache su file
 */
declare(strict_types=1);

function ts_http_get(string $url, array $headers = [], int $timeout = 20): array {
    $ua = 'Mozilla/5.0 (compatible; TradeScope/' . TS_VERSION . '; +https://tradescope.local)';
    $hdr = array_merge(['User-Agent: ' . $ua, 'Accept: application/json,text/plain,*/*'], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) return ['ok' => false, 'code' => 0, 'body' => '', 'error' => $err];
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => (string)$body, 'error' => $code >= 400 ? 'HTTP ' . $code : ''];
    }

    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $hdr), 'timeout' => $timeout, 'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'richiesta fallita'];
    $code = 200;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
    }
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => (string)$body, 'error' => $code >= 400 ? 'HTTP ' . $code : ''];
}

function ts_http_post_json(string $url, array $payload, array $headers = [], int $timeout = 120): array {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hdr  = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);

    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $hdr), 'content' => $json,
            'timeout' => $timeout, 'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 200;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        }
        return ['ok' => $body !== false && $code < 300, 'code' => $code, 'body' => (string)$body, 'error' => $code >= 400 ? 'HTTP ' . $code : ''];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => $hdr,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'code' => 0, 'body' => '', 'error' => $err];
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => (string)$body, 'error' => $code >= 400 ? 'HTTP ' . $code : ''];
}

/** GET con cache su filesystem */
function ts_http_cached(string $url, int $ttl, array $headers = []): array {
    $file = TS_STORAGE . '/cache/' . sha1($url) . '.json';
    if ($ttl > 0 && is_file($file) && (time() - filemtime($file)) < $ttl) {
        $cached = @file_get_contents($file);
        if ($cached !== false) return ['ok' => true, 'code' => 200, 'body' => $cached, 'error' => '', 'cached' => true];
    }
    $res = ts_http_get($url, $headers);
    if ($res['ok']) { @file_put_contents($file, $res['body'], LOCK_EX); }
    elseif (is_file($file)) {
        $cached = @file_get_contents($file);
        if ($cached !== false) return ['ok' => true, 'code' => 200, 'body' => $cached, 'error' => '', 'cached' => true, 'stale' => true];
    }
    return $res;
}

/** Pulizia cache scaduta */
function ts_cache_gc(int $maxAge = 3600): void {
    foreach (glob(TS_STORAGE . '/cache/*.json') ?: [] as $f) {
        if ((time() - filemtime($f)) > $maxAge) @unlink($f);
    }
}
