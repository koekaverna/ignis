<?php

// H1 smoke: a Rust-implemented internal function is callable from PHP.
$id = ignis_submit_sleep(1);
$events = ignis_poll(1000);
printf("hello from rust: %d\n", isset($events[$id]) ? 42 : -1);
printf("zts=%d fibers=%d php=%s\n", PHP_ZTS, (int) class_exists('Fiber'), PHP_VERSION);
