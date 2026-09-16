<?php
// Retired (M3-2): this hardcoded app/public/index.php, which is wrong for any app not at that
// exact path. Superseded by the ignis/runtime composer package (V-40, README.md#symfony).
fwrite(STDERR, <<<'EOF'
php/symfony/worker.php is retired. Migrate to the ignis/runtime composer package:

composer config platform.php 8.5.10
composer config repositories.ignis '{"type":"path","url":"/opt/ignis/php","options":{"symlink":false}}'
composer require ignis/runtime:@dev
composer config extra.runtime.class 'Ignis\Symfony\IgnisRuntime'
composer dump-autoload

Then point ignis.toml's entry at your app's own public/index.php. See README.md#symfony (V-40).

EOF);
exit(2);
