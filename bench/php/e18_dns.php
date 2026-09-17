<?php

// E18-B / H34 control: N concurrent libpq connects (pg_connect, PGSQL_CONNECT_FORCE_NEW) to a
// hostname. H34's real target is `slow.ignis.test`, answered slowly by bench/e18/resolver.py — but
// getaddrinfo(3) only sees that stub once the runtime resolver is pointed at it, and glibc reads
// /etc/resolv.conf for that, which we are not allowed to edit. H34 needs a knob the implementation
// doesn't have yet: IGNIS_RESOLVER=127.0.0.1:5353 (E18-I). Until then this bench is honest about
// what it is actually measuring: `localhost`, resolved instantly by glibc's normal (files/dns)
// path — the "not slow" control. note=stub_not_wired says so on every line.
//
// No TCP postgres listens on this box (PostgreSQL is reachable only over the unix socket
// /tmp/ignis-pgsock, see bench/e18.sh) so these connects resolve fast and then fail fast
// (connection refused) rather than succeed — `ok` counts connections, and 0 here is expected and
// does not indicate a bug in this bench.
//
// Usage: ignis bench/php/e18_dns.php <host> [n]   (env fallback: DNS_HOST, N; default n=50)
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

$host = $argv[1] ?? getenv('DNS_HOST') ?: 'localhost';
$n = (int) ($argv[2] ?? getenv('N') ?: 50);

$ok = 0;

$main = Ignis\async(static function () use ($host, $n, &$ok): void {
    $t0 = hrtime(true);
    $fs = [];
    for ($i = 0; $i < $n; $i++) {
        $fs[] = Ignis\async(static function () use ($host, &$ok): void {
            $conn = @pg_connect(
                "host=$host port=5432 dbname=ignis user=ignis password=ignis connect_timeout=1",
                PGSQL_CONNECT_FORCE_NEW,
            );
            if ($conn !== false) {
                $ok++;
                pg_close($conn);
            }
        });
    }
    Ignis\all($fs);
    $wall = (hrtime(true) - $t0) / 1e6;
    printf("e18: dns_50 wall_ms=%.0f ok=%d note=stub_not_wired\n", $wall, $ok);
});
Ignis\Loop::run();
