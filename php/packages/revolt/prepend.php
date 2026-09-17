<?php
// The embed SAPI does not define the CLI stream constants; Revolt/AMPHP examples expect them.
if (!defined('STDIN'))  { define('STDIN',  fopen('php://stdin',  'r')); }
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }
