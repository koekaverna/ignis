<?php
// Worker entry: the untouched symfony/skeleton public/index.php, with the two env knobs the embed SAPI lacks.
require __DIR__ . '/../ignis.php';
ini_set('memory_limit', '1G'); // pooled fibers × kernel: the default 128M is too small under load
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/app/public/index.php';
$_SERVER['APP_RUNTIME'] = Ignis\Symfony\IgnisRuntime::class;
$_SERVER['APP_ENV'] = getenv('APP_ENV') ?: 'prod';
$_SERVER['APP_DEBUG'] = '0';
require $_SERVER['SCRIPT_FILENAME'];
