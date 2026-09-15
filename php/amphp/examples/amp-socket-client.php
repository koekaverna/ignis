<?php
// amphp/socket v2: connect to the Ignis hello server, send a request, read the reply (onReadable/onWritable path).
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use function Amp\Socket\connect;

$socket = connect('tcp://127.0.0.1:8080');
$socket->write("GET /?amp=1 HTTP/1.0\r\nHost: localhost\r\n\r\n");
$buf = '';
while (null !== ($chunk = $socket->read())) {
    $buf .= $chunk;
}
$socket->close();
$body = substr($buf, strpos($buf, "\r\n\r\n") + 4);
printf("amp-socket: status=%s body=%s\n", explode(' ', $buf, 3)[1] ?? '?', json_encode($body));
