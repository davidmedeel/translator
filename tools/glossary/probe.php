<?php

declare(strict_types=1);

/**
 * Feeds one trial sentence per language through the hub and reports what came
 * back, so we can see how much a mirror actually costs before writing it.
 *
 *   php tools/glossary/probe.php
 */

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use Medel\Translator\Support\Text;
use Medel\Translator\Translator;

$translator = new Translator([
    'data_path' => $root . '/data',
    'cache' => false,
    'empty_paragraphs' => 'br',
]);

$sentences = [
    'Complete source code of the PHP application',
    'Standard Software: Advantages and Limitations',
    'Low initial investment:',
    'Feature',
    'Initial investment',
    'When to Choose Standard Software',
];

$english = array_map(
    static fn (string $s): string => "Tokify $s",
    $sentences
);

foreach (['en', 'de'] as $code) {
    printf("== es -> %s ==\n", $code);

    foreach ($english as $i => $text) {
        $out = $translator->translate($text, $code, 'es');
        $result = $translator->result($text, $code, 'es');

        printf(
            "  %-46s %2d car. cov %3d%%  %s\n",
            mb_substr($sentences[$i], 0, 46),
            Text::length($out),
            (int) ($result->coverage() * 100),
            $out
        );
    }

    echo "\n";
}

// Cost estimate: Japanese has no spaces, so a phrase key is one contiguous run.
$sample = 'Off the shelf software, also known as commercial off the shelf (COTS) or boxed software, is a ready made solution that covers common needs.';

printf("frase fuente: %d caracteres, %d palabras\n", strlen($sample), count(preg_split('/\s+/', $sample)));
printf("MAX_CJK_CHARS = %d, asi que una clave CJK mas larga no se puede recortar\n", 64);