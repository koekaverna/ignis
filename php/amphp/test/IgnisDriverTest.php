<?php
/**
 * E15b / H22b — Revolt's abstract driver suite run against Ignis\Revolt\IgnisDriver.
 *
 * The abstract suite is revolt/event-loop's own `Revolt\EventLoop\Driver\DriverTest`
 * (vendor/revolt/event-loop/test/Driver/DriverTest.php, mapped by test/bootstrap.php).
 * A concrete subclass only has to supply a factory; see
 * vendor/revolt/event-loop/test/Driver/StreamSelectDriverTest.php for the shape.
 *
 * This test only runs inside the ignis binary: IgnisDriver's constructor throws
 * UnsupportedFeatureException when ignis_poll() is missing. Run it with bench/e15-revolt.sh.
 *
 * Data-provider compatibility: revolt's suite is written for PHPUnit 9 and declares its
 * providers with `@dataProvider` doc-comments plus a non-static provider method. PHPUnit 12
 * ignores doc-comment metadata and requires static providers, so four tests of the abstract
 * suite are collected without arguments and die with ArgumentCountError — on *any* driver,
 * including StreamSelectDriver under the stock CLI. `DataProviderCompat` below re-enables
 * three of them with attributes and a static copy of `provideRegistrationArgs()`, so the
 * driver is really exercised for defer/delay/repeat/onWritable/onReadable/onSignal.
 * `testNoMemoryLeak` cannot be re-enabled: its body calls `getTestResultObject()`, removed
 * in PHPUnit 10.
 */
declare(strict_types=1);

namespace Ignis\Revolt\Test;

use Ignis\Revolt\IgnisDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use Revolt\EventLoop\Driver\DriverTest;

trait DataProviderCompat
{
    /** Static copy of DriverTest::provideRegistrationArgs() (PHPUnit 12 requires static providers). */
    public static function registrationArgs(): iterable
    {
        yield 'defer' => ['defer', [static function (): void {
        }]];
        yield 'delay' => ['delay', [0.005, static function (): void {
        }]];
        yield 'repeat' => ['repeat', [0.005, static function (): void {
        }]];
        yield 'onWritable' => ['onWritable', [\STDOUT, static function (): void {
        }]];
        yield 'onReadable' => ['onReadable', [\STDIN, static function (): void {
        }]];
        yield 'onSignal' => ['onSignal', [\SIGUSR1, static function (): void {
        }]];
    }

    #[DataProvider('registrationArgs')]
    public function testDisableWithConsecutiveCancel(string $type, array $args): void
    {
        parent::testDisableWithConsecutiveCancel($type, $args);
    }

    #[DataProvider('registrationArgs')]
    public function testCallbackReferenceInfo(string $type, array $args): void
    {
        parent::testCallbackReferenceInfo($type, $args);
    }

    #[DataProvider('registrationArgs')]
    public function testCallbackRegistrationAndCancellationInfo(string $type, array $args): void
    {
        parent::testCallbackRegistrationAndCancellationInfo($type, $args);
    }
}

final class IgnisDriverTest extends DriverTest
{
    use DataProviderCompat;

    public function getFactory(): callable
    {
        return static fn (): IgnisDriver => new IgnisDriver();
    }

    public function testHandle(): void
    {
        self::assertNull($this->loop->getHandle());
    }
}
