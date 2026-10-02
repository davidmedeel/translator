<?php

declare(strict_types=1);

/**
 * Reports glossary values that mix scripts inside one phrase, which is how a
 * garbled half-translated value shows up (a Japanese value with stray Latin or
 * Cyrillic words in the middle).
 *
 *   php tools/glossary/scramble.php
 */

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use Medel\Translator\Support\Text;

$glossary = [];

foreach (glob(__DIR__ . '/part-*.php') as $file) {
    foreach ((array) require $file as $index => $row) {
        $glossary[$index] = $row;
    }
}

foreach (glob(__DIR__ . '/mirror-*.php') as $file) {
    foreach ((array) require $file as $index => $row) {
        $glossary[$index] = array_merge($glossary[$index] ?? [], $row);
    }
}

$found = 0;

/**
 * A value is garbled when it carries a long latin word (or any Cyrillic) in the
 * middle of a run of ideographs / Arabic letters. Brand acronyms are fine, so
 * only runs of letters longer than three characters count.
 */
foreach ($glossary as $index => $row) {
    foreach (['ja', 'ar', 'ru'] as $code) {
        $value = $row[$code] ?? null;

        if ($value === null || $value === '') {
            continue;
        }

        $where = sprintf('indice %d (%s)', $index, $code);

        // Cyrillic inside a Japanese or Arabic value is always wrong.
        if ($code !== 'ru' && preg_match('/[\x{0400}-\x{04FF}]/u', $value) === 1) {
            printf("%s: contiene cirilico -> %s\n", $where, mb_substr($value, 0, 70));
            $found++;

            continue;
        }

        // Arabic inside a Japanese value, or Japanese ideographs inside Arabic.
        if ($code === 'ja' && preg_match('/[\x{0600}-\x{06FF}]/u', $value) === 1) {
            printf("%s: contiene arabe -> %s\n", $where, mb_substr($value, 0, 70));
            $found++;

            continue;
        }

        if ($code === 'ar' && preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $value) === 1) {
            printf("%s: contiene CJK -> %s\n", $where, mb_substr($value, 0, 70));
            $found++;

            continue;
        }

        // Latin words of four letters or more, inside the script of the value.
        if (preg_match_all('/(?<![\x{0600}-\x{06FF}\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}])[A-Za-z]{4,}(?![\x{0600}-\x{06FF}\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}])/u', $value, $m) > 0) {
            printf("%s: mezcla latin (%s) -> %s\n", $where, implode(', ', $m[0]), mb_substr($value, 0, 70));
            $found++;

            continue;
        }

        // Hangul, Thai, Hebrew, Devanagari or kana inside a value that is not
        // Japanese: a fragment of a language nobody asked for.
        if ($code !== 'ja' && preg_match('/[\x{1100}-\x{11FF}\x{0E00}-\x{0E7F}\x{0590}-\x{05FF}\x{0900}-\x{097F}]/u', $value) === 1) {
            printf("%s: mezcla otro alfabeto -> %s\n", $where, mb_substr($value, 0, 70));
            $found++;

            continue;
        }

        // Simplifying Chinese-only characters sitting inside Japanese text: the
        // mirror is Japanese, so kanji must be written with the Japanese forms.
        if ($code === 'ja' && preg_match('/[\x{4E00}-\x{9FFF}]/u', $value) === 1) {
            $kana = preg_match_all('/[\x{3040}-\x{30FF}]/u', $value);
            $han = preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $value);

            // A Japanese sentence mixes kana with kanji. A value with a big block
            // of hanzi and almost no kana reads like Chinese, not Japanese.
            if ($han > 0 && $kana === 0) {
                printf("%s: parece chino (sin kana) -> %s\n", $where, mb_substr($value, 0, 70));
                $found++;
            }
        }
    }
}

printf("\n%d valores suspectedos\n", $found);