<?php

/**
 * CGI-era helpers a classic script expects and the embed SAPI does not provide.
 * Global namespace on purpose — these are the names PHP itself would define under mod_php,
 * and each is guarded so a real one always wins.
 */

declare(strict_types=1);

if (!function_exists('getallheaders')) {
    /**
     * Request headers in canonical case, from the HTTP_* entries the runtime put in $_SERVER.
     * @return array<string, mixed>
     */
    function getallheaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headers[ucwords(strtolower(str_replace('_', '-', substr($name, 5))), '-')] = $value;
            }
        }
        return $headers;
    }
    /** @return array<string, mixed> */
    function apache_request_headers(): array
    {
        return getallheaders();
    }
    /** @return array<string, mixed> */
    function apache_response_headers(): array
    {
        return \Ignis\Classic\Runner::headerMap();
    }
}
