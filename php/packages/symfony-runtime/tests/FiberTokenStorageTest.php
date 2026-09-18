<?php

declare(strict_types=1);

namespace Ignis\Tests\Symfony;

use Ignis\Scope;
use Ignis\Symfony\FiberTokenStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;

/**
 * ADR-0011 / V-68: the authenticated token is fiber-scoped storage, same as `FiberRequestStack`,
 * for the same reason -- a shared `TokenStorage` service leaks whoever authenticated last into a
 * request that parked on I/O and resumed on the same thread.
 */
#[CoversClass(FiberTokenStorage::class)]
final class FiberTokenStorageTest extends TestCase
{
    protected function setUp(): void
    {
        Scope::clear();
    }

    protected function tearDown(): void
    {
        Scope::clear();
    }

    public function testAnEmptyScopeHasNoToken(): void
    {
        self::assertNull((new FiberTokenStorage())->getToken());
    }

    public function testSetTokenIsWhatGetTokenReturns(): void
    {
        $storage = new FiberTokenStorage();
        $token = new NullToken();

        $storage->setToken($token);

        self::assertSame($token, $storage->getToken());
    }

    public function testResetClearsTheToken(): void
    {
        $storage = new FiberTokenStorage();
        $storage->setToken(new NullToken());

        $storage->reset();

        self::assertNull($storage->getToken());
    }

    /** The scope slot is a generic bag, so anything other than a token is a bug, not a `mixed` to trust. */
    public function testAScopeValueThatIsNotATokenIsRejected(): void
    {
        Scope::set('security.token', 'not-a-token');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('holds something other than a security token');
        (new FiberTokenStorage())->getToken();
    }
}
