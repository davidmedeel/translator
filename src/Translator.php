<?php

declare(strict_types=1);

namespace Medel\Translator;

use Medel\Translator\Cache\CacheStore;
use Medel\Translator\Engines\LanguageRegistry;
use Medel\Translator\Engines\OfflineEngine;
use Medel\Translator\Engines\TranslationResult;
use Medel\Translator\Support\Text;

/**
 * Public entry point of the package.
 *
 * ```php
 * $translator = new Translator([
 *     'data_path'       => __DIR__ . '/data',
 *     'protected_terms' => ['Medel', 'Ford', 'WordPress'],
 * ]);
 *
 * echo $translator->translate('Hola mundo', 'en', 'es');            // Hello world
 * echo $translator->translate('<p>Hola <b>mundo</b></p>', 'en', 'es');
 * ```
 *
 * The class never performs an HTTP request: dictionaries are read from the
 * local /data folder and every repeated fragment is served from the local
 * cache (SQLite when available, JSON otherwise).
 */
final class Translator
{
    /** Bump to invalidate every cached fragment of a previous release. */
    private const CACHE_VERSION = 'translator-v1';

    /** @var OfflineEngine */
    private $engine;

    /** @var CacheStore */
    private $cache;

    /** @var HtmlProcessor */
    private $html;

    /** @var string */
    private $pivot;

    /** @var array<string,mixed> */
    private $defaults;

    /** @var array<string,int> */
    private $counters = [
        'calls' => 0,
        'html_calls' => 0,
        'segments' => 0,
        'untranslated' => 0,
        'cache_hits' => 0,
    ];

    /** @var int */
    private $optionSignature = 0;

    /**
     * Terms copied verbatim that still take part in phrase matching.
     *
     * @var array<int,string>
     */
    private $keep = [];

    /**
     * How the classic "<p>&nbsp;</p>" spacer is treated: 'keep', 'br' or
     * 'remove'. Applied only to the HTML output.
     *
     * @var string|null
     */
    private $emptyParagraphs;

    /**
     * @param array<string,mixed> $options
     *        data_path:       string|array folder(s) with the dictionaries
     *                        (defaults to the bundled /data folder)
     *        cache:           true|false|CacheStore
     *        cache_driver:    auto|sqlite|json|memory|none
     *        cache_path:      explicit cache file
     *        cache_ttl:       seconds, 0 = no expiration
     *        protected_terms: array of brands/terms that must stay untouched
     *        keep:            array of terms copied verbatim that still take part
     *                        in dictionary phrases (better than protected_terms
     *                        for brands you want translated around)
     *        placeholder:     sprintf template, default "__NOTRANSLATE_%d__"
     *        pivot:           pivot language for indirect pairs, default "en"
     *        skip_tags:       HTML tags never translated
     *        translate_skipped_tags: translate <code>, <pre>... too
     *        html:            false disables the HTML pipeline
     *        detect_source:   false requires an explicit source language
     *        exact_only:      true = only full phrase hits, no token guessing
     *        on_miss:         null|"keep"|callable(string $text, string $from, string $to)
     */
    public function __construct(array $options = [])
    {
        $this->defaults = $options;

        $this->pivot = isset($options['pivot'])
            ? (LanguageRegistry::normalize((string) $options['pivot']) ?: 'en')
            : 'en';

        $this->emptyParagraphs = self::normalizeSpacers(
            $options['empty_paragraphs'] ?? ($options['compact_spacers'] ?? 'keep')
        );

        $this->cache = $this->buildCache($options);

        $this->engine = new OfflineEngine([
            'data_path' => $this->dataPaths($options),
            'cache' => $this->cache,
            'pivot' => $this->pivot,
            'exact_only' => !empty($options['exact_only']),
            'min_coverage' => $options['min_coverage'] ?? 0.5,
            'pivot_fallback' => $options['pivot_fallback'] ?? 'source',
        ]);

        $this->keep = isset($options['keep']) && is_array($options['keep'])
            ? array_values(array_map('strval', $options['keep']))
            : [];

        $this->html = new HtmlProcessor([
            'protected_terms' => isset($options['protected_terms']) && is_array($options['protected_terms'])
                ? $options['protected_terms']
                : [],
            'keep' => $this->keep,
            'skip_tags' => isset($options['skip_tags']) ? $options['skip_tags'] : null,
            'placeholder' => isset($options['placeholder']) ? $options['placeholder'] : null,
            'translate_skipped_tags' => !empty($options['translate_skipped_tags']),
        ]);

        $this->optionSignature = (int) crc32((string) json_encode([
            self::CACHE_VERSION,
            OfflineEngine::codeSignature(),
            (string) @filemtime(__FILE__),
            (string) @filemtime(__DIR__ . '/HtmlProcessor.php'),
            $this->pivot,
            !empty($options['exact_only']),
            ($options['pivot_fallback'] ?? 'source'),
            $this->html->getProtectedTerms(),
            $this->keep,
            $options['placeholder'] ?? '__NOTRANSLATE_%d__',
            $options['skip_tags'] ?? HtmlProcessor::DEFAULT_SKIP_TAGS,
            !empty($options['translate_skipped_tags']),
            $this->emptyParagraphs,
        ]));
    }

