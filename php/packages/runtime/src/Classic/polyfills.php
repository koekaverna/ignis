<?php

/**
 * CGI-era helpers a classic script expects and the embed SAPI does not provide.
 * Global namespace on purpose — these are the names PHP itself would define under mod_php,
 * and each is guarded so a real one always wins.
 */

declare(strict_types=1);

if (!function_exists('getallheaders')) {
    /** Request headers in canonical case, from the HTTP_* entries the runtime put in $_SERVER. */
    function getallheaders(): array
    {
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $h[ucwords(strtolower(str_replace('_', '-', substr($k, 5))), '-')] = $v;
            }
        }
        return $h;
    }
    function apache_request_headers(): array
    {
        return getallheaders();
    }
    function apache_response_headers(): array
    {
        return \Ignis\Classic\Runner::headerMap();
    }
}
