<?php

/** Posts every corpus case to both servers and diffs what they made of it. */

declare(strict_types=1);

$oracle = 'http://127.0.0.1:' . (getenv('ORACLE_PORT') ?: '8197') . '/dump.php';
$ours   = 'http://127.0.0.1:' . (getenv('IGNIS_PORT') ?: '8198') . '/';

function ask(string $url, string $body, string $type): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $type, 'Expect:'],
    ]);
    $raw = curl_exec($ch);

    return json_decode((string) $raw, true) ?? ['post' => '<unparseable: ' . substr((string) $raw, 0, 60) . '>'];
}

$cases = require __DIR__ . '/cases.php';
$bad = 0;
foreach ($cases as $name => $c) {
    $a = ask($oracle, $c['body'], $c['type'])['post'] ?? null;
    $b = ask($ours, $c['body'], $c['type'])['post'] ?? null;
    if ($a === $b) {
        printf("  ok      %s\n", $name);
        continue;
    }
    ++$bad;
    printf("  DIFFER  %s\n            php: %s\n          ignis: %s\n", $name,
        json_encode($a, JSON_UNESCAPED_UNICODE), json_encode($b, JSON_UNESCAPED_UNICODE));
}
printf("\n%d of %d cases differ from PHP's own parser\n", $bad, count($cases));
exit($bad === 0 ? 0 : 1);
