<?php

declare(strict_types=1);

/**
 * Shows the es_<target> entries the article glossary would change.
 *
 *   php tools/glossary/diff-direct.php
 */

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use Medel\Translator\Support\Text;
use Medel\Translator\Translator;

$page = (string) file_get_contents($root . '/tests/fixtures/software-2026.html');

$collector = new Translator([
    'data_path' => $root . '/data',
    'cache' => false,
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

$glossary = [];

foreach (glob(__DIR__ . '/part-*.php') as $file) {
    foreach ((array) require $file as $index => $row) {
        $glossary[$index] = $row;
    }
}

$changed = 0;

foreach (['es_en' => 'en', 'es_de' => 'de'] as $name => $code) {
    $phrases = (array) ((array) json_decode(
        (string) file_get_contents($root . '/data/' . $name . '.json'),
        true
    ))['phrases'] ?? [];

    foreach ($glossary as $index => $row) {
        $value = $row[$code] ?? null;
        $key = $nodes[$index] ?? null;

        if ($value === null || $key === null || !isset($phrases[$key])) {
            continue;
        }

        if ($phrases[$key] === $value) {
            continue;
        }

        $changed++;

        printf(
            "%s  nodo %d\n  clave  : %s\n  actual : %s\n  nuevo  : %s\n\n",
            $name,
            $index,
            $key,
            $phrases[$key],
            $value
        );
    }
}

printf("%d entradas directas cambiarían\n", $changed);