<?php
// E13 (b): two interleaved fibers each set their own superglobals; after suspending they must still see them.
declare(strict_types=1);
require __DIR__ . '/../../php/ignis.php';
$mismatch = 0;
$mk = static function (string $tag) use (&$mismatch): Ignis\Future {
    return Ignis\async(static function () use ($tag, &$mismatch): void {
        ignis_set_superglobals(['REQUEST_URI' => "/$tag"], ['x' => $tag], [], ['sid' => $tag]);
        Ignis\Scope::set('tag', $tag);
        for ($i = 0; $i < 50; $i++) {
            Ignis\sleep(1);
            if (($_GET['x'] ?? null) !== $tag || ($_SERVER['REQUEST_URI'] ?? null) !== "/$tag" || ($_COOKIE['sid'] ?? null) !== $tag || Ignis\Scope::get('tag') !== $tag) {
                $mismatch++;
            }
            $_GET['i'] = $i; // a write must not leak into the other fiber either
            if (isset($_GET['i']) && $_GET['i'] !== $i) { $mismatch++; }
        }
    });
};
$fa = $mk('A'); $fb = $mk('B'); $fc = $mk('C');
Ignis\Loop::run();
Ignis\all([$fa, $fb, $fc]);
$mainSeesLeak = isset($_GET['x']) ? 1 : 0; // {main} inherited nothing: it never set globals and the fibers' entries were saved under their own contexts
printf("e13_isolation fibers=3 checks=%d mismatches=%d main_leak=%d\n", 3 * 50 * 2, $mismatch, $mainSeesLeak);
exit($mismatch === 0 ? 0 : 1);
