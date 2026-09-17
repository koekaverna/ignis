<?php

declare(strict_types=1);

/**
 * Subjects for `Ignis\Offload\Router::proxyClass()`, in the root namespace because that is the only
 * place it can generate into: it emits `namespace Ignis\Offload\Proxy; class <Class> extends
 * \<Class>`, so a namespaced class name would not be a legal class declaration.
 *
 * Between them the methods cover every branch of `typeString()` and of the parameter renderer:
 * union, intersection, nullable class, `self`, `static`, builtin, untyped, `void`, a default value,
 * a by-reference parameter and a variadic — plus the three kinds `proxyClass()` must skip.
 */
if (!class_exists('IgnisOffloadProxySubject', false)) {
    class IgnisOffloadProxySubject
    {
        public const TAG = 'subject';

        public function __construct(public string $name = 'unnamed') {}

        public function plain(int $number, string $suffix = 'b'): string
        {
            return $number . $suffix;
        }

        public function nullableClass(?DateTimeInterface $when = null): ?DateTimeImmutable
        {
            return null;
        }

        public function union(int|string $value): int|string|null
        {
            return $value;
        }

        /**
         * @param  Countable&ArrayAccess<mixed, mixed> $bag
         * @return Countable&ArrayAccess<mixed, mixed>
         */
        public function intersection(Countable&ArrayAccess $bag): Countable&ArrayAccess
        {
            return $bag;
        }

        public function selfType(self $other): self
        {
            return $other;
        }

        public function staticType(): static
        {
            return $this;
        }

        /** @param array<int|string, mixed> $rows */
        public function byReference(array &$rows, int ...$rest): void {}

        /**
         * @param  mixed $anything
         * @return mixed
         */
        public function untyped($anything)
        {
            return $anything;
        }

        public static function aStaticMethod(): void {}

        final public function aFinalMethod(): void {}
    }
}
