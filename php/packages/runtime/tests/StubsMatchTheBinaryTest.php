<?php

declare(strict_types=1);

namespace Ignis\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The `ignis_*` functions are defined in Rust, so every analyser's view of that boundary is
 * `stubs/ignis.php` and nothing keeps it honest by itself. It had drifted by ten functions before
 * this test existed, which showed up as "unknown function" findings nobody could act on.
 */
final class StubsMatchTheBinaryTest extends TestCase
{
    /** A scan that finds nothing would pass silently, which is the one way this test could rot. */
    public function testEveryIgnisFunctionCalledInThisRepositoryHasAStub(): void
    {
        $declared = $this->declaredStubs();
        $callSites = $this->callSites();
        $missing = [];

        self::assertGreaterThan(20, count($declared), 'the stub file was not read');
        self::assertGreaterThan(10, count($callSites), 'no call sites found -- the scan is broken');

        foreach ($callSites as $file => $functions) {
            foreach ($functions as $function) {
                if (!in_array($function, $declared, true)) {
                    $missing[$function][] = $file;
                }
            }
        }

        self::assertSame([], $missing, 'called but not declared in stubs/ignis.php: ' . implode(', ', array_keys($missing)));
    }

    /** @return list<string> */
    private function declaredStubs(): array
    {
        $source = file_get_contents(dirname(__DIR__) . '/stubs/ignis.php');
        preg_match_all('/^\s*function (ignis_\w+)\(/m', (string) $source, $matches);

        return $matches[1];
    }

    /** @return array<string, list<string>> */
    private function callSites(): array
    {
        $root = dirname(__DIR__, 4);
        $found = [];

        foreach ([$root . '/php/packages', $root . '/examples'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/vendor/')) {
                    continue;
                }
                if (str_ends_with($file->getPathname(), 'stubs/ignis.php')) {
                    continue;
                }
                preg_match_all('/\b(ignis_\w+)\s*\(/', (string) file_get_contents($file->getPathname()), $matches);
                $names = array_values(array_unique($matches[1]));
                if ($names !== []) {
                    $found[substr($file->getPathname(), strlen($root) + 1)] = $names;
                }
            }
        }

        return $found;
    }
}