    /**
     * Translates a plain text or an HTML fragment.
     *
     * @param array<string,mixed> $options per call overrides
     */
    public function translate(string $text, string $to, ?string $from = null, array $options = []): string
    {
        return $this->result($text, $to, $from, $options)->text();
    }

    /**
     * Same as translate() but returns the full TranslationResult: route,
     * coverage and cache flags.
     *
     * @param array<string,mixed> $options
     */
    public function result(string $text, string $to, ?string $from = null, array $options = []): TranslationResult
    {
        $this->counters['calls']++;

        $to = LanguageRegistry::normalize($to);
        $from = ($from === null || $from === '') ? null : LanguageRegistry::normalize($from);

        if ($text === '' || trim($text) === '' || $to === '') {
            return new TranslationResult($text, (string) $from, $to, [], false);
        }

        if ($from === $to) {
            return new TranslationResult($text, $to, $to, [], true, 1.0, 1, 1);
        }

        if ($from === null && !$this->option('detect_source', $options, true)) {
            return $this->miss($text, $to);
        }

        $sourceLanguage = $from ?? $this->engine->detectLanguage($text);
        if ($sourceLanguage === null) {
            return $this->miss($text, $to);
        }

        if ($sourceLanguage === $to) {
            return new TranslationResult($text, $sourceLanguage, $to, [], true, 1.0, 1, 1);
        }

        $isHtml = (bool) $this->option('html', $options, true) && $this->html->looksLikeHtml($text);

        // Per call protected terms: applied here (and not only kept in the cache
        // key), otherwise the documented option would silently do nothing.
        $perCallTerms = null;
        if (isset($options['protected_terms']) && is_array($options['protected_terms'])) {
            $previousTerms = $this->html->getProtectedTerms();
            $this->html->setProtectedTerms($options['protected_terms']);
            $perCallTerms = $previousTerms;
        }

        try {
            return $this->resultWithTerms($text, $sourceLanguage, $to, $isHtml, $options);
        } finally {
            if ($perCallTerms !== null) {
                $this->html->setProtectedTerms($perCallTerms);
            }
        }
    }

    /**
     * Body of result(), once the protected terms of the call are in place.
     *
     * @param array<string,mixed> $options
     */
    private function resultWithTerms(string $text, string $sourceLanguage, string $to, bool $isHtml, array $options): TranslationResult
    {
        $key = $this->cacheKey($text, $sourceLanguage, $to, $isHtml, $options);

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            $this->counters['cache_hits']++;

            $envelope = $this->unpack($cached);

            $hit = new TranslationResult(
                $envelope['text'],
                $sourceLanguage,
                $to,
                $envelope['route'],
                $envelope['matched'],
                $envelope['coverage']
            );
            $hit->markCached(true);

            return $hit;
        }

        if (!$isHtml) {
            $result = $this->translateFragment($text, $sourceLanguage, $to, $options);
            $this->cache->put($key, $this->pack($result));

            return $result;
        }

        $this->counters['html_calls']++;

        $nodeOptions = [];
        foreach (['skip_tags', 'translate_skipped_tags'] as $optionKey) {
            if (array_key_exists($optionKey, $options)) {
                $nodeOptions[$optionKey] = $options[$optionKey];
            }
        }

        $segments = 0;
        $matchedSegments = 0;
        $route = [];

        $html = $this->html->translateNodes(
            $text,
            function (string $node) use ($sourceLanguage, $to, $options, &$segments, &$matchedSegments, &$route): string {
                $nodeResult = $this->translateFragment($node, $sourceLanguage, $to, $options);
                $segments++;
                if ($nodeResult->matched()) {
                    $matchedSegments++;
                }
                if ($route === []) {
                    $route = $nodeResult->route();
                }

                return $nodeResult->text();
            },
            $nodeOptions
        );

        $coverage = $segments > 0 ? $matchedSegments / $segments : 0.0;

        $assembled = new TranslationResult($html, $sourceLanguage, $to, $route, $matchedSegments > 0, $coverage, $segments, $matchedSegments);

        $spacers = $this->option('empty_paragraphs', $options, $this->emptyParagraphs);
        if ($spacers !== null && $spacers !== 'keep') {
            $assembled = new TranslationResult(
                $this->html->compactEmptyParagraphs($html, $spacers === 'remove' ? '' : '<br>'),
                $sourceLanguage,
                $to,
                $route,
                $matchedSegments > 0,
                $coverage,
                $segments,
                $matchedSegments
            );
        }

        $this->cache->put($key, $this->pack($assembled));

        return $assembled;
    }

    /**
     * Serializes a result into the local cache.
     */
    private function pack(TranslationResult $result): string
    {
        return (string) json_encode([
            'text' => $result->text(),
            'route' => $result->route(),
            'matched' => $result->matched(),
            'coverage' => $result->coverage(),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Restores a cached payload (plain strings are accepted as well).
     *
     * @return array{text:string,route:array<int,array{from:string,to:string}>,matched:bool,coverage:float}
     */
    private function unpack(string $raw): array
    {
        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !array_key_exists('text', $decoded)) {
            return ['text' => $raw, 'route' => [], 'matched' => true, 'coverage' => 1.0];
        }

        return [
            'text' => (string) $decoded['text'],
            'route' => isset($decoded['route']) && is_array($decoded['route']) ? $decoded['route'] : [],
            'matched' => !isset($decoded['matched']) || (bool) $decoded['matched'],
            'coverage' => isset($decoded['coverage']) ? (float) $decoded['coverage'] : 0.0,
        ];
    }

    /**
     * Translates an HTML fragment, forcing the HTML pipeline.
     *
     * @param array<string,mixed> $options
     */
    public function translateHtml(string $html, string $to, ?string $from = null, array $options = []): string
    {
        $options['html'] = true;

        return $this->translate($html, $to, $from, $options);
    }

    /**
     * Translates a whole array (recursively) keeping keys and non string values.
     *
     * @param array<mixed>        $items
     * @param array<string,mixed> $options
     *
     * @return array<mixed>
     */
    public function translateBatch(array $items, string $to, ?string $from = null, array $options = []): array
    {
        $out = [];

        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->translateBatch($value, $to, $from, $options);
                continue;
            }

            $out[$key] = is_string($value) ? $this->translate($value, $to, $from, $options) : $value;
        }

        return $out;
    }

    /**
     * Alias of translateBatch() for call sites that read better this way.
     *
     * @param array<mixed>        $items
     * @param array<string,mixed> $options
     *
     * @return array<mixed>
     */
    public function translateArray(array $items, string $to, ?string $from = null, array $options = []): array
    {
        return $this->translateBatch($items, $to, $from, $options);
    }

    /**
     * Registers brands / reserved words that must never be translated.
     *
     * @param array<int,string> $terms
     */
    public function setProtectedTerms(array $terms): self
    {
        $this->html->setProtectedTerms($terms);
        $this->optionSignature = (int) crc32($this->optionSignature . '|' . implode(',', $this->html->getProtectedTerms()));

        return $this;
    }

    /**
     * @param array<int,string> $terms
     */
    public function protect(array $terms): self
    {
        return $this->setProtectedTerms($terms);
    }

    public function addProtectedTerm(string $term): self
    {
        $this->html->addProtectedTerm($term);
        $this->optionSignature = (int) crc32($this->optionSignature . '|' . $term);

        return $this;
    }

    /**
     * @return array<int,string>
     */
    public function protectedTerms(): array
    {
        return $this->html->getProtectedTerms();
    }

    /**
     * Registers terms copied verbatim that still take part in phrase matching.
     *
     * Unlike protected terms, a "keep" term is not replaced by a placeholder,
     * so a dictionary phrase may span it. Use it for brands and acronyms that
     * appear inside sentences ("Instala Tokify en 3 dominios"), and protected
     * terms for isolated fragments that must never be touched.
     *
     * @param array<int,string> $terms
     */
    public function setKeepTerms(array $terms): self
    {
        $this->keep = array_values(array_map('strval', $terms));
        $this->html->setKeepPhrases($this->keep);
        $this->optionSignature = (int) crc32($this->optionSignature . '|keep:' . implode(',', $this->keep));

        return $this;
    }

    /**
     * Multi word keep terms currently masked, i.e. the brands that a dictionary
     * phrase is not allowed to cross.
     *
     * @return array<int,string>
     */
    public function keepPhrases(): array
    {
        return $this->html->getKeepPhrases();
    }

    /**
     * @param array<int,string> $terms
     */
    public function keep(array $terms): self
    {
        return $this->setKeepTerms($terms);
    }

    public function addKeepTerm(string $term): self
    {
        return $this->setKeepTerms(array_merge($this->keep, [$term]));
    }

    /**
     * @return array<int,string>
     */
    public function keepTerms(): array
    {
        return $this->keep;
    }

    /**
     * Translates a single string using extra protected terms, restoring the
     * previous list afterwards.
     *
     * @param array<int,string>  $terms
     * @param array<string,mixed> $options
     */
    public function translateWithTerms(string $text, string $to, ?string $from, array $terms, array $options = []): string
    {
        return $this->withProcessorTerms($terms, function () use ($text, $to, $from, $options): string {
            return $this->translate($text, $to, $from, $options);
        });
    }

    /**
     * True when a dictionary (direct or pivoted) can serve the pair.
     */
    public function isTranslatable(string $from, string $to): bool
    {
        return $this->engine->isTranslatable($from, $to);
    }

    /**
     * True when the fragment exists as a phrase in the route tables.
     */
    public function has(string $text, string $from, string $to): bool
    {
        return $this->engine->has($text, $from, $to);
    }

    /**
     * Guesses the language of a fragment.
     */
    public function detectLanguage(string $text): ?string
    {
        return $this->engine->detectLanguage($text);
    }

    /**
     * Route used for a pair: [es=>en, en=>de] for a pivoted translation.
     *
     * @return array<int,array{from:string,to:string}>
     */
    public function route(string $from, string $to): array
    {
        return $this->engine->route($from, $to);
    }

    /**
     * Every dictionary pair found in the data folders.
     *
     * @return array<int,array{from:string,to:string,entries:int,path:string}>
     */
    public function availablePairs(): array
    {
        return $this->engine->pairs();
    }

