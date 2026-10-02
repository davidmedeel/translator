<?php

declare(strict_types=1);

/**
 * Dependency free smoke test suite.
 *
 *   php tests/smoke.php
 *
 * It exercises the public API, the HTML pipeline, the protected terms, the
 * pivot strategy, both cache backends and - importantly - asserts that the
 * package contains no networking code at all.
 */

require __DIR__ . '/../vendor/autoload.php';

use Medel\Translator\Engines\DictionaryTable;
use Medel\Translator\Support\Text;
use Medel\Translator\Translator;

final class Suite
{
    /** @var int */
    private $passed = 0;

    /** @var array<int,string> */
    private $failures = [];

    /** @var string */
    private $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo PHP_EOL . '== ' . $name . ' ==' . PHP_EOL;
    }

    public function is(string $label, $expected, $actual): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo '  [ok]   ' . $label . PHP_EOL;

            return;
        }

        $this->failures[] = $this->group . ' / ' . $label;
        echo '  [FAIL] ' . $label . PHP_EOL;
        echo '         expected: ' . var_export($expected, true) . PHP_EOL;
        echo '         actual:   ' . var_export($actual, true) . PHP_EOL;
    }

    public function true(string $label, $actual): void
    {
        $this->is($label, true, (bool) $actual);
    }

    public function false(string $label, $actual): void
    {
        $this->is($label, false, (bool) $actual);
    }

    public function summary(): int
    {
        $total = $this->passed + count($this->failures);

        echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;

        if ($this->failures === []) {
            echo sprintf('OK: %d/%d assertions passed.', $this->passed, $total) . PHP_EOL;

            return 0;
        }

        echo sprintf('FAILED: %d of %d assertions.', count($this->failures), $total) . PHP_EOL;
        foreach ($this->failures as $failure) {
            echo '  - ' . $failure . PHP_EOL;
        }

        return 1;
    }
}

$t = new Suite();

$root = dirname(__DIR__);
$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medel-translator-tests';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

$translator = new Translator([
    'data_path' => $root . '/data',
    'cache_dir' => $cacheDir,
    'cache_driver' => 'sqlite',
    'protected_terms' => ['Medel', 'WordPress', 'Ford'],
]);

/* ------------------------------------------------------------------ basic */

$t->group('Core API');

$t->is('phrase translation', 'hello world', $translator->translate('hola mundo', 'en', 'es'));
$t->is('case preserved', 'Hello world', $translator->translate('Hola Mundo', 'en', 'es'));
$t->is('reverse pair', 'hola mundo', $translator->translate('hello world', 'es', 'en'));
$t->is('german direct', 'hallo Welt', $translator->translate('hola mundo', 'de', 'es'));
$t->is('same language is a no-op', 'Hola mundo', $translator->translate('Hola mundo', 'es', 'es'));
$t->is('unknown text is preserved', 'Zzzz qqqq', $translator->translate('Zzzz qqqq', 'en', 'es'));
$t->is('empty string', '', $translator->translate('', 'en', 'es'));
$t->is('regional variant normalized', 'hello world', $translator->translate('hola mundo', 'en', 'es_MX'));
$t->is('iso 639-3 alias', 'hello world', $translator->translate('hola mundo', 'en', 'spa'));

$t->group('Auto detection');

$t->is('detects spanish', 'es', (string) $translator->detectLanguage('Hola mundo, buenas tardes'));
$t->is('detects english', 'en', (string) $translator->detectLanguage('Hello world, good morning'));
$t->is('translate without source', 'Hello world', $translator->translate('Hola mundo', 'en'));

/* ------------------------------------------------------------------- html */

$t->group('HTML pipeline');

$html = '<p class="intro">Hola <b>mundo</b>, <i>bienvenido</i> a <a href="/home" title="Inicio">Medel</a>.</p>';

$t->is(
    'markup and attributes preserved',
    '<p class="intro">Hello <b>world</b>, <i>welcome</i> to <a href="/home" title="Inicio">Medel</a>.</p>',
    $translator->translate($html, 'en', 'es')
);

$t->is(
    'void and self closing tags',
    '<p>Hello<br />world<br>end</p>',
    $translator->translate('<p>Hola<br />mundo<br>fin</p>', 'en', 'es')
);

$t->is(
    'comments and doctype untouched',
    '<!-- Hola mundo --><p>Hello world</p>',
    $translator->translate('<!-- Hola mundo --><p>Hola mundo</p>', 'en', 'es')
);

$t->is(
    'script and style skipped',
    '<script>var hola = "Hola mundo";</script><style>.a{content:"Hola mundo"}</style><p>Hello world</p>',
    $translator->translate('<script>var hola = "Hola mundo";</script><style>.a{content:"Hola mundo"}</style><p>Hola mundo</p>', 'en', 'es')
);

$t->is(
    'code block skipped',
    '<pre><code>echo "Hola mundo";</code></pre><p>Hello world</p>',
    $translator->translate('<pre><code>echo "Hola mundo";</code></pre><p>Hola mundo</p>', 'en', 'es')
);

$t->is(
    'html entities preserved',
    '<p>Zamb&oacute;a &amp; Hello world&nbsp;end</p>',
    $translator->translate('<p>Zamb&oacute;a &amp; Hola mundo&nbsp;fin</p>', 'en', 'es')
);

$t->is(
    'entity encoded accents are translatable',
    'tomorrow',
    $translator->translate('ma&ntilde;ana', 'en', 'es')
);

$t->is(
    'soft hyphen does not break the word',
    'tomorrow',
    $translator->translate('ma&shy;nana', 'en', 'es')
);

$t->is(
    'em dash entity stays a separator and is restored',
    'Hello world &mdash; Connection secure',
    $translator->translate('Hola mundo &mdash; Conexi&oacute;n segura', 'en', 'es')
);

$t->is(
    'uppercase word keeps case',
    '<p>HELLO WORLD</p>',
    $translator->translate('<p>HOLA MUNDO</p>', 'en', 'es')
);

$t->is(
    'numbers untouched',
    '<p>Hello world 2024 100% 3,50</p>',
    $translator->translate('<p>Hola mundo 2024 100% 3,50</p>', 'en', 'es')
);

/* ------------------------------------------------------- protected terms */

$t->group('Protected terms');

$t->is(
    'brand kept intact',
    'Hello Medel and Ford',
    $translator->translate('Hola Medel y Ford', 'en', 'es')
);

$t->is(
    'brand inside html kept intact',
    '<a href="/ford">Ford</a> is a <b>car</b>',
    $translator->translate('<a href="/ford">Ford</a> es un <b>coche</b>', 'en', 'es')
);

$t->is(
    'case insensitive match',
    'Hello MEDEL',
    $translator->translate('Hola MEDEL', 'en', 'es')
);

$t->is(
    'longest term wins',
    'Hello WordPress',
    $translator->translate('Hola WordPress', 'en', 'es')
);

$temp = new Translator([
    'data_path' => $root . '/data',
    'cache_dir' => $cacheDir,
    'cache_driver' => 'sqlite',
    'protected_terms' => ['coche'],
]);
$t->is('term registered at runtime', 'Hello coche', $temp->translate('Hola coche', 'en', 'es'));
$t->is('previous instance unaffected', 'Hello car', $translator->translate('Hola coche', 'en', 'es'));

/* ----------------------------------------------------------------- cache */

$t->group('Cache');

$cacheTranslator = new Translator([
    'data_path' => $root . '/data',
    'cache_dir' => $cacheDir,
    'cache_driver' => 'sqlite',
]);
$cacheTranslator->clearCache();

$first = $cacheTranslator->translate('Hola mundo cacheado', 'en', 'es');
$start = microtime(true);
$second = $cacheTranslator->translate('Hola mundo cacheado', 'en', 'es');
$elapsed = (microtime(true) - $start) * 1000;

$t->is('stable output', $first, $second);
$t->true('second call is a cache hit', $cacheTranslator->stats()['counters']['cache_hits'] >= 1);
$t->true('cached call under 5ms', $elapsed < 5);
$t->true('sqlite driver active', $cacheTranslator->cache()->driver() === 'sqlite');

