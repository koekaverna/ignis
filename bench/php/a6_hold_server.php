<?php

// TLS server that answers once and then KEEPS THE CONNECTION OPEN, so the client sees no EOF.
$port = (int) (getenv('PORT') ?: 8460);
$ctx = stream_context_create(['ssl' => ['local_cert' => getenv('CERT'), 'verify_peer' => false, 'allow_self_signed' => true]]);
$srv = stream_socket_server("ssl://127.0.0.1:$port", $e, $s, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($srv === false) {
    fwrite(STDERR, "srv: $s\n");
    exit(1);
}
$c = stream_socket_accept($srv, 10);
if ($c === false) {
    exit(1);
}
stream_set_timeout($c, 5);
$req = '';
while (!str_contains($req, "\r\n\r\n") && ($l = fgets($c)) !== false) {
    $req .= $l;
}
$body = str_repeat('y', 10 * 1024);   // one TLS record's worth of plaintext
fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
sleep(20);  // hold it open: no EOF for the client
