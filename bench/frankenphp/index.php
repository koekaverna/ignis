<?php
// FrankenPHP worker-mode hello world (baseline for E4). Same libphp 8.5.10 ZTS build as Ignis.
ignore_user_abort(true);
$handler = static function (): void {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Hello, World!\n";
};
while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
