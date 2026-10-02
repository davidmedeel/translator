<?php

declare(strict_types=1);

/**
 * Runnable demonstration of medel/translator.
 *
 *   php examples/demo.php
 *
 * Everything below runs locally: no HTTP request, no API key, no internet.
 */

require __DIR__ . '/../vendor/autoload.php';

use Medel\Translator\Translator;

$translator = new Translator([
    // Optional: defaults to the bundled /data folder.
    'data_path' => dirname(__DIR__) . '/data',
    // Brands and reserved words that must never be translated.
    'protected_terms' => ['Medel', 'WordPress', 'Ford'],
    // Where the local SQLite/JSON cache lives.
    'cache_dir' => sys_get_temp_dir() . '/medel-translator-demo',
]);

$line = str_repeat('-', 72);

echo $line . PHP_EOL;
echo 'medel/translator - offline translation engine' . PHP_EOL;
echo $line . PHP_EOL . PHP_EOL;

echo '1. Plain text, any pair of languages' . PHP_EOL;
echo '   es -> en : ' . $translator->translate('Hola mundo', 'en', 'es') . PHP_EOL;
echo '   en -> es : ' . $translator->translate('Good morning, my friend', 'es', 'en') . PHP_EOL;
echo '   es -> de : ' . $translator->translate('Anadir al carrito', 'de', 'es') . PHP_EOL;
echo '   es -> fr : ' . $translator->translate('Hola mundo', 'fr', 'es') . ' (via pivote)' . PHP_EOL;
echo '   it -> pt : ' . $translator->translate('Ciao mondo', 'pt', 'it') . ' (via pivote)' . PHP_EOL;
echo '   de -> es : ' . $translator->translate('Hallo Welt', 'es', 'de') . ' (via pivote)' . PHP_EOL;
echo '   auto     : ' . $translator->translate('Buenos dias, gracias por tu ayuda', 'en') . PHP_EOL;
echo PHP_EOL;

echo '2. HTML fragments: markup is never touched' . PHP_EOL;
$html = '<section class="card" data-id="42">'
    . '<h2>Hola mundo</h2>'
    . '<p>Bienvenido a <strong>Medel</strong>. <em>Presiona</em> <a href="/carrito" title="Carrito">el carrito</a>.</p>'
    . '<pre><code>// Hola mundo</code></pre>'
    . '</section>';
echo '   ' . $translator->translate($html, 'en', 'es') . PHP_EOL;
echo PHP_EOL;

echo '3. Protected terms (placeholders)' . PHP_EOL;
echo '   ' . $translator->translate('Hola Medel, compra un Ford en WordPress', 'en', 'es') . PHP_EOL;
echo '   terms: ' . implode(', ', $translator->protectedTerms()) . PHP_EOL;
echo PHP_EOL;

echo '3b. WordPress spacer paragraphs and emojis' . PHP_EOL;
$wpDoc = '<h3>&#128421; Carrito de compra</h3>'
    . '<p>&#128024; Anadir al carrito</p>'
    . '<p>&nbsp;</p>'
    . '<p>&#9989; Iniciar sesion</p>'
    . '<p>&nbsp;</p>'
    . '<p>🖥️ Emoji literal + icono 🚀</p>';

echo "   keep  : " . str_replace("\n", '', $translator->translate($wpDoc, 'en', 'es', ['empty_paragraphs' => 'keep'])) . PHP_EOL;
echo "   br    : " . str_replace("\n", '', $translator->translate($wpDoc, 'en', 'es', ['empty_paragraphs' => 'br'])) . PHP_EOL;
echo "   remove: " . str_replace("\n", '', $translator->translate($wpDoc, 'en', 'es', ['empty_paragraphs' => 'remove'])) . PHP_EOL;
echo PHP_EOL;

echo '4. Pivot language when there is no direct dictionary' . PHP_EOL;
foreach ($translator->route('es', 'it') as $leg) {
    echo '   ' . $leg['from'] . ' -> ' . $leg['to'] . PHP_EOL;
}
echo '   es -> de -> it : ' . $translator->translate('Hola mundo', 'it', 'es') . PHP_EOL;
echo PHP_EOL;

echo '4b. Combination matrix (what a language picker needs)' . PHP_EOL;
$languages = $translator->availableLanguages();
printf('        %s%s', str_repeat(' ', 8), implode(' ', array_map(static function (string $l): string {
    return str_pad($l, 3);
}, $languages)) . PHP_EOL);
$matrix = $translator->combinationMatrix();
foreach ($languages as $from) {
    printf('  %s   ', str_pad($from, 5));
    foreach ($languages as $to) {
        if ($from === $to) {
            printf('  - ');

            continue;
        }
        $legs = $matrix[$from][$to] ?? null;
        printf(' %s ', $legs === null ? 'NO' : ($legs === 1 ? ' D ' : ' P '));
    }
    echo PHP_EOL;
}
echo '   D = diccionario directo, P = via pivote (ingles)' . PHP_EOL;
echo '   targets from "es": ' . implode(', ', $translator->languagesFor('es')) . PHP_EOL;
echo PHP_EOL;

echo '5. Batch translation (strings and arrays)' . PHP_EOL;
$batch = $translator->translateBatch([
    'title' => 'Carrito de compra',
    'cta' => 'Finalizar compra',
    'meta' => ['envio' => 'Envio gratuito', 'sku' => 12345],
], 'en', 'es');
print_r($batch);
echo PHP_EOL;

echo '6. Unknown text is returned untouched' . PHP_EOL;
$mystery = 'Xyzzy plugh 42';
echo '   ' . $translator->translate($mystery, 'en', 'es') . PHP_EOL;
$result = $translator->result($mystery, 'en', 'es');
echo '   matched: ' . ($result->matched() ? 'yes' : 'no') . PHP_EOL;
echo PHP_EOL;

echo '7. Local cache (SQLite, falling back to JSON)' . PHP_EOL;
$translator->clearCache();
$start = microtime(true);
$translator->translate('Hola mundo', 'en', 'es');
$cold = (microtime(true) - $start) * 1000;

$start = microtime(true);
$translator->translate('Hola mundo', 'en', 'es');
$warm = (microtime(true) - $start) * 1000;

printf('   driver : %s (%s)%s', $translator->cache()->driver(), (string) $translator->cache()->path(), PHP_EOL);
printf('   first  : %.3f ms%s', $cold, PHP_EOL);
printf('   cached : %.3f ms%s', $warm, PHP_EOL);
echo PHP_EOL;

echo '8. Dictionaries found in /data' . PHP_EOL;
foreach ($translator->availablePairs() as $pair) {
    printf('   %s -> %s : %d entries (%s)%s', $pair['from'], $pair['to'], $pair['entries'], basename($pair['path']), PHP_EOL);
}
echo '   languages: ' . implode(', ', $translator->availableLanguages()) . PHP_EOL;
echo PHP_EOL;

echo '9. Detailed result object' . PHP_EOL;
print_r($translator->result('Hola mundo', 'de', 'es')->toArray());