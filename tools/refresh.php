<?php
/**
 * Regenerates every reverse table and re-applies the hand written additions,
 * in the correct order. Run this whenever an en_XX table changes.
 */

$dataDir = 'C:/xampp/desarrollo/translator/data';
$root = 'C:/xampp/desarrollo/translator';

// 1. reverse tables
foreach ([['en_fr', 'fr_en'], ['en_it', 'it_en'], ['en_pt', 'pt_en'], ['en_de', 'de_en']] as [$source, $target]) {
    exec(sprintf(
        'php %s %s %s',
        escapeshellarg($root . '/tools/invert.php'),
        escapeshellarg($dataDir . '/' . $source . '.json'),
        escapeshellarg($dataDir . '/' . $target . '.json')
    ), $out, $code);
    echo implode("\n", $out), "\n";
    $out = [];
}

// 2. German pivot: drop the articles instead of carrying them through English
$deEn = json_decode(file_get_contents($dataDir . '/de_en.json'), true);
$deEn = [
    'from' => $deEn['from'],
    'to' => $deEn['to'],
    'name' => $deEn['name'] ?? 'de => en',
    'version' => 1,
    'drop_source' => ['der', 'die', 'das', 'dem', 'den', 'des'],
] + $deEn;
file_put_contents($dataDir . '/de_en.json', json_encode($deEn, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo "de_en.json: drop_source restored\n";

// 3. hand written additions the generator cannot infer
$additions = [
    'es_en.json' => ['es' => 'is', 'son' => 'are', 'va' => 'goes', 'voy' => 'I go', 'ya es' => 'it is already'],
    'en_es.json' => ['es' => 'es', 'son' => 'son', 'va' => 'va', 'voy' => 'voy', 'ser' => 'ser', 'estar' => 'estar'],
    'en_pt.json' => ['is' => 'está', 'are' => 'são', 'am' => 'sou', 'was' => 'era'],
    'en_fr.json' => ['is' => 'est', 'are' => 'sont', 'am' => 'suis', 'was' => 'étais'],
    'en_it.json' => ['is' => 'è', 'are' => 'sono', 'am' => 'sono', 'was' => 'era'],
    'it_en.json' => [
        'la' => 'the', 'lo' => 'the', 'i' => 'the', 'gli' => 'the', 'le' => 'the',
        'un' => 'a', 'una' => 'a', 'uno' => 'a', 'del' => 'of the', 'della' => 'of the',
        'nel' => 'in the', 'nella' => 'in the', 'al' => 'to the', 'alla' => 'to the',
    ],
    'fr_en.json' => [
        'le' => 'the', 'la' => 'the', 'les' => 'the', 'un' => 'a', 'une' => 'a', 'des' => 'some',
        'du' => 'of the', 'de la' => 'of the', 'au' => 'to the', 'aux' => 'to the',
        // feminine forms the generator cannot derive from the masculine entry
        'grande' => 'big', 'petite' => 'small', 'vide' => 'empty', 'pleine' => 'full',
        'nouvelle' => 'new', 'vraie' => 'true', 'maison' => 'house', 'voiture' => 'car',
    ],
    'pt_en.json' => [
        'o' => 'the', 'os' => 'the', 'as' => 'the', 'um' => 'a', 'uma' => 'a', 'uns' => 'some',
        'do' => 'of the', 'da' => 'of the', 'no' => 'in the', 'na' => 'in the',
        'está' => 'is', 'estão' => 'are', 'está vazio' => 'is empty', 'está vazia' => 'is empty',
    ],
    'en_fr.json' => [
        'grande' => 'grand', 'petite' => 'petit', 'haute' => 'haut', 'jeune' => 'jeune',
    ],
];

foreach ($additions as $file => $rows) {
    $path = $dataDir . '/' . $file;
    $data = json_decode(file_get_contents($path), true);
    $added = 0;

    foreach ($rows as $key => $value) {
        if (!isset($data['words'][$key])) {
            $data['words'][$key] = $value;
            $added++;
        }
    }

    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    echo str_pad($file, 14), "+{$added} entries\n";
}

// 4. Keys that only differ in case: the index folds them, so one of the two
// entries is dead weight. Inverting a table produces them (two English keys can
// share a German value in a different case), so this runs after every refresh.
foreach (glob($dataDir . '/*.json') as $file) {
    $data = json_decode(file_get_contents($file), true);

    if (!is_array($data) || !isset($data['phrases'], $data['words'])) {
        continue;
    }

    $removed = 0;

    foreach (['phrases', 'words'] as $section) {
        $keep = [];
        $drop = [];

        foreach ($data[$section] as $key => $value) {
            $folded = strtolower(trim((string) $key));

            if (isset($keep[$folded])) {
                $drop[$keep[$folded]] = true;   // the older one loses
                $removed++;
            }

            $keep[$folded] = $key;              // the newest survives
        }

        foreach (array_keys($drop) as $old) {
            unset($data[$section][$old]);
        }
    }

    if ($removed > 0) {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        printf("%-14s -%d keys that only differ in case\n", basename($file), $removed);
    }
}

// 5. Single word entries duplicated in "phrases" shadow the words table
foreach (glob($dataDir . '/*.json') as $file) {
    $data = json_decode(file_get_contents($file), true);

    if (!is_array($data) || !isset($data['phrases'], $data['words'])) {
        continue;
    }

    $removed = 0;

    foreach (array_keys($data['phrases']) as $key) {
        if (strpos((string) $key, ' ') === false && isset($data['words'][$key])) {
            unset($data['phrases'][$key]);
            $removed++;
        }
    }

    if ($removed > 0) {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        printf("%-14s -%d duplicated single word phrases\n", basename($file), $removed);
    }
}
echo "\ndone\n";