<?php

declare(strict_types=1);

/**
 * Merges the article glossary into data/*.json.
 *
 * The glossary files under tools/glossary/ are keyed BY NODE INDEX, not by
 * hand written Spanish: the Spanish source is read back from the fixture, so a
 * key can never drift from the page (accents, typos and all).
 *
 *   php tools/glossary/build.php                  # every language present
 *   php tools/glossary/build.php en de            # only these targets
 *   php tools/glossary/build.php --check          # dry run, no writes
 *
 * Values written by this script are phrases (whole nodes). They go to
 * es_<target>.json when the source language is es, and to en_<target>.json
 * when the glossary is a mirror of the English text.
 */

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use Medel\Translator\Support\Text;
use Medel\Translator\Translator;

$fixture = 'software-2026';
$page = (string) file_get_contents($root . '/tests/fixtures/' . $fixture . '.html');

$brands = ['Tokify', 'Stripe', 'PayPal', 'ElevenLabs', 'HeyGen', 'D-ID', 'Whisper.cpp', 'Pexels',
    'OpenAI', 'Anthropic', 'YouTube', 'TikTok', 'Instagram', 'Reddit', 'Amazon S3', 'WYSIWYG',
    'PHP', 'SQL', 'SaaS', 'API', 'REST', 'PWA', 'S3', 'SEO', 'Medel Platforms'];

$collector = new Translator([
    'data_path' => $root . '/data',
    'cache' => false,
    'keep' => $brands,
    'empty_paragraphs' => 'br',
]);

$nodes = [];

$collector->htmlProcessor()->translateNodes($page, static function (string $node) use (&$nodes): string {
    $text = Text::trim($node);

    if ($text !== '' && !Text::isPathLike($text)) {
        $nodes[] = $text;
    }

    return $node;
});

/* ------------------------------------------------------------- 1. GLOSSARY */

$glossary = [];

foreach (glob(__DIR__ . '/part-*.php') as $file) {
    $part = require $file;

    foreach ($part as $index => $row) {
        if (isset($glossary[$index])) {
            fwrite(STDERR, sprintf("indice %d duplicado en %s\n", $index, basename($file)));
            exit(1);
        }

        $glossary[$index] = $row;
    }
}

/* ------------------------------------------------------------ 2. ARGUMENTS */

$argv = $_SERVER['argv'];
$check = in_array('--check', $argv, true);
$targets = array_values(array_filter($argv, static fn ($a) => $a !== '--check' && preg_match('/^[a-z]{2}$/', $a) === 1));

$all = ['en', 'de', 'fr', 'it', 'pt', 'ja', 'ar', 'ru'];
$targets = $targets === [] ? $all : $targets;

foreach ($targets as $code) {
    if (!in_array($code, $all, true)) {
        fwrite(STDERR, "idioma desconocido: {$code}\n");
        exit(1);
    }
}

/* --------------------------------------------------------------- 3. TABLES */

$plan = [];

foreach ($glossary as $index => $row) {
    if (!isset($nodes[$index])) {
        fwrite(STDERR, sprintf("indice %d fuera de rango (el fixture tiene %d nodos)\n", $index, count($nodes)));
        exit(1);
    }

    $spanish = $nodes[$index];

    foreach ($targets as $code) {
        $value = $row[$code] ?? null;

        if ($value === null || $value === '') {
            continue;
        }

        // Direct leg: Spanish -> target.
        $plan[$code === 'en' ? 'es_en' : 'es_de'][$spanish] = $value;
    }
}

// Mirrors: the English text of the same node is the key of en_<target>.
foreach ($glossary as $index => $row) {
    $english = $row['en'] ?? null;

    if ($english === null || $english === '') {
        continue;
    }

    foreach ($targets as $code) {
        if ($code === 'en') {
            continue;
        }

        $value = $row[$code] ?? null;

        if ($value === null || $value === '') {
            continue;
        }

        $plan['en_' . $code][$english] = $value;
    }
}

/* ---------------------------------------------------------------- 4. WRITE */

$load = static function (string $name) use ($root): array {
    $path = $root . '/data/' . $name . '.json';

    if (!is_file($path)) {
        fwrite(STDERR, "falta data/{$name}.json\n");
        exit(1);
    }

    return (array) json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
};

$save = static function (string $name, array $data) use ($root, $check): void {
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json = json_encode($data, $flags | JSON_THROW_ON_ERROR) . "\n";

    $path = $root . '/data/' . $name . '.json';

    if (!$check && file_get_contents($path) !== $json) {
        file_put_contents($path, $json);
    }
};

ksort($plan);

foreach ($plan as $name => $entries) {
    $data = $load($name);
    $phrases = (array) ($data['phrases'] ?? []);
    $added = 0;
    $changed = 0;

    // New entries are appended in page order and existing ones keep their slot,
    // so adding vocabulary to a fixture produces a reviewable diff instead of
    // reordering the whole table.
    foreach ($entries as $key => $value) {
        if (!array_key_exists($key, $phrases)) {
            $added++;
            $phrases[$key] = $value;

            continue;
        }

        if ($phrases[$key] !== $value) {
            $changed++;
            $phrases[$key] = $value;
        }
    }

    $data['phrases'] = $phrases;
    $save($name, $data);

    printf(
        "  %-6s %4d frases  +%d nuevas  %d actualizadas  %s\n",
        $name,
        count($phrases),
        $added,
        $changed,
        $check ? '(dry run)' : ''
    );
}

printf("\n%d nodos glosados, %d tablas\n", count($glossary), count($plan));