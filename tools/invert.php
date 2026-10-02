<?php

declare(strict_types=1);

/**
 * Dictionary maintenance helper (offline, no dependencies).
 *
 * Creates the reverse table of an existing data file:
 *
 *   php tools/invert.php data/es_en.json data/en_es.json
 *
 * Single word "phrases" are stored as word entries in the generated file, so
 * the reverse table can be used for token level lookups too.
 */

$source = $argv[1] ?? (__DIR__ . '/../data/es_en.json');
$target = $argv[2] ?? null;

if ($target === null) {
    fwrite(STDERR, "usage: php tools/invert.php <source.json> <target.json>\n");
    exit(1);
}

$raw = @file_get_contents($source);
if ($raw === false) {
    fwrite(STDERR, 'cannot read ' . $source . PHP_EOL);
    exit(1);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, 'invalid JSON: ' . json_last_error_msg() . PHP_EOL);
    exit(1);
}

$reversePhrases = [];
$reverseWords = [];
$ambiguous = [];

$invert = static function (array $rows, array &$phrases, array &$words, array &$ambiguous): void {
    foreach ($rows as $key => $value) {
        $key = trim((string) $key);
        $value = trim((string) $value);

        if ($key === '' || $value === '') {
            continue;
        }

        if (isset($phrases[$value]) || isset($words[$value])) {
            $ambiguous[$value] = ($ambiguous[$value] ?? 0) + 1;
            continue;
        }

        if (strpos($key, ' ') === false) {
            $words[$value] = $key;
        } else {
            $phrases[$value] = $key;
        }
    }
};

$invert(isset($data['words']) && is_array($data['words']) ? $data['words'] : [], $reversePhrases, $reverseWords, $ambiguous);
$invert(isset($data['phrases']) && is_array($data['phrases']) ? $data['phrases'] : [], $reversePhrases, $reverseWords, $ambiguous);

$output = [
    'from' => (string) ($data['to'] ?? ''),
    'to' => (string) ($data['from'] ?? ''),
    'name' => ($data['to'] ?? '') . ' => ' . ($data['from'] ?? ''),
    'version' => 1,
    'meta' => [
        'generated_by' => 'tools/invert.php',
        'generated_from' => basename($source),
        'description' => 'Reverse table generated from ' . basename($source) . '.',
    ],
    'keep' => $data['keep'] ?? [],
    'phrases' => $reversePhrases,
    'words' => $reverseWords,
];

$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, 'cannot encode output' . PHP_EOL);
    exit(1);
}

if (file_put_contents($target, $json . PHP_EOL) === false) {
    fwrite(STDERR, 'cannot write ' . $target . PHP_EOL);
    exit(1);
}

printf(
    "written %s: %d phrases, %d words, %d ambiguous values skipped%s",
    $target,
    count($reversePhrases),
    count($reverseWords),
    count($ambiguous),
    PHP_EOL
);