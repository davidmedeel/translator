<?php

declare(strict_types=1);

/**
 * Scans the fully translated page for punctuation that got doubled, or lost,
 * while a node was replaced by its phrase.
 *
 *   php tools/glossary/punctuation.php de en fr
 */

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use Medel\Translator\Support\Text;
use Medel\Translator\Translator;

$page = (string) file_get_contents($root . '/tests/fixtures/software-2026.html');

$translator = new Translator([
    'data_path' => $root . '/data',
    'cache' => false,
    'empty_paragraphs' => 'br',
]);

$languages = array_slice($_SERVER['argv'], 1);
$languages = $languages === [] ? ['de', 'en', 'fr', 'it', 'pt'] : $languages;

// Anything that must never appear twice in a row in the output. A space before
// ":" or "?" is correct French typography, so it is not a duplicated mark.
$patterns = [
    'doble dos puntos' => '/::/',
    'doble punto' => '/\.\./',
    'doble coma' => '/,,/',
    'doble exclamacion' => '/!!/',
    'doble interrogacion' => '/\?\?/',
    'doble parentesis' => '/\)\)/',
    'doble punto y coma' => '/;;/',
    // French puts a space before ";" as well, so only "." and "," are checked.
    'espacio antes de punto o coma' => '/\s+[.,]/',
];

$total = 0;

foreach ($languages as $language) {
    $out = $translator->translate($page, $language, 'es');
    $found = [];

    foreach ($patterns as $label => $pattern) {
        if (preg_match_all($pattern, $out, $m, PREG_OFFSET_CAPTURE) === false) {
            continue;
        }

        foreach ($m[0] as $hit) {
            $found[] = sprintf('%s -> %s', $label, addcslashes((string) substr($out, max(0, $hit[1] - 30), 60), "\0..\37"));
        }
    }

    printf("%-3s %s\n", $language, $found === [] ? 'sin puntuacion duplicada' : count($found) . ' avisos');

    foreach (array_slice($found, 0, 5) as $line) {
        echo '     ', $line, PHP_EOL;
    }

    $total += count($found);
}

printf("\n%d avisos en total\n", $total);