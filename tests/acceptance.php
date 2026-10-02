<?php

declare(strict_types=1);

/**
 * Acceptance test: send a mixed document (plain text + HTML + entities +
 * emojis + WordPress spacers) and check that ANY language can be requested
 * while the markup comes out exactly as it went in.
 *
 *   php tests/acceptance.php
 *
 * Copy the $message below with your own content and run it.
 */

require __DIR__ . '/../vendor/autoload.php';

use Medel\Translator\Translator;

$message = <<<'TXT'
Te has quedado pillado o habias acabado todo?
Si has acabado, prueba a traducir esto a aleman:
<p>Antes de instalar RedactSEO, aseg&uacute;rate de que tu servidor cumple con los siguientes requisitos m&iacute;nimos.</p>
<p>&nbsp;</p>
<h3>🖥️ Requisitos del servidor</h3>
<ul>
<li>
<p>🐘 <strong>PHP 8.2 o superior</strong></p>
</li>
<li>
<p>✅ Compatible con <strong>PHP 8.4</strong></p>
</li>
<li>
<p>🗄️ <strong>MySQL 5.7+</strong> o <strong>MariaDB</strong></p>
</li>
<li>
<p>🌐 <strong>Apache</strong> o <strong>Nginx</strong></p>
</li>
<li>
<p>🔒 <strong>HTTPS recomendado</strong> para entornos de producci&oacute;n</p>
</li>
</ul>
<p>&nbsp;</p>
<h3>🧩 Extensiones de PHP necesarias</h3>
<hr>
<ul>
<li>
<p><code>curl</code> &mdash; Comunicaci&oacute;n con APIs y servicios externos</p>
</li>
<li>
<p><code>pdo_mysql</code> &mdash; Conexi&oacute;n segura con bases de datos MySQL / MariaDB</p>
</li>
</ul>
<p>&nbsp;</p>
<p><strong>Versi&oacute;n m&iacute;nima de PHP:</strong> 8.2<br><strong>Compatibilidad:</strong> PHP 8.2 &ndash; 8.4</p>
TXT;

$languages = ['de', 'fr', 'en', 'it', 'pt'];

/**
 * Everything that must not change: tags, entities, emojis.
 *
 * @return array{0:array<int,string>,1:array<int,string>,2:array<int,string>}
 */
$fingerprint = static function (string $html): array {
    preg_match_all('/<[^>]+>/', $html, $tags);
    preg_match_all('/&(?:#[0-9]+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);/', $html, $entities);
    preg_match_all('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $html, $emojis);

    return [$tags[0], $entities[0], $emojis[0]];
};

$factory = static function (array $options = []): Translator {
    return new Translator(array_merge([
        'data_path' => dirname(__DIR__) . '/data',
        'cache_dir' => sys_get_temp_dir() . '/medel-translator-acceptance',
        'protected_terms' => ['RedactSEO'],
    ], $options));
};

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures++;
    }
    printf("  %-6s %s\n", $ok ? '[ok]' : '[FAIL]', $label);
};

$translator = $factory();
[$expectedTags, $expectedEntities, $expectedEmojis] = $fingerprint($message);

echo "1. Markup, entities and emojis must survive in every language\n";
foreach ($languages as $language) {
    $out = $translator->translate($message, $language, 'es');
    [$tags, $entities, $emojis] = $fingerprint($out);

    $check($language . ': tags identical (' . count($tags) . ')', $tags === $expectedTags);
    $check($language . ': no invented entities (' . count($entities) . ')', array_diff($entities, $expectedEntities) === []);
    $check($language . ': emojis identical (' . count($emojis) . ')', $emojis === $expectedEmojis);
}

echo "\n2. Spacer paragraphs (<p>&nbsp;</p> -> <br>)\n";
$spacers = $factory(['empty_paragraphs' => 'br']);
$converted = $spacers->translate($message, 'de', 'es');
$check('spacers became <br>', substr_count($converted, '<br>') > substr_count($message, '<br>'));
$check('<hr> preserved', strpos($converted, '<hr>') !== false);
$check('<code> preserved', strpos($converted, '<code>curl</code>') !== false);

echo "\n3. Language coverage on the HTML part\n";
$htmlPart = substr($message, (int) strpos($message, '<p>Antes'));
foreach ($languages as $language) {
    $translator->clearCache();
    $result = $translator->result($htmlPart, $language, 'es');
    printf(
        "  %-3s %2d/%2d nodos | cobertura %3d%% | ruta %s\n",
        $language,
        $result->matchedSegments(),
        $result->segments(),
        round($result->coverage() * 100),
        $result->routeAsString() ?: '-'
    );
}

echo "\n4. Commercial SaaS page (tests/fixtures/tokify.html), brands kept verbatim\n";
$tokify = file_get_contents(__DIR__ . '/fixtures/tokify.html');
$brands = [
    'Tokify', 'Stripe', 'PayPal', 'ElevenLabs', 'HeyGen', 'D-ID', 'Whisper.cpp', 'Pexels',
    'OpenAI', 'Anthropic', 'YouTube', 'TikTok', 'Instagram', 'Reddit', 'Amazon S3', 'WYSIWYG',
    'PHP', 'SQL', 'SaaS', 'API', 'REST', 'PWA', 'S3', 'SEO',
];
[$pageTags, $pageEntities, $pageEmojis] = $fingerprint($tokify);

foreach (['keep', 'br'] as $mode) {
    $page = $factory(['empty_paragraphs' => $mode, 'keep' => $brands]);
    $page->clearCache();

    $reference = $mode === 'br' ? str_replace('<p>&nbsp;</p>', '<br>', $tokify) : $tokify;
    [$referenceTags] = $fingerprint($reference);

    foreach ($languages as $language) {
        $page->clearCache();
        $out = $page->translate($tokify, $language, 'es');
        [$tags, $entities, $emojis] = $fingerprint($out);

        $check("{$mode}/{$language}: tags identical (" . count($tags) . ')', $tags === $referenceTags);
        $check("{$mode}/{$language}: no invented entities", array_diff($entities, $pageEntities) === []);
        $check("{$mode}/{$language}: emojis identical (" . count($emojis) . ')', $emojis === $pageEmojis);
    }

    $page->clearCache();
    $result = $page->result($tokify, 'de', 'es');
    printf(
        "  %-3s %2d/%2d nodos | cobertura %3d%% | ruta %s\n",
        $mode,
        $result->matchedSegments(),
        $result->segments(),
        round($result->coverage() * 100),
        $result->routeAsString() ?: '-'
    );
}

echo "\n5. Output sample (German)\n";
echo $converted, "\n";

echo "\n" . ($failures === 0
    ? "OK: la estructura HTML se conserva en los " . count($languages) . " idiomas.\n"
    : "FAILED: {$failures} comprobaciones.\n");

exit($failures === 0 ? 0 : 1);