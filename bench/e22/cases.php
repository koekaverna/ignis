<?php

/**
 * The multipart corpus, shared by both arms of the differential test (E22).
 *
 * Every case is raw bytes on purpose: `curl -F` normalises too much, and the cases that matter are
 * exactly the ones a normaliser would hide — LF-only line endings, a part with no name, a value that
 * looks like a boundary. The expectations are not written here; PHP's own `rfc1867` is the oracle,
 * reached through `php -S`, which has a `read_post` and therefore parses natively.
 *
 * @return array<string, array{body: string, type: string}>
 */

declare(strict_types=1);

return (static function (): array {
    $b = 'BND';
    $part = static function (string $disposition, string $value, string $extra = '') use ($b): string {
        return "--{$b}\r\nContent-Disposition: form-data; {$disposition}\r\n{$extra}\r\n{$value}\r\n";
    };
    $end = "--{$b}--\r\n";
    $ct = "multipart/form-data; boundary={$b}";

    $case = static fn (string $body, ?string $type = null): array => ['body' => $body, 'type' => $type ?? "multipart/form-data; boundary=BND"];

    $cases = [
        'single field'            => $case($part('name="a"', '1') . $end),
        'two fields'              => $case($part('name="a"', '1') . $part('name="b"', 'two') . $end),
        'empty value'             => $case($part('name="a"', '') . $end),
        'list name'               => $case($part('name="b[]"', 'x') . $part('name="b[]"', 'y') . $end),
        'nested name'             => $case($part('name="u[profile][name]"', 'ada') . $end),
        'numeric key'             => $case($part('name="n[3]"', 'three') . $part('name="n[]"', 'next') . $end),
        'duplicate name'          => $case($part('name="a"', 'first') . $part('name="a"', 'second') . $end),
        'empty name'              => $case($part('name=""', 'v') . $part('name="ok"', '1') . $end),
        'unicode name and value'  => $case($part('name="имя"', 'значение') . $end),
        'value with newlines'     => $case($part('name="t"', "line1\r\nline2") . $end),
        'value looks like boundary' => $case($part('name="t"', 'x--BND-not-a-boundary') . $end),
        'value with equals and amp' => $case($part('name="t"', 'a=1&b=2') . $end),
        'value with plus'         => $case($part('name="t"', 'a + b') . $end),
        'field carries a content-type' => $case($part('name="j"', '{"k":1}', "Content-Type: application/json\r\n")),
        'quoted boundary'         => $case($part('name="a"', '1') . $end, 'multipart/form-data; boundary="BND"'),
        'boundary then charset'   => $case($part('name="a"', '1') . $end, 'multipart/form-data; boundary=BND; charset=utf-8'),
        'charset then boundary'   => $case($part('name="a"', '1') . $end, 'multipart/form-data; charset=utf-8; boundary=BND'),
        'upper case header'       => $case($part('name="a"', '1') . $end, 'MULTIPART/FORM-DATA; BOUNDARY=BND'),
        'lf line endings'         => $case(str_replace("\r\n", "\n", $part('name="lf"', 'v') . $end)),
        'preamble before first part' => $case("ignored preamble\r\n" . $part('name="a"', '1') . $end),
        'epilogue after last part' => $case($part('name="a"', '1') . $end . "ignored junk\r\n"),
        'no trailing crlf on end' => $case($part('name="a"', '1') . "--{$b}--"),
        'single quoted name'      => $case("--{$b}\r\nContent-Disposition: form-data; name=a\r\n\r\n1\r\n" . $end),
        'name with a space'       => $case($part('name="a b"', '1') . $end),
        'field after a file part' => $case($part('name="f"; filename="x.txt"', 'FILE') . $part('name="after"', 'ok') . $end),
        'file with no filename value' => $case($part('name="f"; filename=""', '') . $part('name="ok"', '1') . $end),
        'part with no name at all' => $case($part('filename="x.txt"', 'X') . $part('name="ok"', '1') . $end),
        'empty body'              => $case($end),
    ];

    return $cases;
})();
