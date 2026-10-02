<?php

declare(strict_types=1);

/**
 * Shows which existing glossary entries the article glossary would overwrite,
 * so a review can decide case by case instead of blindly replacing the file.
 *
 *   php tools/glossary/diff.php
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

$overwritten = 0;

foreach (['es_de', 'es_en', 'en_de', 'en_fr', 'en_it', 'en_pt', 'en_ja', 'en_ar', 'en_ru'] as $name) {
    $phrases = (array) ((array) json_decode(
        (string) file_get_contents($root . '/data/' . $name . '.json'),
        true
    ))['phrases'] ?? [];

    foreach ($glossary as $index => $row) {
        foreach ($row as $code => $value) {
            if ($code === 'en' || $code === 'de') {
                continue;
            }

            $key = $name === 'es_de' ? $nodes[$index] : ($row['en'] ?? null);

            if ($key === null || !isset($phrases[$key])) {
                continue;
            }

            if ($phrases[$key] === $value) {
                continue;
            }

            $overwritten++;

            printf(
                "%s  nodo %d (%s)\n  actual: %s\n  nuevo : %s\n\n",
                $name,
                $index,
                $code,
                $phrases[$key],
                $value
            );
        }
    }
}

printf("%d entradas existentes cambiarían\n", $overwritten);