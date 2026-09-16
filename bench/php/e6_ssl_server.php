<?php
// Stock-PHP TLS server for the E6' bench: accepts one connection at a time, reads the request,
// sleeps DELAY_MS, answers a small HTTP response. Run with /opt/php85-zts/bin/php (blocking, in its own process).
// Env: PORT, DELAY_MS, CERT (PEM with key+cert), BODY_KB (default 0 = the original tiny body;
// A6 uses a large one so a single TLS record leaves plaintext buffered inside rustls).
declare(strict_types=1);
$port = (int) (getenv('PORT') ?: 8441);
$delay = (int) (getenv('DELAY_MS') ?: 200);
$ctx = stream_context_create(['ssl' => ['local_cert' => getenv('CERT') ?: '/tmp/e6-ssl/cert.pem', 'verify_peer' => false, 'allow_self_signed' => true]]);
$srv = stream_socket_server("ssl://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($srv === false) { fwrite(STDERR, "server: $errstr\n"); exit(1); }
fwrite(STDERR, "ready $port\n");
$idleSince = time();
while (true) {
    $c = @stream_socket_accept($srv, 5);
    if ($c === false) { // a rejected handshake (client verify failure) also lands here: keep serving
        if (time() - $idleSince > 40) { break; }
        continue;
    }
    $idleSince = time();
    stream_set_timeout($c, 5);
    $req = '';
    while (!str_contains($req, "\r\n\r\n") && ($line = fgets($c)) !== false) { $req .= $line; }
    usleep($delay * 1000);
    $bodyKb = (int) (getenv('BODY_KB') ?: 0);
    $body = $bodyKb > 0 ? str_repeat('x', $bodyKb * 1024) : "hello over tls from $port\n";
    fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body");
    fclose($c);
}