$jsonTranslator = new Translator([
    'data_path' => $root . '/data',
    'cache_dir' => $cacheDir,
    'cache_driver' => 'json',
]);
$jsonTranslator->clearCache();
$t->is('json driver active', 'json', $jsonTranslator->cache()->driver());
$jsonTranslator->translate('Hola mundo json', 'en', 'es');
$t->true('json entry counted in memory', $jsonTranslator->cache()->count() > 0);
$t->true('json writes are buffered', !is_file($cacheDir . '/translations.json'));
$t->is('json cached output', 'Hello world json', $jsonTranslator->translate('Hola mundo json', 'en', 'es'));
$jsonTranslator->commitCache();
$t->true('json file created after commit', is_file($cacheDir . '/translations.json'));

$noCache = new Translator([
    'data_path' => $root . '/data',
    'cache' => false,
]);
$t->is('cache disabled still translates', 'hello world', $noCache->translate('hola mundo', 'en', 'es'));

/* ------------------------------------------------------------------ batch */

$t->group('Batches');

$batch = $translator->translateBatch([
    'title' => 'hola mundo',
    'nested' => ['cta' => 'iniciar sesion', 'keep' => 42],
    'numeric' => 7,
], 'en', 'es');

$t->is('batch string', 'hello world', $batch['title']);
$t->is('batch nested', 'log in', $batch['nested']['cta']);
$t->is('batch keeps ints', 42, $batch['nested']['keep']);
$t->is('batch keeps non strings', 7, $batch['numeric']);

/* ------------------------------------------------------------------ pivot */

$t->group('Pivot language');

$pivotDir = $cacheDir . '/pivot-data';
if (!is_dir($pivotDir)) {
    mkdir($pivotDir, 0775, true);
}

file_put_contents($pivotDir . '/es_en.json', json_encode([
    'from' => 'es',
    'to' => 'en',
    'phrases' => ['hola mundo' => 'hello world', 'adios amigo' => 'bye friend'],
    'words' => ['mundo' => 'world', 'amigo' => 'friend'],
], JSON_UNESCAPED_UNICODE));

file_put_contents($pivotDir . '/en_it.json', json_encode([
    'from' => 'en',
    'to' => 'it',
    'phrases' => ['hello world' => 'ciao mondo', 'bye friend' => 'arrivederci amico'],
    'words' => ['world' => 'mondo', 'friend' => 'amico'],
], JSON_UNESCAPED_UNICODE));

$pivot = new Translator([
    'data_path' => $pivotDir,
    'cache_dir' => $cacheDir,
    'cache_driver' => 'sqlite',
    'pivot' => 'en',
]);

$t->true('no direct table for es>it', count($pivot->route('es', 'it')) === 2);
$t->true('pair is reachable through the pivot', $pivot->isTranslatable('es', 'it'));
$t->is(
    'route uses the pivot',
    'es>en,en>it',
    implode(',', array_map(static function (array $leg): string {
        return $leg['from'] . '>' . $leg['to'];
    }, $pivot->route('es', 'it')))
);
$t->is('pivoted translation', 'Ciao mondo', ucfirst(strtolower($pivot->translate('Hola mundo', 'it', 'es'))));
$t->is('direct pair still direct', 'Ciao mondo', ucfirst(strtolower($pivot->translate('Hello world', 'it', 'en'))));
$t->is('unreachable pair untouched', 'klingon text', $pivot->translate('klingon text', 'it', 'es'));

$t->group('Alternative pivot');

$alt = new Translator([
    'data_path' => $pivotDir,
    'cache_dir' => $cacheDir,
    'cache_driver' => 'sqlite',
    'pivot' => 'it',
]);
$t->is('pivot can be changed', [], $alt->route('es', 'it'));

/* ---------------------------------------------------------- dictionary IO */

$t->group('Dictionary loading');

$pairs = $translator->availablePairs();
$t->true('pairs discovered', count($pairs) >= 4);
$t->true('languages discovered', in_array('de', $translator->availableLanguages(), true));

$result = $translator->result('Hola mundo', 'en', 'es');
$t->true('result exposes the route', $result->routeAsString() === 'es>en');
$t->true('result reports a match', $result->matched());
$t->true('result coverage', $result->coverage() > 0.5);

$miss = $translator->result('qwerty zxcvb', 'en', 'es');
$t->false('miss reported', $miss->matched());

$t->true('has() finds a phrase', $translator->has('hola mundo', 'es', 'en'));
$t->false('has() rejects unknown', $translator->has('qwerty zxcvb', 'es', 'en'));

/* --------------------------------------------------------- any language */

$t->group('Every language combination');

$shipped = ['es', 'en', 'de', 'fr', 'it', 'pt', 'ja', 'ar', 'ru'];
$matrix = $translator->combinationMatrix();

foreach ($shipped as $language) {
    $t->true('language available: ' . $language, in_array($language, $translator->availableLanguages(), true));
}

$checked = 0;
$reachable = 0;
foreach ($shipped as $from) {
    foreach ($shipped as $to) {
        if ($from === $to) {
            continue;
        }
        $checked++;
        if (isset($matrix[$from][$to])) {
            $reachable++;
        }
    }
}

$t->is('every ordered pair is reachable', $checked, $reachable);
$t->is('nine languages x eight targets', 72, $checked);

$t->is('matrix shape', 9, count($matrix));
$t->true('matrix marks direct tables', ($matrix['es']['en'] ?? 0) === 1);
$t->true('matrix marks pivoted pairs', ($matrix['es']['fr'] ?? 0) === 2);
$t->true('matrix reaches Japanese', ($matrix['es']['ja'] ?? 0) === 2);
$t->true('matrix reaches Arabic', ($matrix['es']['ar'] ?? 0) === 2);
$t->true('matrix reaches Russian', ($matrix['es']['ru'] ?? 0) === 2);
$t->false('matrix omits unknown languages', isset($matrix['zh']));
$t->true('languagesFor() lists the targets', in_array('pt', $translator->languagesFor('es'), true));
$t->is('languagesFor() on unsupported language', [], $translator->languagesFor('zh'));
$realTranslations = [
    ['es', 'en', 'Hola mundo', 'Hello world'],
    ['en', 'es', 'Hello world', 'Hola mundo'],
    ['es', 'de', 'Hola mundo', 'Hallo Welt'],
    ['en', 'de', 'Hello world', 'Hallo Welt'],
    ['de', 'en', 'Hallo Welt', 'Hello world'],
    ['en', 'fr', 'Hello world', 'Bonjour le monde'],
    ['es', 'fr', 'Hola mundo', 'Bonjour le monde'],
    ['fr', 'it', 'Bonjour le monde', 'Ciao mondo'],
    ['it', 'pt', 'Ciao mondo', 'Olá mundo'],
    ['pt', 'es', 'Olá mundo', 'Hola mundo'],
    ['de', 'it', 'Hallo Welt', 'Ciao mondo'],
];

foreach ($realTranslations as $case) {
    [$from, $to, $input, $expected] = $case;
    $t->is(
        sprintf('%s -> %s ("%s")', $from, $to, $input),
        $expected,
        $translator->translate($input, $to, $from)
    );
}

$t->group('Accent disambiguation');

$t->is('accented key still matches', 'Tomorrow', $translator->translate('Mañana', 'en', 'es'));
$t->is('italian "e" (and) is not "è" (is)', 'is', $translator->translate('è', 'en', 'it'));
$t->is('italian "e" (and)', 'and', $translator->translate('e', 'en', 'it'));
$t->is('accent preserved in output', 'Añadir al carrito', $translator->translate('Add to cart', 'es', 'en'));
$t->is('german nouns capitalised', 'Hallo Welt', $translator->translate('Hello world', 'de', 'en'));
$t->is('german articles dropped when pivoting', 'Ciao mondo', $translator->translate('Hallo Welt', 'it', 'de'));
$t->is('phrase keeps the case of the whole span', 'HTTPS aktiviert', $translator->translate('HTTPS habilitado', 'de', 'es'));

$t->group('Spacer paragraphs');

$spacerDoc = '<p>Hola mundo</p><p>&nbsp;</p><h3>Titulo</h3><p>Adios amigo</p>';
$noSpacers = new Translator([
    'data_path' => $root . '/data',
    'cache_dir' => $cacheDir,
    'cache' => false,
]);
$withSpacers = new Translator([
    'data_path' => $root . '/data',
    'cache_dir' => $cacheDir,
    'cache' => false,
    'empty_paragraphs' => 'br',
]);

$t->is('spacers kept by default', '<p>Hello world</p><p>&nbsp;</p><h3>Title</h3><p>Goodbye friend</p>', $noSpacers->translate($spacerDoc, 'en', 'es'));
$t->is('spacers become line breaks', '<p>Hello world</p><br><h3>Title</h3><p>Goodbye friend</p>', $withSpacers->translate($spacerDoc, 'en', 'es'));
$t->is('spacers can be removed', '<p>Hello world</p><h3>Title</h3><p>Goodbye friend</p>', $noSpacers->translate($spacerDoc, 'en', 'es', ['empty_paragraphs' => 'remove']));
$t->is('option can be per call', '<p>Hello world</p><br><h3>Title</h3>', $noSpacers->translate('<p>Hola mundo</p><p>&nbsp;</p><h3>Titulo</h3>', 'en', 'es', ['empty_paragraphs' => true]));

$variants = '<p>&nbsp;</p><p>&#160;</p><p> </p><p class="sp"></p><p>&nbsp;&nbsp;</p>';
$t->is('every spacer shape detected', '<br><br><br><br><br>', $withSpacers->translate($variants, 'en', 'es'));
$t->is('paragraph with content untouched', '<p>&nbsp;<b>x</b></p>', $withSpacers->translate('<p>&nbsp;<b>x</b></p>', 'en', 'es'));
$t->is(
    'spacers inside <pre> are literal markup',
    '<pre><p>&nbsp;</p></pre>',
    $withSpacers->translate('<pre><p>&nbsp;</p></pre>', 'en', 'es')
);

$t->group('Emojis');

$emoji = '<h3>&#128421; Servidor</h3><p>&#128024; PHP 8.2 &#9989;</p><p>🖥️ Emoji literal 🚀🎉 🧩🔐</p>';
$expectedEmoji = '<h3>&#128421; Server</h3><p>&#128024; PHP 8.2 &#9989;</p><p>🖥️ Emoji literal 🚀🎉 🧩🔐</p>';
$t->is('emojis survive translation (entities and literals)', $expectedEmoji, $noSpacers->translate($emoji, 'en', 'es'));
$t->is('emojis survive the spacer pass', $expectedEmoji, $withSpacers->translate($emoji, 'en', 'es'));
$t->is('emoji inside a text node is kept', 'Hello world 🎉', $noSpacers->translate('Hola mundo 🎉', 'en', 'es'));

$t->group('Structural invariance');

/**
 * Everything that must never change: tags, attributes, entities, emojis.
 *
 * @return array{0:array<int,string>,1:array<int,string>,2:array<int,string>}
 */
