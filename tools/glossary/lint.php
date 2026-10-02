<?php

declare(strict_types=1);

/**
 * Sanity checks on the article glossary before it is merged into data/.
 *
 *   php tools/glossary/lint.php
 *
 * Catches the mistakes that are easy to make when writing hundreds of phrases
 * by hand: invalid UTF-8, values that are not in the script of their language,
 * Spanish left inside a non Spanish value, keys or values that duplicate an
 * existing entry with a different translation, and mirror languages that were
 * forgotten for a node.
 */

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use Medel\Translator\Support\Text;
use Medel\Translator\Translator;

$problems = [];
$warnings = [];
$note = static function (string $message) use (&$problems): void {
    $problems[] = $message;
};

/**
 * Not a failure: two languages can legitimately share a word. "dipendenza alta"
 * is correct Italian and also correct Spanish, and "Alta" translates "Alta".
 */
$warn = static function (string $message) use (&$warnings): void {
    $warnings[] = $message;
};

/* ---------------------------------------------------------------- 1. NODES */

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

/* ------------------------------------------------------------- 2. GLOSSARY */

$glossary = [];
$seen = [];

foreach (glob(__DIR__ . '/part-*.php') as $file) {
    foreach ((array) require $file as $index => $row) {
        if (isset($glossary[$index])) {
            $note(sprintf('%s: el indice %d ya estaba definido', basename($file), $index));
        }

        $glossary[$index] = $row;
        $seen[$index] = basename($file);
    }
}

foreach (glob(__DIR__ . '/mirror-*.php') as $file) {
    foreach ((array) require $file as $index => $row) {
        if (!isset($glossary[$index])) {
            $note(sprintf('%s: el indice %d no existe en part-*.php', basename($file), $index));

            continue;
        }

        foreach ($row as $code => $value) {
            $glossary[$index][$code] = $value;
        }
    }
}

$targets = ['de', 'en', 'fr', 'it', 'pt', 'ja', 'ar', 'ru'];

$script = static function (string $code): string {
    if ($code === 'ja') {
        return 'cjk';
    }

    return $code === 'ar' ? 'arabic' : ($code === 'ru' ? 'cyrillic' : 'latin');
};

$scriptName = static fn (string $s): string => [
    'cjk' => 'alfabeto CJK',
    'arabic' => 'alfabeto arabe',
    'cyrillic' => 'alfabeto cirilico',
    'latin' => 'alfabeto latino',
][$s];

/* -------------------------------------------------------------- 3. CHECKS */

$incomplete = [];

foreach ($glossary as $index => $row) {
    $spanish = $nodes[$index] ?? null;

    if ($spanish === null) {
        $note(sprintf('indice %d: fuera de rango (el fixture tiene %d nodos)', $index, count($nodes)));

        continue;
    }

    if (!mb_check_encoding($spanish, 'UTF-8')) {
        $note(sprintf('indice %d: el nodo de origen no es UTF-8 valido', $index));
    }

    foreach ($targets as $code) {
        $value = $row[$code] ?? null;

        if ($value === null || $value === '') {
            $incomplete[$code][] = $index;

            continue;
        }

        $where = sprintf('indice %d (%s)', $index, $code);

        if (!mb_check_encoding($value, 'UTF-8')) {
            $note($where . ': el valor no es UTF-8 valido');

            continue;
        }

        if (trim($value) === '') {
            $note($where . ': el valor esta vacio');

            continue;
        }

        // The value must not simply repeat the Spanish it translates. Two
        // languages can share a word, so this is only a warning.
        if (Text::normalizeKey($value) === Text::normalizeKey($spanish)) {
            $warn(sprintf('%s: el valor coincide con el espanol de origen ("%s")', $where, mb_substr($value, 0, 40)));
        }

        // And it must be written in the script the language uses.
        $expected = $script($code);
        $hasCjk = preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $value) === 1;
        $hasArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $value) === 1;
        $hasCyrillic = preg_match('/[\x{0400}-\x{04FF}]/u', $value) === 1;
        $latinOnly = preg_match('/^[A-Za-z0-9 .&\'’\/-]+$/u', $value) === 1;

        $ok = match ($expected) {
            'cjk' => $hasCjk || $latinOnly,
            'arabic' => $hasArabic || $latinOnly,
            'cyrillic' => $hasCyrillic || $latinOnly,
            default => !$hasCjk && !$hasArabic && !$hasCyrillic,
        };

        if (!$ok) {
            $note(sprintf('%s: el valor no esta en %s -> %s', $where, $scriptName($expected), mb_substr($value, 0, 60)));
        }

        // A value must keep the punctuation of its node, otherwise the sentence
        // reads wrong once the node is replaced.
        $endsQuestion = str_ends_with(rtrim($spanish), '?');
        $endsColon = str_ends_with(rtrim($spanish), ':');

        if ($endsQuestion && !str_ends_with($value, '?')) {
            $note($where . ': el nodo termina en interrogacion y el valor no');
        }

        if ($endsColon && !str_ends_with($value, ':')) {
            $note($where . ': el nodo termina en dos puntos y el valor no');
        }
    }
}

/* ------------------------------------------- 4. CLASHES WITH SHIPPED DATA */

$load = static function (string $name) use ($root): array {
    return (array) ((array) json_decode(
        (string) file_get_contents($root . '/data/' . $name . '.json'),
        true
    ))['phrases'] ?? [];
};

foreach ($glossary as $index => $row) {
    $spanish = $nodes[$index] ?? null;

    if ($spanish === null) {
        continue;
    }

    foreach (['en' => 'es_en', 'de' => 'es_de'] as $code => $table) {
        $value = $row[$code] ?? null;

        if ($value === null) {
            continue;
        }

        $existing = $load($table);

        if (isset($existing[$spanish]) && $existing[$spanish] !== $value) {
            $note(sprintf(
                "indice %d: %s ya tiene \"%s\" = \"%s\", el glosario propone \"%s\"",
                $index,
                $table,
                mb_substr($spanish, 0, 40),
                mb_substr($existing[$spanish], 0, 40),
                mb_substr($value, 0, 40)
            ));
        }
    }

    $english = $row['en'] ?? null;

    if ($english === null) {
        continue;
    }

    foreach (['fr', 'it', 'pt', 'ja', 'ar', 'ru'] as $code) {
        $value = $row[$code] ?? null;

        if ($value === null) {
            continue;
        }

        $existing = $load('en_' . $code);

        if (isset($existing[$english]) && $existing[$english] !== $value) {
            $note(sprintf(
                "indice %d: en_%s ya difiere para la misma frase inglesa",
                $index,
                $code
            ));
        }
    }
}

/* ---------------------------------------------------------------- 5. DONE */

printf("nodos glosados: %d de %d\n", count($glossary), count($nodes));

foreach ($incomplete as $code => $list) {
    printf("  %-3s faltan %3d nodos\n", $code, count($list));
}

if ($problems === []) {
    foreach ($warnings as $warning) {
        echo '  [aviso] ', $warning, PHP_EOL;
    }

    echo "\nOK: el glosario esta limpio";

    echo $warnings === [] ? ".\n" : sprintf(" (%d avisos).\n", count($warnings));

    exit(0);
}

echo "\n";

foreach ($problems as $problem) {
    echo '  [FAIL] ', $problem, PHP_EOL;
}

printf("\n%d problemas.\n", count($problems));

exit(1);