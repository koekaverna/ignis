<?php

// E6' / H25: ssl:// through the hook. Env: PORTS="8441 8442 8443", CAFILE (the servers' self-signed cert).
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
$ports = array_map('intval', explode(' ', getenv('PORTS') ?: '8441 8442 8443'));
$cafile = getenv('CAFILE') ?: '/tmp/e6-ssl/cert.pem';
$hook = getenv('IGNIS_NO_STREAM_HOOK') ? 'off' : 'on';

Ignis\async(static function () use ($ports, $cafile, $hook): void {
    // 1. Three concurrent https fetches, each server sleeps 200 ms: ≈ 200 ms with the hook, ≈ 600 ms without.
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $t = hrtime(true);
    $rs = Ignis\all(array_map(static fn(int $p) => Ignis\async(static fn() => file_get_contents("https://127.0.0.1:$p/", false, $ctx)), $ports));
    printf("hook %s: 3 concurrent https fetches (200 ms each) in %.0f ms; bodies: %s\n", $hook, (hrtime(true) - $t) / 1e6, json_encode(array_map('trim', $rs)));

    // 2. Verification follows the context: default (verify on, self-signed) must fail; cafile makes it pass; wrong peer_name fails; verify_peer_name=false passes.
    $cases = [
        'default (verify_peer on)' => [],
        'cafile' => ['cafile' => $cafile, 'peer_name' => 'localhost'],
        'cafile + wrong peer_name' => ['cafile' => $cafile, 'peer_name' => 'example.invalid'],
        'cafile + verify_peer_name=false' => ['cafile' => $cafile, 'peer_name' => 'example.invalid', 'verify_peer_name' => false],
        'allow_self_signed' => ['allow_self_signed' => true, 'peer_name' => 'localhost'],
    ];
    foreach ($cases as $label => $opts) {
        $r = @file_get_contents("https://127.0.0.1:{$ports[0]}/", false, stream_context_create(['ssl' => $opts]));
        $err = error_get_last()['message'] ?? '';
        printf("verify [%s]: %s%s\n", $label, $r === false ? 'FAIL' : 'ok', $r === false ? ' — ' . preg_replace('/\s+/', ' ', substr($err, 0, 400)) : '');
        error_clear_last();
    }

    // 3. STARTTLS: tcp:// connect, then stream_socket_enable_crypto() upgrades the hooked stream in place.
    $c = stream_socket_client("tcp://127.0.0.1:{$ports[1]}", $e, $s, 5, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => false, 'peer_name' => 'localhost']]));
    $meta = stream_get_meta_data($c)['stream_type'];
    $ok = stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    fwrite($c, "GET / HTTP/1.0\r\nHost: localhost\r\n\r\n");
    $resp = stream_get_contents($c);
    printf("starttls on a %s stream: enable_crypto=%s, response=%s\n", $meta, var_export($ok, true), json_encode(trim(substr(rtrim($resp), (int) strrpos(rtrim($resp), "\n")))));
})->await();
