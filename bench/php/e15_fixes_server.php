<?php
require __DIR__ . '/../../php/ignis.php';
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$name = stream_socket_get_name($srv, false);
error_log("server on $name");
$wrote = false;
$f = Ignis\async(static function () use ($name, &$wrote) { $c = stream_socket_client("tcp://$name", $e, $s, 1); error_log("client connected type=" . stream_get_meta_data($c)['stream_type'] . ""); fwrite($c, "ping"); $wrote = true; error_log("client wrote"); return fread($c, 4); });
Ignis\Loop::runUntil(static function () use (&$wrote) { return $wrote; });
error_log("accepting");
$conn = stream_socket_accept($srv, 1);
error_log("accepted");
$got = fread($conn, 4); error_log("server got '$got'"); fwrite($conn, "pong"); fclose($conn);
error_log("client got '" . $f->await() . "'");
