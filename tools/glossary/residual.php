<?php

declare(strict_types=1);

/**
 * Reports the <p>/<h*>/<li> nodes that still carry one of the Spanish words
 * tests/i18n.php watches for, using that exact word list.
 *
 *   php tools/glossary/residual.php fr
 */

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use Medel\Translator\Support\Text;
use Medel\Translator\Translator;

$language = $_SERVER[1] ?? 'fr';

$page = (string) file_get_contents($root . '/tests/fixtures/software-2026.html');

$translator = new Translator([
    'data_path' => $root . '/data',
    'cache' => false,
    'empty_paragraphs' => 'br',
]);

$spanish = ['funciones', 'licencia', 'necesarios', 'archivos', 'carpeta',
    'documentacion', 'carrito', 'incluye', 'todas las', 'de por vida', 'clave por usuario'];

$out = $translator->translate($page, $language, 'es');

preg_match_all('/<(?:p|h[1-6]|li)(?:\s[^>]*)?>(.*?)<\/(?:p|h[1-6]|li)>/su', $out, $translated);

$found = 0;

foreach ($translated[1] as $index => $node) {
    $text = Text::trim(html_entity_decode(strip_tags($node), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    foreach ($spanish as $word) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1) {
            $found++;

            printf("[%d] %s\n     %s\n\n", $index, $word, $text);

            break;
        }
    }
}

printf("%d nodos con español residual en %s\n", $found, $language);