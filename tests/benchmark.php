<?php

declare(strict_types=1);

/**
 * Benchmark: how much CPU and how much time does a translation cost?
 *
 *   php tests/benchmark.php
 *
 * Every number is a real measurement on the machine that runs it. Cold means an
 * empty cache (every fragment is computed and written); warm means the same
 * document served from the cache.
 */

require __DIR__ . '/../vendor/autoload.php';

use Medel\Translator\Translator;

$root = dirname(__DIR__);
$page = (string) file_get_contents(__DIR__ . '/fixtures/tokify.html');
$cacheDir = sys_get_temp_dir() . '/medel-translator-benchmark';

// Brands copied verbatim: real pages need them.
$brands = [
    'Tokify', 'Stripe', 'PayPal', 'ElevenLabs', 'HeyGen', 'D-ID', 'Whisper.cpp', 'Pexels',
    'OpenAI', 'Anthropic', 'YouTube', 'TikTok', 'Instagram', 'Reddit', 'Amazon S3', 'WYSIWYG',
    'PHP', 'SQL', 'SaaS', 'API', 'REST', 'PWA', 'S3', 'SEO',
];

$options = [
    'data_path' => $root . '/data',
    'cache_dir' => $cacheDir,
    'keep' => $brands,
];

/**
 * @param array<int,string> $languages
 */
$measure = static function (callable $work, array $languages, int $each = 1): array {
    $start = microtime(true);
    $work();
    $total = (microtime(true) - $start) * 1000;

    return [$total, $total / max(1, count($languages) * $each)];
};

$format = static function (string $label, float $total, float $each, string $unit = 'ms'): void {
    printf("  %-42s %9.2f %s", $label, $total, $unit);

    if ($each > 0 && $each !== $total) {
        printf("   (%.3f %s cada uno)", $each, $unit);
    }

    echo PHP_EOL;
};

$kb = static function (int $bytes): float {
    return round($bytes / 1024, 1);
};

echo 'PHP ' . PHP_VERSION . ' | ' . PHP_UNAME('s') . ' | ' . PHP_INT_SIZE . ' bits', PHP_EOL;
echo str_repeat('=', 74), PHP_EOL, PHP_EOL;

// A clean cache, so "cold" really means cold.
foreach (glob($cacheDir . '/*') as $file) {
    @unlink($file);
}

$pageKb = $kb(strlen($page));
echo "Documento de prueba: {$pageKb} KB, 65 nodos de texto\n";
echo str_repeat('-', 74), PHP_EOL;

echo "\n1. ARRANQUE\n";

[$total] = $measure(static function () use ($options, $page): void {
    (new Translator($options))->translate($page, 'de', 'es');
}, ['de']);
$format('Pagina completa, cache vacia (frase a frase)', $total, 0);

[$total] = $measure(static function () use ($root, $brands): void {
    (new Translator(['data_path' => $root . '/data', 'cache' => false, 'keep' => $brands]))
        ->translate('Hola mundo', 'de', 'es');
}, ['de']);
$format('Carga de las 11 tablas en memoria', $total, 0);

echo "\n2. PAGINA COMPLETA (es > de, 65 fragmentos)\n";

[$total] = $measure(static function () use ($options, $page): void {
    (new Translator($options))->translate($page, 'de', 'es');
}, ['de']);
$format('Cache caliente', $total, 0);

[$total] = $measure(static function () use ($root, $brands, $page): void {
    (new Translator(['data_path' => $root . '/data', 'cache' => false, 'keep' => $brands]))
        ->translate($page, 'de', 'es');
}, ['de']);
$format('Sin cache (solo calculo del motor)', $total, 0);

echo "\n3. LOS CINCO IDIOMAS\n";

$translator = new Translator($options);

foreach (['de', 'en', 'fr', 'it', 'pt'] as $language) {
    $translator->translate($page, $language, 'es');
}

[$total, $each] = $measure(static function () use ($translator, $page): void {
    foreach (['de', 'en', 'fr', 'it', 'pt'] as $language) {
        $translator->translate($page, $language, 'es');
    }
}, ['de', 'en', 'fr', 'it', 'pt']);
$format('Pagina completa x5, ya cacheadas', $total, $each);

echo "\n4. TEXTOS SUELTOS\n";

[$total, $each] = $measure(static function () use ($root): void {
    $t = new Translator(['data_path' => $root . '/data', 'cache' => false]);

    for ($i = 0; $i < 2000; $i++) {
        $t->translate('Añadir al carrito', 'de', 'es');
    }
}, ['de'], 2000);
$format('2000 traducciones, sin cache', $total, $each);

[$total, $each] = $measure(static function () use ($root): void {
    $t = new Translator(['data_path' => $root . '/data', 'cache' => false]);

    for ($i = 0; $i < 2000; $i++) {
        $t->translate('Xyzzy plugh ' . $i, 'de', 'es');
    }
}, ['de'], 2000);
$format('2000 textos desconocidos (peor caso)', $total, $each);

echo "\n5. DOCUMENTO GRANDE\n";

$big = str_repeat($page, 20);
$bigTranslator = new Translator($options + ['empty_paragraphs' => 'keep']);
$bigTranslator->translate($big, 'de', 'es');

[$total] = $measure(static function () use ($bigTranslator, $big): void {
    $bigTranslator->translate($big, 'de', 'es');
}, ['de']);
$format($kb(strlen($big)) . ' KB de HTML (x20), caliente', $total, 0);

echo "\n6. MEMORIA Y CACHE\n";

printf("  %-42s %9.1f MB\n", 'Pico de memoria del proceso', memory_get_peak_usage(true) / 1048576);

$bytes = 0;
$count = 0;

foreach (glob($cacheDir . '/*') as $file) {
    $bytes += (int) filesize($file);
    $count++;
}

printf("  %-42s %9.1f KB  (%d archivos)\n", 'Cache en disco', $kb($bytes), $count);
printf("  %-42s %9.1f KB  (%d tablas)\n", 'Diccionarios', array_sum(array_map('filesize', glob($root . '/data/*.json'))) / 1024, count(glob($root . '/data/*.json')));

echo "\n", str_repeat('=', 74), PHP_EOL;
echo "Sin cache el coste es siempre el mismo; con cache, la segunda vez\n";
echo "es un lookup. Para produccion: precalienta la cache al desplegar.\n";