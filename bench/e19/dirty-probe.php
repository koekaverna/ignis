<?php
/**
 * E19-R2: how big is a real framework's boot heap, and how many of its pages does one request write?
 *
 * Instrument, not mechanism: soft-dirty bits. Write "4" to /proc/self/clear_refs to clear the
 * kernel's per-page dirty marks, run one request, then read /proc/self/pagemap and count the pages
 * of the process's anonymous memory that came back marked. No barrier, no mprotect, so the
 * measurement does not disturb what it measures. (clear_refs is process-wide, which is exactly why
 * it cannot BE the mechanism — research 34 — but this probe is single-threaded.)
 *
 * Usage: /opt/php85-zts/bin/php bench/e19/dirty-probe.php /path/to/symfony [requests] [uri]
 */
declare(strict_types=1);

$app = $argv[1] ?? '/home/koe/projects/symfony-ignis';
$n = (int) ($argv[2] ?? 12);
$uri = $argv[3] ?? '/';
const PAGE = 4096;

/** Anonymous, writable, private regions — where the Zend MM heap and everything else PHP allocates live. */
function anonRegions(): array {
    $out = [];
    foreach (file('/proc/self/maps') as $line) {
        if (!preg_match('/^([0-9a-f]+)-([0-9a-f]+) (\S{4}) \S+ \S+ \S+\s*(.*)$/', trim($line), $m)) continue;
        [$all, $lo, $hi, $perm, $path] = $m;
        if ($perm[1] !== 'w' || $perm[3] !== 'p') continue;          // writable private only
        if ($path !== '' && !str_starts_with($path, '[heap]')) continue;  // anonymous or the brk heap
        $out[] = [hexdec($lo), hexdec($hi)];
    }
    return $out;
}

function clearSoftDirty(): void { file_put_contents('/proc/self/clear_refs', "4\n"); }

/** @return array{0:int,1:int} [dirty pages, pages examined] */
function countDirty(array $regions) {
    $pm = fopen('/proc/self/pagemap', 'rb');
    $dirty = 0; $seen = 0;
    foreach ($regions as [$lo, $hi]) {
        $pages = intdiv($hi - $lo, PAGE);
        if ($pages <= 0) continue;
        fseek($pm, intdiv($lo, PAGE) * 8);
        $buf = fread($pm, $pages * 8);
        if ($buf === false) continue;
        $seen += intdiv(strlen($buf), 8);
        foreach (unpack('Q*', substr($buf, 0, intdiv(strlen($buf), 8) * 8)) as $e) {
            if ($e & (1 << 55)) $dirty++;
        }
    }
    fclose($pm);
    return [$dirty, $seen];
}

require $app . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($app . '/.env');
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'prod';

$rss0 = (int) (preg_match('/VmRSS:\s+(\d+)/', file_get_contents('/proc/self/status'), $m) ? $m[1] : 0);
$mem0 = memory_get_usage(true);

$kernel = new App\Kernel('prod', false);
$kernel->boot();
$req = Symfony\Component\HttpFoundation\Request::create($uri);
$kernel->handle($req);   // one warm-up request: lazy services, compiled container, routes

$mem1 = memory_get_usage(true);
$rss1 = (int) (preg_match('/VmRSS:\s+(\d+)/', file_get_contents('/proc/self/status'), $m) ? $m[1] : 0);
printf("boot heap (memory_get_usage(true)): %.1f MiB -> %.1f MiB after boot + first request\n", $mem0 / 1048576, $mem1 / 1048576);
printf("process RSS: %.1f MiB -> %.1f MiB\n", $rss0 / 1024, $rss1 / 1024);

$regions = anonRegions();
$tot = array_sum(array_map(fn($r) => intdiv($r[1] - $r[0], PAGE), $regions));
printf("anonymous writable pages in the process: %d (%.1f MiB) across %d regions\n\n", $tot, $tot * PAGE / 1048576, count($regions));

printf("%-8s %-14s %-14s %s\n", 'request', 'dirty pages', 'dirty MiB', 'ns');
for ($i = 1; $i <= $n; $i++) {
    clearSoftDirty();
    $t = hrtime(true);
    $kernel->handle(Symfony\Component\HttpFoundation\Request::create($uri));
    $ns = hrtime(true) - $t;
    [$d, $seen] = countDirty($regions);
    printf("%-8d %-14d %-14.2f %d\n", $i, $d, $d * PAGE / 1048576, $ns);
}
