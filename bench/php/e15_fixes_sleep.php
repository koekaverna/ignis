<?php
require __DIR__ . '/../../php/ignis.php';
error_log("start");
$t = hrtime(true);
$fs = [];
for ($i = 0; $i < 10; $i++) { $fs[] = Ignis\async(static function () { usleep(200000); return 1; }); }
Ignis\all($fs);
error_log(sprintf("t1 usleep hook: 10 fibers x usleep(200ms) in %.0f ms", (hrtime(true) - $t) / 1e6));
$t = hrtime(true);
$fs = [];
for ($i = 0; $i < 3; $i++) { $fs[] = Ignis\async(static function () { sleep(1); return 1; }); }
Ignis\all($fs);
error_log(sprintf("t1b sleep hook: 3 fibers x sleep(1) in %.0f ms", (hrtime(true) - $t) / 1e6));