/**
 * @return array<int,string>
 */
    public function availableLanguages(): array
    {
        return $this->engine->languages();
    }

    /**
     * Which ordered combinations are available right now.
     *
     * The value is the number of legs of the route (1 = direct dictionary,
     * 2 = through the pivot language). A missing key means "no disponible",
     * which is exactly what a language picker needs to grey out.
     *
     * ```php
     * $matrix = $translator->combinationMatrix();
     * $matrix['es']['ja'] ?? null;   // null = combinación no disponible
     * ```
     *
     * @return array<string,array<string,int>>
     */
    public function combinationMatrix(): array
    {
        return $this->engine->matrix();
    }

    /**
     * Languages that can be used as source for the given target (and vice
     * versa), useful to populate the two selects of a language switcher.
     *
     * @return array<int,string>
     */
    public function languagesFor(string $language): array
    {
        $language = LanguageRegistry::normalize($language);
        $row = $this->engine->matrix();

        return array_keys($row[$language] ?? []);
    }

    public function pivot(): string
    {
        return $this->pivot;
    }

    /**
     * Minimum share of tokens a fragment must translate to be accepted.
     *
     * 0 = translate whatever the dictionary knows (useful for a first look);
     * 0.5 (default) = below half the words known, keep the original;
     * 1 = only accept complete matches.
     */
    public function minCoverage(): float
    {
        return $this->engine->minCoverage();
    }

    /**
     * What a pivot route returns when it lands below the coverage threshold.
     *
     * 'source'   (default) the untouched original;
     * 'last-leg' whatever the last leg managed to translate, usually the hub.
     */
    public function pivotFallback(): string
    {
        return $this->engine->pivotFallback();
    }

    public function setPivotFallback(string $mode): self
    {
        $this->engine->setPivotFallback($mode);
        $this->optionSignature = (int) crc32($this->optionSignature . '|fb:' . $this->engine->pivotFallback());

        return $this;
    }

    public function setMinCoverage($coverage): self
    {
        $this->engine->setMinCoverage($coverage);
        $this->optionSignature = (int) crc32($this->optionSignature . '|cov:' . $this->minCoverage());

        return $this;
    }

    public function setPivot(string $code): self
    {
        $this->pivot = LanguageRegistry::normalize($code) ?: 'en';
        $this->engine->setPivot($this->pivot);
        $this->optionSignature = (int) crc32($this->optionSignature . '|pivot:' . $this->pivot);

        return $this;
    }

    public function cache(): CacheStore
    {
        return $this->cache;
    }

    public function engine(): OfflineEngine
    {
        return $this->engine;
    }

    public function htmlProcessor(): HtmlProcessor
    {
        return $this->html;
    }

    /**
     * Folders scanned looking for dictionaries.
     *
     * @return array<int,string>
     */
    public function dataFolders(): array
    {
        return $this->engine->stats()['data_paths'];
    }

    /**
     * Forces the buffered cache writes to disk (only the JSON backend buffers).
     */
    public function commitCache(): self
    {
        $this->cache->commit();

        return $this;
    }

    /**
     * Empties the local cache.
     */

    public function clearCache(): self
    {
        $this->cache->flush();

        return $this;
    }

    public function pruneCache(): int
    {
        return $this->cache->prune();
    }

    /**
     * @return array<string,mixed>
     */
    public function stats(): array
    {
        return [
            'counters' => $this->counters,
            'cache' => $this->cache->stats(),
            'engine' => $this->engine->stats(),
            'protected_terms' => $this->html->getProtectedTerms(),
            'pivot' => $this->pivot,
        ];
    }

    /**
     * Default folder of the bundled dictionaries.
     */
    public static function defaultDataPath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    }

    /**
     * Applies the whole pipeline (cache, protected terms, engine) to one text
     * node or plain fragment.
     *
     * @param array<string,mixed> $options
     */
    private function translateFragment(string $text, string $sourceLanguage, string $to, array $options): TranslationResult
    {
        $this->counters['segments']++;

        $key = $this->cacheKey($text, $sourceLanguage, $to, false, $options);

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            $this->counters['cache_hits']++;

            $envelope = $this->unpack($cached);

            $hit = new TranslationResult(
                $envelope['text'],
                $sourceLanguage,
                $to,
                $envelope['route'],
                $envelope['matched'],
                $envelope['coverage']
            );
            $hit->markCached(true);

            return $hit;
        }

        $entityMap = [];
        $masked = Text::maskEntities($text, $entityMap);
        $protected = $this->html->protect($masked);

        $previousCoverage = $this->engine->minCoverage();

        if (array_key_exists('min_coverage', $options)) {
            $this->engine->setMinCoverage($options['min_coverage']);
        }

        $previousFallback = null;
        if (isset($options['pivot_fallback'])) {
            $previousFallback = $this->engine->pivotFallback();
            $this->engine->setPivotFallback((string) $options['pivot_fallback']);
        }

        $engineOptions = [];

        $keep = $this->keep;

        if (isset($options['keep']) && is_array($options['keep'])) {
            $keep = array_merge($keep, array_values($options['keep']));
        }

        if ($keep !== []) {
            $engineOptions['keep'] = $keep;

            // Multi word brands are masked, so they survive byte exact.
            $this->html->setKeepPhrases($keep);
        }

        $engineResult = $this->engine->translate($protected['text'], $sourceLanguage, $to, $engineOptions);

        $this->engine->setMinCoverage($previousCoverage);

        if ($previousFallback !== null) {
            $this->engine->setPivotFallback($previousFallback);
        }

        if (!$engineResult->matched()) {
            $this->counters['untranslated']++;

            $result = new TranslationResult($text, $sourceLanguage, $to, [], false);
        } else {
            $translated = $this->html->restore($engineResult->text(), $protected['map']);
            $translated = Text::unmaskEntities($translated, $entityMap);

            $result = new TranslationResult(
                $translated,
                $sourceLanguage,
                $to,
                $engineResult->route(),
                true,
                $engineResult->coverage()
            );
        }

        $onMiss = $options['on_miss'] ?? ($this->defaults['on_miss'] ?? null);
        if (is_callable($onMiss) && !$result->matched()) {
            $handled = (string) $onMiss($text, $sourceLanguage, $to);
            if ($handled !== '') {
                $result = new TranslationResult($handled, $sourceLanguage, $to, [], true);
            }
        }

        $this->cache->put($key, $this->pack($result));

        return $result;
    }

    /**
     * A miss keeps the original fragment untouched.
     */
    private function miss(string $text, string $to): TranslationResult
    {
        $this->counters['untranslated']++;

        return new TranslationResult($text, '', $to, [], false);
    }

    /**
     * Cache key: invalidated automatically when a dictionary file is touched.
     *
     * @param array<string,mixed> $options
     */
    private function cacheKey(string $text, string $from, string $to, bool $isHtml, array $options): string
    {
        $signature = '';

        foreach ($this->engine->route($from, $to) as $leg) {
            $signature .= $leg['from'] . '>' . $leg['to'] . '@' . $this->engine->signature($leg['from'], $leg['to']) . ';';
        }

        $terms = isset($options['protected_terms']) && is_array($options['protected_terms'])
            ? implode(',', $options['protected_terms'])
            : '';

        // Every option that can change the OUTPUT must be part of the key.
        $perCall = [];
        foreach (['html', 'min_coverage', 'empty_paragraphs', 'skip_tags', 'translate_skipped_tags', 'detect_source', 'exact_only', 'keep', 'pivot_fallback'] as $optionKey) {
            if (array_key_exists($optionKey, $options)) {
                $perCall[] = $optionKey . '=' . json_encode($options[$optionKey]);
            }
        }

        return Text::fingerprint(
            self::CACHE_VERSION,
            (string) $this->optionSignature,
            $signature,
            $isHtml ? 'html' : 'text',
            $terms,
            implode('|', $perCall),
            $from,
            $to,
            $text
        );
    }

    /**
     * @param array<string,mixed> $options
     * @param mixed               $default
     *
     * @return mixed
     */
    private function option(string $key, array $options, $default)
    {
        return array_key_exists($key, $options) ? $options[$key] : $default;
    }

    /**
     * @param array<int,string> $terms
     */
    private function withProcessorTerms(array $terms, callable $callback): string
    {
        $previous = $this->html->getProtectedTerms();
        $this->html->setProtectedTerms($terms);

        try {
            return (string) $callback();
        } finally {
            $this->html->setProtectedTerms($previous);
        }
    }

    /**
     * @param array<string,mixed> $options
     */
    private function buildCache(array $options): CacheStore
    {
        if (isset($options['cache']) && $options['cache'] instanceof CacheStore) {
            return $options['cache'];
        }

        if (array_key_exists('cache', $options) && $options['cache'] === false) {
            return new CacheStore(['enabled' => false]);
        }

        $cacheOptions = [];
        $map = [
            'cache_driver' => 'driver',
            'cache_path' => 'path',
            'cache_dir' => 'directory',
            'cache_ttl' => 'ttl',
        ];

        foreach ($map as $optionKey => $cacheKey) {
            if (array_key_exists($optionKey, $options)) {
                $cacheOptions[$cacheKey] = $options[$optionKey];
            }
        }

        if (array_key_exists('cache', $options) && is_bool($options['cache'])) {
            $cacheOptions['enabled'] = $options['cache'];
        }

        return new CacheStore($cacheOptions);
    }

/**
 * Accepts true/'br'/'remove'/false/'keep' and returns 'br', 'remove' or null.
 */
    private static function normalizeSpacers($value): ?string
    {
        if ($value === true) {
            return 'br';
        }

        if ($value === false || $value === null || $value === '' || $value === 'keep') {
            return null;
        }

        return $value === 'remove' ? 'remove' : 'br';
    }

    /**
     * @param array<string,mixed> $options
     *
     * @return array<int,string>
 */
    private function dataPaths(array $options): array
    {
        $paths = null;

        if (isset($options['data_path'])) {
            $paths = $options['data_path'];
        } elseif (isset($options['data_paths'])) {
            $paths = $options['data_paths'];
        }

        if ($paths === null) {
            return [self::defaultDataPath()];
        }

        $paths = is_array($paths) ? $paths : [$paths];
        $paths = array_values(array_filter(array_map('strval', $paths), static function (string $path): bool {
            return trim($path) !== '';
        }));

        return $paths === [] ? [self::defaultDataPath()] : $paths;
    }
}