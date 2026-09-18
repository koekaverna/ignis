<?php

// A6: read-ahead on a hooked TLS stream must be visible to stream_select().
//
// rustls decrypts a whole TLS record at a time. Once a record is consumed from the socket the raw
// fd shows nothing readable, so a select() on the fd parks even though decrypted plaintext is
// waiting. This checks the observable consequence: after reading ONE byte of a response, a
// stream_select() with a zero timeout must report the stream ready immediately.
//
// Env: PORT (an e6_ssl_server.php instance), CAFILE.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

$port = (int) (getenv('PORT') ?: 8441);
$hook = getenv('IGNIS_NO_STREAM_HOOK') ? 'off' : 'on';

Ignis\async(static function () use ($port, $hook): void {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $c = stream_socket_client("ssl://127.0.0.1:$port", $e, $s, 5, STREAM_CLIENT_CONNECT, $ctx);
    if ($c === false) {
        printf("a6 hook=%s connect_failed err=%s\n", $hook, $s);
        return;
    }
    fwrite($c, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

    // Drain everything php_stream has buffered, so the next readiness answer can only come from
    // plaintext still held on the tokio side. One op_read asks for 8192 bytes while a TLS record
    // carries up to 16 KB, so with a large body rustls keeps the remainder.
    $first = fread($c, 8192);
    while (strlen($first) < 8192) {
        $more = fread($c, 8192 - strlen($first));
        if ($more === false || $more === '') {
            break;
        }
        $first .= $more;
    }

    $r = [$c];
    $w = null;
    $x = null;
    $t0 = hrtime(true);
    $ready = stream_select($r, $w, $x, 0, 0);   // zero timeout: must answer from buffered data
    $selUs = (hrtime(true) - $t0) / 1e3;

    // NOT stream_get_contents(): on a connection the peer holds open it would block for ever on
    // EOF and mask what select() actually answered.
    $rest = $ready === 1 ? (string) fread($c, 4096) : '';
    fclose($c);
    printf(
        "a6 hook=%s first_bytes=%d select_ready=%d select_us=%.1f rest_bytes=%d verdict=%s\n",
        $hook,
        strlen($first),
        (int) $ready,
        $selUs,
        strlen($rest),
        ($ready === 1 && strlen($rest) > 0) ? 'PASS' : 'FAIL',
    );
});
Ignis\Loop::run();
