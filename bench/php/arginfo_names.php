<?php

/**
 * Does the binary agree with the stubs about what the runtime's functions are called?
 *
 * The arg-info tables were keyed by arity until 2026-09-19 — one table per parameter count, shared by
 * whichever functions happened to take that many. So `ignis_respond_chunk` reflected as
 * `$id, $status`, `ignis_offload_submit` as `$id, $code, $message`, and nine functions called their
 * one parameter `$value`. Named arguments took the wrong names and `ReflectionFunction` reported
 * them. Two tables also carried the lender's `required_num_args`: `ignis_stream_bind` declared three
 * parameters while telling the engine four were required.
 *
 * Only the binary can answer this — under plain php-cli the `ignis_*` names belong to the test fake.
 *
 *   ignis bench/php/arginfo_names.php
 */

declare(strict_types=1);

$stubs = __DIR__ . '/../../php/packages/runtime/stubs/ignis.php';
$source = (string) file_get_contents($stubs);
preg_match_all('/^\s*function (ignis_\w+)\(([^)]*)\)/m', $source, $matches, PREG_SET_ORDER);

$expected = [];
foreach ($matches as [, $name, $parameters]) {
    preg_match_all('/\$(\w+)/', $parameters, $names);
    $expected[$name] = $names[1];
}

$wrong = [];
$checked = 0;
foreach ($expected as $name => $names) {
    if (!function_exists($name)) {
        continue;   // a build without --features temporal, or without the async ABI
    }
    $checked++;
    $reflected = array_map(
        static fn(ReflectionParameter $p): string => $p->getName(),
        (new ReflectionFunction($name))->getParameters(),
    );
    if ($reflected !== $names) {
        $wrong[$name] = ['binary' => $reflected, 'stubs' => $names];
    }
    $function = new ReflectionFunction($name);
    if ($function->getNumberOfRequiredParameters() > $function->getNumberOfParameters()) {
        $wrong[$name]['required'] = $function->getNumberOfRequiredParameters() . ' of ' . $function->getNumberOfParameters();
    }
}

printf(
    "arginfo_names checked=%d wrong=%d%s\n",
    $checked,
    \count($wrong),
    $wrong === [] ? '' : ' ' . json_encode($wrong, JSON_UNESCAPED_SLASHES),
);
exit($wrong === [] ? 0 : 1);
