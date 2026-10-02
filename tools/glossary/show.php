<?php

declare(strict_types=1);

/**
 * Prints a page node with its index and the translation each language returns,
 * for the nodes whose index is given on the command line.
 *
 *   php tools/glossary/show.php                  # every node, every language
 *   php tools/glossary/show.php 67 96 155        # only these nodes
 *   php tools/glossary/show.php --it 53 95 170  # only these nodes, one language
 */

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use Medel\Translator\Support\Text;
use Medel\Translator\Translator;

$argv = array_slice($_SERVER['argv'], 1);
$only = null;

if (($argv[0] ?? '') === '--' || str_starts_with($argv[0] ?? '', '--')) {
    $only = substr((string) array_shift($argv), 2);
}

$page = (string) file_get_contents($root . '/tests/fixtures/software-2026.html');

$translator = new Translator([
    'data_path' => $root . '/data',
    'cache' => false,
    'keep' => ['Medel Platforms'],
    'empty_paragraphs' => 'br',
]);

$nodes = [];

$translator->htmlProcessor()->translateNodes($page, static function (string $node) use (&$nodes): string {
    $text = Text::trim($node);

    if ($text !== '' && !Text::isPathLike($text)) {
        $nodes[] = $text;
    }

    return $node;
});

$languages = $only !== null
    ? [$only]
    : ['de', 'en', 'fr', 'it', 'pt', 'ja', 'ar', 'ru'];

$wanted = array_map('intval', $argv);
$wanted = $wanted === [] ? range(0, count($nodes) - 1) : $wanted;

foreach ($wanted as $index) {
    if (!isset($nodes[$index])) {
        printf("nodo %d no existe (hay %d)\n", $index, count($nodes));
        continue;
    }

    printf("[%d] %s\n", $index, $nodes[$index]);

    foreach ($languages as $code) {
        $out = $translator->translate($nodes[$index], $code, 'es');

        printf("     %-3s %s\n", $code, Text::length($out) > 150 ? Text::substr($out, 0, 150) . '...' : $out);
    }

    echo "\n";
}