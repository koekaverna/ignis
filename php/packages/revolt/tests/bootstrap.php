<?php

/**
 * PHPUnit bootstrap for the Ignis Revolt driver tests (E15b).
 *
 * Composer only exposes revolt/event-loop's `src/` (its `autoload-dev` section is
 * not applied to an installed dependency), so the abstract suite
 * `Revolt\EventLoop\Driver\DriverTest` has to be mapped by hand here.
 *
 * No phpunit.xml.dist: reading an XML configuration needs ext-dom/ext-libxml,
 * which this minimal PHP build does not have. Run phpunit with
 * `--no-configuration --bootstrap php/packages/revolt/tests/bootstrap.php`.
 */
declare(strict_types=1);

$root = \dirname(__DIR__);

require $root . '/vendor/autoload.php';

\spl_autoload_register(static function (string $class) use ($root): void {
    // revolt/event-loop's own test namespace: Revolt\EventLoop\* -> test/*
    if (\str_starts_with($class, 'Revolt\\EventLoop\\')) {
        $path = $root . '/vendor/revolt/event-loop/test/'
            . \str_replace('\\', '/', \substr($class, \strlen('Revolt\\EventLoop\\'))) . '.php';
        if (\is_file($path)) {
            require $path;
        }
        return;
    }
    // Ignis\Revolt\Test\* -> php/packages/revolt/tests/*
    if (\str_starts_with($class, 'Ignis\\Revolt\\Test\\')) {
        $path = $root . '/test/'
            . \str_replace('\\', '/', \substr($class, \strlen('Ignis\\Revolt\\Test\\'))) . '.php';
        if (\is_file($path)) {
            require $path;
        }
    }
});