$fingerprint = static function (string $html): array {
    preg_match_all('/<[^>]+>/', $html, $tags);
    preg_match_all('/&(?:#[0-9]+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);/', $html, $entities);
    preg_match_all('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $html, $emojis);

    return [$tags[0], $entities[0], $emojis[0]];
};

$rich = "Te has quedado pillado o habias acabado todo?\n"
    . "<p>Antes de instalar RedactSEO, aseg&uacute;rate de que tu servidor cumple con los siguientes requisitos m&iacute;nimos.</p>\n"
    . "<p>&nbsp;</p>\n"
    . "<h3>🖥️ Requisitos del servidor</h3>\n"
    . "<ul>\n<li>\n<p>🐘 <strong>PHP 8.2 o superior</strong></p>\n</li>\n"
    . "<li>\n<p>✅ Compatible con <strong>PHP 8.4</strong></p>\n</li>\n</ul>\n"
    . "<hr>\n"
    . "<table border=\"1\"><tr><td><code>curl</code></td><td>Comunicaci&oacute;n</td></tr></table>\n"
    . "<pre><code>// Hola mundo\n$a = 1;</code></pre>\n"
    . "<p><strong>Versi&oacute;n m&iacute;nima:</strong> 8.2<br><strong>Nota:</strong> PHP 8.2 &ndash; 8.4</p>";

$pristine = new Translator([
    'data_path' => $root . '/data',
    'cache_dir' => $cacheDir,
    'protected_terms' => ['RedactSEO'],
]);

foreach (['de', 'en', 'es', 'fr', 'it', 'pt'] as $lang) {
    $out = $pristine->translate($rich, $lang, 'es');
    [$tags, $entities, $emojis] = $fingerprint($out);
    [$expectedTags, $expectedEntities, $expectedEmojis] = $fingerprint($rich);

    $t->is($lang . ': tags identical', $expectedTags, $tags);
    $t->is($lang . ': no invented entities', [], array_diff($entities, $expectedEntities));
    $t->is($lang . ': emojis identical', $expectedEmojis, $emojis);
}

$spacers = $pristine->translate($rich, 'de', 'es', ['empty_paragraphs' => 'br']);
$t->is('spacer pass only changes the spacers', 1, substr_count($spacers, '<br>') - substr_count($rich, '<br>'));
$t->false('spacer pass keeps the html table', strpos($spacers, '<table border="1">') === false);
$t->true('spacer pass keeps the html table (present)', strpos($spacers, '<table border="1">') !== false);
$t->true('spacer pass keeps the pre block', strpos($spacers, "// Hola mundo") !== false);
$t->true('spacer pass keeps unknown entities', strpos($pristine->translate('Zamb&oacute;a', 'en', 'es', ['empty_paragraphs' => 'br']), 'Zamb&oacute;a') !== false);

$t->group('Plain text mixed with HTML');

$mixed = $pristine->translate('Hola mundo <b>Adios amigo</b> y <a href="/x" title="Inicio">Iniciar sesion</a>', 'en', 'es');
$t->is('plain text and inline html in one string', 'Hello world <b>Goodbye friend</b> and <a href="/x" title="Inicio">Log in</a>', $mixed);

$t->group('Minimum coverage');
$sparse = 'zzz qqq www xxx vvv';
$t->is('default threshold is 0.5', 0.5, $translator->minCoverage());
$t->is('unknown text untouched', $sparse, $translator->translate($sparse, 'en', 'es'));

$partial = 'Hola qqq zzz www mundo';
$loose = $translator->setMinCoverage(0.0);
$t->is('coverage 0 translates what it knows', 'Hello qqq zzz www world', $loose->translate($partial, 'en', 'es'));
$loose->setMinCoverage(0.9);
$t->is('high coverage keeps the original', $partial, $loose->translate($partial, 'en', 'es'));
$t->is('per call override', $partial, $loose->translate($partial, 'en', 'es', ['min_coverage' => 0.9]));
$loose->setMinCoverage(0.5);
$t->is('full hit still accepted', 'Hello world', $loose->translate('Hola mundo', 'en', 'es'));

/* ------------------------------------------------------------------ keep */

$t->group('Keep terms');

$brands = ['Tokify', 'SaaS', 'API'];
$spanPhrase = 'Adecuado para proyectos personales empresas saas y uso comercial';

$t->is(
    'phrase may span a keep term',
    'Geeignet für persönliche Projekte, SaaS-Unternehmen und kommerzielle Nutzung',
    $translator->translate($spanPhrase, 'de', 'es', ['keep' => $brands])
);

$t->is(
    'keep terms are copied verbatim',
    'Geeignet für persönliche Projekte, SaaS-Unternehmen und kommerzielle Nutzung',
    $translator->translate($spanPhrase, 'de', 'es')
);

$t->is(
    'protected terms stay verbatim',
    'Stripe API REST',
    $translator->translate('Stripe API REST', 'de', 'es', ['protected_terms' => $brands])
);

// The sentence around a protected term is still translated word by word, but
// no dictionary phrase may swallow the masked term.
$protected = $translator->translate($spanPhrase, 'de', 'es', ['protected_terms' => $brands]);
$t->is('protected term is still there', true, strpos($protected, 'saas') !== false);
$t->is('no phrase replaces a protected term', false, strpos($protected, 'SaaS-Unternehmen') !== false);

$keeper = new Translator(['data_path' => $root . '/data', 'cache' => false, 'keep' => ['SaaS']]);
$t->is('keep can be set on the constructor', ['SaaS'], $keeper->keepTerms());
$t->is('addKeepTerm returns the instance', $keeper, $keeper->addKeepTerm('Pexels'));
$t->is('keep terms accumulate', ['SaaS', 'Pexels'], $keeper->keepTerms());
$t->is('setKeepTerms replaces the list', ['PHP'], $keeper->setKeepTerms(['PHP'])->keepTerms());

/* ------------------------------------------------------- pivot fallback */

$t->group('Pivot fallback');

// Spanish known to the hub but not to the French table: the first leg has
// already done the work when the second one gives up.
$fragment = 'con php actualizado';

$t->is('default keeps the source', 'source', $translator->pivotFallback());
$t->is(
    'source is returned when the last leg is weak',
    $fragment,
    $translator->translate($fragment, 'fr', 'es')
);
$t->is(
    'last-leg keeps the work already done',
    'avec up-à-date PHP',
    $translator->translate($fragment, 'fr', 'es', ['pivot_fallback' => 'last-leg'])
);

$graceful = new Translator([
    'data_path' => $root . '/data',
    'cache' => false,
    'pivot_fallback' => 'last-leg',
]);
$t->is('pivot_fallback on the constructor', 'last-leg', $graceful->pivotFallback());
$t->is('setPivotFallback toggles it', 'source', $graceful->setPivotFallback('source')->pivotFallback());
/* ----------------------------------------------------- multi word brands */

$t->group('Multi word brands');

$plain = new Translator(['data_path' => $root . '/data', 'cache' => false]);

// Shipped in the "keep" list of the dictionaries, so no configuration needed.
$t->is('brand survives de', 'Medel Platforms', $plain->translate('Medel Platforms', 'de', 'es'));
$t->is('brand survives en', 'Medel Platforms', $plain->translate('Medel Platforms', 'en', 'es'));
$t->is('brand survives fr', 'Medel Platforms', $plain->translate('Medel Platforms', 'fr', 'es'));
$t->is('brand survives it', 'Medel Platforms', $plain->translate('Medel Platforms', 'it', 'es'));
$t->is('brand survives pt', 'Medel Platforms', $plain->translate('Medel Platforms', 'pt', 'es'));
$t->is('brand survives ja', 'Medel Platforms', $plain->translate('Medel Platforms', 'ja', 'es'));
$t->is('brand survives ar', 'Medel Platforms', $plain->translate('Medel Platforms', 'ar', 'es'));
$t->is('brand survives ru', 'Medel Platforms', $plain->translate('Medel Platforms', 'ru', 'es'));

$t->is(
    'brand inside a link',
    '<a href="https://medel.es/">Medel Platforms</a>',
    $plain->translate('<a href="https://medel.es/">Medel Platforms</a>', 'de', 'es')
);

// The rest of the sentence is still translated around it.
$t->is(
    'sentence around the brand keeps it and translates the rest',
    'Contacta with Medel Platforms today',
    $plain->translate('Contacta con Medel Platforms hoy', 'en', 'es')
);

// A brand that is not shipped can be added at runtime.
$runtime = new Translator(['data_path' => $root . '/data', 'cache' => false, 'keep' => ['Acme Cloud']]);
$t->is('runtime keep of a multi word brand', 'Acme Cloud', $runtime->translate('Acme Cloud', 'de', 'es'));
$t->is('runtime brand inside a sentence', 'Hello Acme Cloud', $runtime->translate('Hola Acme Cloud', 'en', 'es'));
$t->is('keepPhrases() lists them', ['Acme Cloud'], $runtime->keepPhrases());

/* --------------------------------------------------- invisible entities */

$t->group('Invisible entities');

$t->is(
    'zero width joiner survives translation',
    "👨&zwj;💻 Vollständiges Admin-Panel",
    $translator->translate("👨&zwj;💻 Panel de administración completo", 'de', 'es')
);

$t->is(
    'emoji sequence is not swallowed by a phrase',
    "🔎 Fortgeschrittenes SEO-System",
    $translator->translate("🔎 Sistema SEO avanzado", 'de', 'es')
);

$t->is(
    'soft hyphen keeps the word translatable',
    'Tomorrow is tomorrow',
    $translator->translate("Mañana es ma&shy;nana", 'en', 'es')
);
/* --------------------------------------------------- sentence level pages */

$t->group('Commercial page coverage');

$page = (string) file_get_contents($root . '/tests/fixtures/tokify.html');
$brands = [
    'Tokify', 'Stripe', 'PayPal', 'ElevenLabs', 'HeyGen', 'D-ID', 'Whisper.cpp', 'Pexels',
    'OpenAI', 'Anthropic', 'YouTube', 'TikTok', 'Instagram', 'Reddit', 'Amazon S3', 'WYSIWYG',
    'PHP', 'SQL', 'SaaS', 'API', 'REST', 'PWA', 'S3', 'SEO',
];

$pageTranslator = new Translator([
    'data_path' => $root . '/data',
    'cache' => false,
    'keep' => $brands,
    'empty_paragraphs' => 'br',
]);

foreach (['de', 'en', 'fr', 'it', 'pt'] as $language) {
    $untranslated = [];

    $pageTranslator->htmlProcessor()->translateNodes(
        $page,
        static function (string $node) use ($language, $pageTranslator, &$untranslated): string {
            $text = trim($node, " \t\n\r\0\x0B\u{00A0}");

            // Blank spacers and paths like "/docs" are not vocabulary, so
            // coming back untranslated is the right answer for them.
            if ($text === '' || Text::isPathLike($text)) {
                return $node;
            }

            if (!$pageTranslator->result($node, $language, 'es')->matched()) {
                $untranslated[] = $text;
            }

            return $node;
        }
    );

    $t->is('every visible node of the page is translated to ' . $language, [], $untranslated);
}

// A phrase must not swallow an emoji or a protected term.
$t->is(
    'hyphenated brand inside a phrase',
    'KI-Avatare mit HeyGen und D-ID',
    $pageTranslator->translate('Avatares IA con HeyGen y D-ID', 'de', 'es')
);

$t->is(
    'emoji before a phrase is preserved',
    "\u{1F4BB} Vollständiges Admin-Panel",
    $pageTranslator->translate('💻 Panel de administracion completo', 'de', 'es')
);

// The tokenizer splits "multi-moneda" and "l'application" into separate units;
// without the joiner the dictionary entry could never be found.
$joiner = DictionaryTable::fromArray([
    'from' => 'es',
    'to' => 'it',
    'phrases' => [
        'sistema multi-moneda' => 'sistema multi-valuta',
        "colores de l'interfaz" => "colori dell'interfaccia",
    ],
], 'es', 'it');

$t->is('hyphen inside a phrase', 'Sistema multi-valuta', $joiner->translate('Sistema multi-moneda')['text']);
$t->is('apostrophe inside a phrase', "Colori dell'interfaccia", $joiner->translate("Colores de l'interfaz")['text']);

// The folder /docs stays as it is: it is a path, not the word "docs".
$t->is('a path is never translated', '/docs', $pageTranslator->translate('/docs', 'de', 'es'));
$t->is('a file name is never translated', 'config.php', $pageTranslator->translate('config.php', 'de', 'es'));
/* -------------------------------------------------------------- guarantees */

$t->group('Offline guarantees');

$forbidden = ['curl_init', 'curl_exec', 'fsockopen', 'stream_context_create', 'pfsockopen', 'xmlrpc_request', 'wp_remote_get', 'file_get_contents(\'http', 'file_get_contents("http', 'fopen(\'http', 'GuzzleHttp'];
$offenders = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    foreach ($forbidden as $needle) {
        if (stripos($contents, $needle) !== false) {
            $offenders[] = $file->getFilename() . ' -> ' . $needle;
        }
    }
}
$t->is('no networking code in src', [], $offenders);

$runtimeDeps = json_decode((string) file_get_contents($root . '/composer.json'), true)['require'];
$t->is('no external composer dependencies', ['php', 'ext-json', 'ext-mbstring'], array_keys($runtimeDeps));

exit($t->summary());


