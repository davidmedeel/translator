<?php

declare(strict_types=1);

namespace Medel\Translator\Engines;

use Medel\Translator\Cache\CacheStore;
use Medel\Translator\Support\Text;
use PDO;
use PDOException;

/**
 * The offline engine.
 *
 * Responsibilities:
 *  - locate the dictionaries of /data/ (JSON, or SQLite when available);
 *  - load and index them lazily, memoized per process;
 *  - resolve any language pair, falling back to the pivot language
 *    (es => ja becomes es => en => ja) when no direct table exists;
 *  - answer every lookup from the local cache when possible.
 *
 * Hard guarantee: this class performs local filesystem reads only. There is no
 * curl, no stream context, no socket and no remote endpoint anywhere in the
 * package.
 */
final class OfflineEngine
{
    /** Cache format version: bump it to invalidate old entries. */
    private const CACHE_VERSION = 'v1';

    /**
     * Files whose changes must invalidate cached fragments.
     */
    private const CODE_FILES = [
        '/Engines/DictionaryTable.php',
        '/Engines/OfflineEngine.php',
        '/Support/Text.php',
    ];

    /** @var array<int,string> */
    private $directories = [];

    /** @var string */
    private $pivot = 'en';

    /** @var bool */
    private $exactOnly = false;

    /**
     * Minimum share of translated tokens required to accept a result.
     *
     * Below this threshold the fragment is returned untouched: a half
     * translated sentence ("Extensiones de PHP necesarias" -> "Durchwahle von
     * PHP necesarias") is worse than the original, which a human can still
     * read.
     *
     * @var float
     */
    private $minCoverage = 0.5;

    /**
     * What to return when a pivot route ends up below the coverage threshold:
     * 'source' keeps the untouched original, 'last-leg' keeps whatever the last
     * leg managed to translate (usually the hub language).
     *
     * @var string
     */
    private $pivotFallback = 'source';

    /**
     * Reject a long fragment that no phrase covered.
     *
     * Word by word translation of prose is never right, so the original is
     * returned instead. Disable it with 'prose_guard' => false to translate
     * everything the dictionary knows, whatever the result looks like.
     *
     * @var bool
     */
    private $requirePhraseCoverage = true;

    /** Minimum number of tokens for the guard to apply. */
    private $minPhraseTokens = 8;

    /** Minimum share of tokens that a phrase must cover (0.25 = a quarter). */
    private $minPhraseRatio = 0.25;

    /** @var CacheStore|null */
    private $cache;

    /** @var array<string,DictionaryTable|null> */
    private $tables = [];

    /** @var array<string,array<string,mixed>|null> */
    private $payloads = [];

    /** @var array<string,int> */
    private $signatures = [];

    /** @var array<int,array{from:string,to:string,path:string}>|null */
    private $discovered = null;

    /** @var array<string,int> */
    private $stats = [
        'requests' => 0,
        'segments' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'untranslated' => 0,
        'files_loaded' => 0,
    ];

    /**
     * @param array<string,mixed> $options
     *        data_path:  string|array  folder(s) holding the dictionaries
     *        cache:      CacheStore|null
     *        pivot:      pivot language code (default "en")
     *        exact_only: only accept full phrase hits, never token guesses
     */
    public function __construct(array $options = [])
    {
        $paths = [];
        if (isset($options['data_path'])) {
            $paths = is_array($options['data_path']) ? $options['data_path'] : [$options['data_path']];
        } elseif (isset($options['data_paths'])) {
            $paths = is_array($options['data_paths']) ? $options['data_paths'] : [$options['data_paths']];
        }

        foreach ($paths as $path) {
            $path = (string) $path;
            if ($path !== '') {
                $this->directories[] = rtrim($path, "/\\");
            }
        }

        if (isset($options['pivot'])) {
            $this->pivot = LanguageRegistry::normalize((string) $options['pivot']) ?: 'en';
        }

        if (isset($options['min_coverage'])) {
            $this->minCoverage = self::clampCoverage($options['min_coverage']);
        }

        if (isset($options['pivot_fallback'])) {
            $this->pivotFallback = $options['pivot_fallback'] === 'last-leg' ? 'last-leg' : 'source';
        }

        if (array_key_exists('prose_guard', $options)) {
            $this->requirePhraseCoverage = (bool) $options['prose_guard'];
        }

        $this->exactOnly = !empty($options['exact_only']);
        $this->cache = isset($options['cache']) && $options['cache'] instanceof CacheStore
            ? $options['cache']
            : null;
    }

    /**
     * 'source' (default) or 'last-leg'.
     */
    public function setPivotFallback(string $mode): self
    {
        $this->pivotFallback = $mode === 'last-leg' ? 'last-leg' : 'source';

        return $this;
    }

    public function pivotFallback(): string
    {
        return $this->pivotFallback;
    }

    /**
     * True when a long fragment without phrase coverage is returned untouched.
     */
    public function hasProseGuard(): bool
    {
        return $this->requirePhraseCoverage;
    }

    public function setProseGuard(bool $enabled): self
    {
        $this->requirePhraseCoverage = $enabled;

        return $this;
    }

    /**
     * Translates a fragment between two languages, or to the language guessed
     * from the text when $from is null.
     */
    public function translate(string $text, ?string $from, string $to, array $options = []): TranslationResult
    {
        $this->stats['requests']++;

        $keep = isset($options['keep']) && is_array($options['keep']) ? array_values($options['keep']) : [];

        $to = LanguageRegistry::normalize($to);

        if ($from !== null) {
            $from = LanguageRegistry::normalize($from);
        }

        if (trim($text) === '') {
            return new TranslationResult($text, (string) $from, $to, [], false);
        }

        if ($from === null) {
            $from = $this->detectLanguage($text);
            if ($from === null) {
                return new TranslationResult($text, '', $to, [], false);
            }
        }

        if ($from === $to) {
            return new TranslationResult($text, $from, $to, [], true, 1.0, 1, 1);
        }

        $legs = $this->route($from, $to);
        if ($legs === []) {
            $this->stats['untranslated']++;

            return new TranslationResult($text, $from, $to, [], false);
        }

        $this->stats['segments']++;

        $cacheKey = $this->cacheKey($text, $legs, $keep);
        $cached = $this->cache !== null ? $this->cache->get($cacheKey) : null;

        if ($cached !== null) {
            $envelope = json_decode($cached, true);
            if (is_array($envelope) && isset($envelope['text'])) {
                $this->stats['cache_hits']++;

                return new TranslationResult(
                    (string) $envelope['text'],
                    $from,
                    $to,
                    $legs,
                    (bool) $envelope['matched'],
                    (float) $envelope['coverage'],
                    (int) $envelope['tokens'],
                    (int) $envelope['matched_tokens'],
                    true
                );
            }
        }

        $this->stats['cache_misses']++;
        $this->stats['cache_misses']++;

        $result = $this->runLegs($legs, $text, $keep);

        // A weak direct table is worse than a good pivot route: when the route
        // used translates little, try the alternative and keep the best one.
        if (!$result['matched'] || $result['weighted'] < $this->minCoverage) {
            $alternative = $this->alternativeRoute($from, $to, $legs);

            if ($alternative !== []) {
                $this->stats['fallbacks'] = ($this->stats['fallbacks'] ?? 0) + 1;

                $candidate = $this->runLegs($alternative, $text, $keep);

                if ($candidate['matched'] && $candidate['weighted'] > $result['weighted']) {
                    $result = $candidate;
                    $legs = $alternative;
                }
            }
        }

        if (!$result['matched']) {
            $this->stats['untranslated']++;
        }

        if ($this->cache !== null) {
            $this->cache->put($cacheKey, (string) json_encode([
                'text' => $result['text'],
                'matched' => $result['matched'],
                'coverage' => $result['coverage'],
                'tokens' => $result['tokens'],
                'matched_tokens' => $result['matched_tokens'],
            ], JSON_UNESCAPED_UNICODE));
        }

        return new TranslationResult(
            $result['text'],
            $from,
            $to,
            $legs,
            $result['matched'],
            $result['coverage'],
            $result['tokens'],
            $result['matched_tokens']
        );
    }

    /**
     * Runs a route over a text and measures how well it did.
     *
     * Only the last leg counts for the score: the previous ones are
     * intermediate steps and must not inflate the coverage.
     *
     * @param array<int,array{from:string,to:string}> $legs
     *
     * @return array{text:string,matched:bool,coverage:float,tokens:int,matched_tokens:int}
     */
    private function runLegs(array $legs, string $text, array $keep = []): array
    {
        $current = $text;
        $matched = false;
        $tokens = 0;
        $matchedTokens = 0;
        $weighted = 0.0;
        $lastLegText = null;

        foreach ($legs as $leg) {
            $table = $this->table($leg['from'], $leg['to']);
            if ($table === null) {
                continue;
            }

            $outcome = $this->exactOnly
                ? $this->exactOutcome($table, $current)
                : $table->withKeep($keep)->translate($current);

            $phraseTokens = isset($outcome['phrase_tokens']) ? (int) $outcome['phrase_tokens'] : 0;

            $current = $outcome['text'];
            $matchedTokens = $outcome['matched_tokens'];
            $tokens = $outcome['tokens'];
            $weighted = isset($outcome['weighted']) ? (float) $outcome['weighted'] : 1.0;
            $matched = $matched || $outcome['matched'];
            $lastLegText = $outcome['matched'] ? $outcome['text'] : null;
        }

        $coverage = $tokens > 0 ? min(1.0, $matchedTokens / $tokens) : ($matched ? 1.0 : 0.0);

        // Function words (de, con, y...) are easy to translate and say nothing
        // about quality, so the decision uses the weighted score: a sentence
        // full of prepositions but missing its nouns must not be "translated".
        $score = $weighted;

        // Word by word translation of a long sentence is always wrong word
        // order, whatever the coverage says: "La decisión entre software a
        // medida no es trivial" becomes "Die decision zwischen Maßanfertigung
        // nein ist trivial". The coverage is high because every single word is
        // in the dictionary.
        //
        // So a long fragment that no phrase ever covered comes back untouched:
        // the original Spanish is always better than broken German.
        if ($this->requirePhraseCoverage
            && $tokens >= $this->minPhraseTokens
            && $phraseTokens < $tokens * $this->minPhraseRatio) {
            $this->stats['prose_rejected'] = ($this->stats['prose_rejected'] ?? 0) + 1;

            return [
                'text' => $text,
                'matched' => false,
                'coverage' => 0.0,
                'weighted' => 0.0,
                'tokens' => $tokens,
                'matched_tokens' => 0,
            ];
        }

        if (!$matched || $score < $this->minCoverage) {
            // Graceful degradation: on a pivot route the source text has
            // already been translated into the hub language. Handing back the
            // Spanish original throws that work away, and for a reader of the
            // target language the hub text is usually more useful than the
            // untouched source. Opt in with ['pivot_fallback' => 'last-leg'].
            if ($this->pivotFallback === 'last-leg' && $lastLegText !== null) {
                return [
                    'text' => $lastLegText,
                    'matched' => true,
                    'coverage' => $coverage,
                    'weighted' => $score,
                    'tokens' => $tokens,
                    'matched_tokens' => $matchedTokens,
                ];
            }

            return [
                'text' => $text,
                'matched' => false,
                'coverage' => 0.0,
                'weighted' => 0.0,
                'tokens' => $tokens,
                'matched_tokens' => 0,
            ];
        }

        return [
            'text' => $current,
            'matched' => true,
            'coverage' => $coverage,
            'weighted' => $score,
            'tokens' => $tokens,
            'matched_tokens' => $matchedTokens,
        ];
    }

    /**
     * Route that was not used: the pivot one when a direct table exists, or
     * the direct one when the pivot was used.
     *
     * @param array<int,array{from:string,to:string}> $used
     *
     * @return array<int,array{from:string,to:string}>
     */
    private function alternativeRoute(string $from, string $to, array $used): array
    {
        $pivot = $this->pivot;

        if ($from !== $pivot
            && $to !== $pivot
            && $this->table($from, $pivot) !== null
            && $this->table($pivot, $to) !== null) {
            $pivotRoute = [
                ['from' => $from, 'to' => $pivot],
                ['from' => $pivot, 'to' => $to],
            ];

            if ($pivotRoute !== $used) {
                return $pivotRoute;
            }
        }

        if ($this->table($from, $to) !== null) {
            $direct = [['from' => $from, 'to' => $to]];

            if ($direct !== $used) {
                return $direct;
            }
        }

        return [];
    }

    /**
     * True when the fragment can be translated with full confidence (a phrase
     * hit exists in the route tables).
     */
    public function has(string $text, string $from, string $to): bool
    {
        foreach ($this->route($from, $to) as $leg) {
            $table = $this->table($leg['from'], $leg['to']);
            if ($table !== null && $table->hasPhrase($text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Legs needed to translate $from => $to, transparently using the pivot
     * language when there is no direct dictionary.
     *
     * @return array<int,array{from:string,to:string}>
     */
    public function route(string $from, string $to): array
    {
        $from = LanguageRegistry::normalize($from);
        $to = LanguageRegistry::normalize($to);

        if ($from === '' || $to === '' || $from === $to) {
            return [];
        }

        if ($this->table($from, $to) !== null) {
            return [['from' => $from, 'to' => $to]];
        }

        $pivot = $this->pivot;

        if ($from !== $pivot
            && $to !== $pivot
            && $this->table($from, $pivot) !== null
            && $this->table($pivot, $to) !== null) {
            return [
                ['from' => $from, 'to' => $pivot],
                ['from' => $pivot, 'to' => $to],
            ];
        }

        return [];
    }

    public function isTranslatable(string $from, string $to): bool
    {
        return $this->route($from, $to) !== [];
    }

    /**
     * Loads (or returns the memoized) table of a pair.
     */
    public function table(string $from, string $to): ?DictionaryTable
    {
        $from = LanguageRegistry::normalize($from);
        $to = LanguageRegistry::normalize($to);
        $key = $from . '>' . $to;

        if (!array_key_exists($key, $this->tables)) {
            $this->tables[$key] = $this->loadTable($from, $to);
        }

        return $this->tables[$key];
    }

    /**
     * Every pair available in the data folders.
     *
     * @return array<int,array{from:string,to:string,entries:int,path:string}>
     */
    public function pairs(): array
    {
        $discovered = $this->discoveredPairs();
        $pairs = [];

        foreach ($discovered as $pair) {
            $table = $this->table($pair['from'], $pair['to']);
            $pairs[] = [
                'from' => $pair['from'],
                'to' => $pair['to'],
                'entries' => $table !== null ? $table->entryCount() : 0,
                'path' => $pair['path'],
            ];
        }

        return $pairs;
    }

    /**
     * @return array<int,string>
     */
    public function languages(): array
    {
        return LanguageRegistry::discoverLanguages($this->directories);
    }

/**
 * Availability of every ordered combination of the discovered languages.
 *
 * Perfect for a UI that has to show (or grey out) the language pickers:
 *
 *   [ 'es' => ['en' => 1, 'de' => 2], 'de' => ['en' => 1, 'es' => 2] ]
 *
 * The value is the number of legs of the route: 1 = direct dictionary,
 * 2 = resolved through the pivot language. A missing key means the combination
 * is not available.
 *
 * @return array<string,array<string,int>>
 */
    public function matrix(): array
    {
        $languages = $this->languages();
        $matrix = [];

        foreach ($languages as $from) {
            $row = [];

            foreach ($languages as $to) {
                if ($from === $to) {
                    continue;
                }

                $legs = $this->route($from, $to);
                if ($legs !== []) {
                    $row[$to] = count($legs);
                }
            }

            $matrix[$from] = $row;
        }

        return $matrix;
    }

    /**
     * Guesses the language of a fragment using the loaded dictionaries.
     */
    public function detectLanguage(string $text): ?string
    {
        $tables = [];

        foreach ($this->discoveredPairs() as $pair) {
            $table = $this->table($pair['from'], $pair['to']);
            if ($table !== null && $table->entryCount() > 0) {
                $tables[$pair['from'] . '>' . $pair['to']] = $table;
            }
        }

        return LanguageRegistry::detect($text, $tables);
    }

    /**
     * Dictionaries found in the data folders, discovered only once per process.
     *
     * @return array<int,array{from:string,to:string,path:string}>
     */
    private function discoveredPairs(): array
    {
        if ($this->discovered === null) {
            $this->discovered = LanguageRegistry::discoverPairs($this->directories);
        }

        return $this->discovered;
    }

    /**
     * Signature of a pair: changes automatically when the file is edited, so
     * stale cache entries are never served.
     */
    public function signature(string $from, string $to): string
    {
        $key = $from . '>' . $to;
        if (isset($this->signatures[$key])) {
            return $this->signatures[$key];
        }

        $parts = [];
        foreach ($this->candidateFiles($from, $to) as $path) {
            $parts[] = basename($path) . ':' . (int) @filemtime($path) . ':' . (int) @filesize($path);
        }

        return $this->signatures[$key] = implode('|', $parts);
    }

    /**
     * @return array<string,mixed>
     */
    public function stats(): array
    {
        $stats = $this->stats;
        $stats['tables_loaded'] = count(array_filter($this->tables));
        $stats['pivot'] = $this->pivot;
        $stats['exact_only'] = $this->exactOnly;
        $stats['data_paths'] = $this->directories;

        return $stats;
    }

    public function pivot(): string
    {
        return $this->pivot;
    }

    /**
     * Share of tokens a fragment must translate before it is accepted.
     * 0 = translate whatever is found, 1 = only full matches, 0.5 = default.
     */
    public function minCoverage(): float
    {
        return $this->minCoverage;
    }

    public function setMinCoverage($coverage): self
    {
        $this->minCoverage = self::clampCoverage($coverage);

        return $this;
    }

    private static function clampCoverage($coverage): float
    {
        if (!is_numeric($coverage)) {
            return 0.5;
        }

        $value = (float) $coverage;

        return $value < 0.0 ? 0.0 : ($value > 1.0 ? 1.0 : $value);
    }

    public function setPivot(string $code): void
    {
        $this->pivot = LanguageRegistry::normalize($code) ?: 'en';
        $this->signatures = [];
    }

    /**
     * Forgets every loaded table (used by long running workers and tests).
     */
    public function reset(): void
    {
        $this->tables = [];
        $this->payloads = [];
        $this->signatures = [];
        $this->discovered = null;
    }

    /**
     * @return array{text:string,matched:bool,coverage:float,matched_tokens:int,tokens:int}
     */
    private function exactOutcome(DictionaryTable $table, string $text): array
    {
        $value = $table->phrase($text);
        if ($value !== null) {
            return [
                'text' => Text::restoreCase($text, $value),
                'matched' => true,
                'coverage' => 1.0,
                'matched_tokens' => 1,
                'tokens' => 1,
            ];
        }

        return ['text' => $text, 'matched' => false, 'coverage' => 0.0, 'matched_tokens' => 0, 'tokens' => 0];
    }

    private function cacheKey(string $text, array $legs, array $keep = []): string
    {
        $signature = [];
        foreach ($legs as $leg) {
            $signature[] = $leg['from'] . '>' . $leg['to'] . '@' . $this->signature($leg['from'], $leg['to']);
        }

        return Text::fingerprint(
            self::CACHE_VERSION,
            self::codeSignature(),
            $this->exactOnly ? 'exact' : 'fuzzy',
            'cov' . number_format($this->minCoverage, 2, '.', ''),
            'fallback:' . $this->pivotFallback,
            'keep:' . implode(',', $keep),
            implode(',', $signature),
            $text
        );
    }

    /**
     * Signature of the translation code itself: upgrading the package (or
     * editing it) invalidates every cached fragment automatically.
     */
    public static function codeSignature(): string
    {
        $stamps = [];
        foreach (self::CODE_FILES as $suffix) {
            $path = dirname(__DIR__) . $suffix;
            $stamps[] = is_file($path) ? (string) @filemtime($path) : '0';
        }

        return implode('-', $stamps);
    }

    /**
     * @return array<int,string>
     */
    private function candidateFiles(string $from, string $to): array
    {
        $names = [
            $from . '_' . $to . '.json',
            $from . '-' . $to . '.json',
            $from . '_' . $to . '.sqlite',
            $from . '_' . $to . '.sqlite3',
        ];

        $files = [];
        foreach ($this->directories as $directory) {
            foreach ($names as $name) {
                $path = $directory . DIRECTORY_SEPARATOR . $name;
                if (is_file($path)) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    private function loadTable(string $from, string $to): ?DictionaryTable
    {
        $merged = null;

        foreach ($this->candidateFiles($from, $to) as $path) {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            $section = ($extension === 'sqlite' || $extension === 'sqlite3')
                ? $this->sectionFromSqlite($path, $from, $to)
                : $this->sectionFromJson($path, $from, $to);

            if ($section === null) {
                continue;
            }

            $this->stats['files_loaded']++;
            $table = DictionaryTable::fromArray($section, $from, $to, $path);

            if ($table->isEmpty()) {
                continue;
            }

            $merged = $merged === null ? $table : $this->merge($merged, $table);
        }

        return $merged;
    }

    private function merge(DictionaryTable $a, DictionaryTable $b): DictionaryTable
    {
        // The first table always wins; the second one only fills its gaps.
        $first = $a->toArray();
        $second = $b->toArray();

        return DictionaryTable::fromArray([
            'name' => $first['name'],
            'source' => $a->source(),
            'meta' => $first['meta'],
            'phrases' => array_merge($second['phrases'], $first['phrases']),
            'words' => array_merge($second['words'], $first['words']),
            'phrases_strict' => array_merge($second['phrases_strict'], $first['phrases_strict']),
            'words_strict' => array_merge($second['words_strict'], $first['words_strict']),
            'keep' => $first['keep'],
            'drop_source' => $first['drop_source'],
            'rules' => $first['rules'],
            'plural' => $first['plural'],
        ], $a->from(), $a->to(), $a->source());
    }

    /**
     * Extracts the section of a JSON file that belongs to a language pair.
     *
     * Supported layouts:
     *   {"phrases": {...}, "words": {...}, "rules": [...]}      (per pair file)
     *   {"hola": "hello"}                                        (flat map)
     *   {"tables": [{"from": "es", "to": "en", "phrases": ...}]}
     *   {"entries": [{"from":"es","to":"en","text":"...","translation":"..."}]}
     *
     * @return array<string,mixed>|null
     */
    private function sectionFromJson(string $path, string $from, string $to): ?array
    {
        $payload = $this->payload($path);
        if ($payload === null) {
            return null;
        }

        $section = null;

        if (isset($payload['tables']) && is_array($payload['tables'])) {
            foreach ($payload['tables'] as $table) {
                if (!is_array($table)) {
                    continue;
                }
                if (LanguageRegistry::normalize((string) ($table['from'] ?? '')) === $from
                    && LanguageRegistry::normalize((string) ($table['to'] ?? '')) === $to) {
                    $section = $this->sectionFromTable($table);
                    break;
                }
            }
        } elseif (isset($payload['entries']) && is_array($payload['entries'])) {
            $phrases = [];
            $words = [];
            foreach ($payload['entries'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (LanguageRegistry::normalize((string) ($entry['from'] ?? '')) !== $from
                    || LanguageRegistry::normalize((string) ($entry['to'] ?? '')) !== $to) {
                    continue;
                }

                $source = (string) ($entry['text'] ?? $entry['source'] ?? '');
                $target = (string) ($entry['translation'] ?? $entry['target'] ?? '');
                if ($source === '' || $target === '') {
                    continue;
                }

                if (($entry['type'] ?? 'phrase') === 'word') {
                    $words[$source] = $target;
                } else {
                    $phrases[$source] = $target;
                }
            }

            $section = ['phrases' => $phrases, 'words' => $words];
        } else {
            $declaredFrom = isset($payload['from']) ? LanguageRegistry::normalize((string) $payload['from']) : $from;
            $declaredTo = isset($payload['to']) ? LanguageRegistry::normalize((string) $payload['to']) : $to;

            if ($declaredFrom === $from && $declaredTo === $to) {
                $section = $this->sectionFromTable($payload);
            }
        }

        if ($section === null) {
            return null;
        }

        $section['phrases'] = $section['phrases'] ?? [];
        $section['words'] = $section['words'] ?? [];

        if ($section['phrases'] === [] && $section['words'] === []) {
            return null;
        }

        return $section;
    }

    /**
     * @param array<string,mixed> $table
     *
     * @return array<string,mixed>
     */
    private function sectionFromTable(array $table): array
    {
        $section = [
            'name' => (string) ($table['name'] ?? ''),
            'meta' => isset($table['meta']) && is_array($table['meta']) ? $table['meta'] : [],
            'phrases' => [],
            'words' => [],
        ];

        if (isset($table['phrases']) && is_array($table['phrases'])) {
            $section['phrases'] = $table['phrases'];
        }

        if (isset($table['words']) && is_array($table['words'])) {
            $section['words'] = $table['words'];
        }

        // Flat layout: everything that is a "string => string" pair.
        foreach ($table as $key => $value) {
            if (!is_string($value) || in_array($key, ['from', 'to', 'name', 'source', 'version'], true)) {
                continue;
            }
            if (isset($section['phrases'][$key])) {
                continue;
            }

            $section['phrases'][$key] = $value;
        }

        foreach (['keep', 'rules', 'drop_source', 'keep_value_case'] as $optional) {
            if (isset($table[$optional])) {
                $section[$optional] = $table[$optional];
            }
        }

        if (isset($table['plural'])) {
            $section['plural'] = $table['plural'];
        }

        return $section;
    }

    /**
     * Optional SQLite dictionary: a table called entries/translations/dictionary
     * with (source, target) columns and an optional "kind" column.
     *
     * @return array<string,mixed>|null
     */
    private function sectionFromSqlite(string $path, string $from, string $to): ?array
    {
        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return null;
        }

        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $table = null;
            foreach (['entries', 'translations', 'dictionary'] as $candidate) {
                $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $candidate . "'");
                if ($statement !== false && $statement->fetch() !== false) {
                    $table = $candidate;
                    break;
                }
            }

            if ($table === null) {
                return null;
            }

            $columns = [];
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')') ?: [] as $column) {
                $columns[] = (string) $column['name'];
            }

            if (!in_array('source', $columns, true) || !in_array('target', $columns, true)) {
                return null;
            }

            $hasKind = in_array('kind', $columns, true);
            $hasPair = in_array('from_lang', $columns, true) && in_array('to_lang', $columns, true);

            $sql = 'SELECT source, target' . ($hasKind ? ', kind' : '') . ' FROM ' . $table;
            if ($hasPair) {
                $sql .= " WHERE from_lang = :from AND to_lang = :to";
            }

            $statement = $pdo->prepare($sql);
            if ($hasPair) {
                $statement->execute([':from' => $from, ':to' => $to]);
            } else {
                $statement->execute();
            }

            $phrases = [];
            $words = [];
            foreach ($statement->fetchAll() ?: [] as $row) {
                $value = (string) $row['target'];
                if (($row['kind'] ?? 'phrase') === 'word') {
                    $words[(string) $row['source']] = $value;
                } else {
                    $phrases[(string) $row['source']] = $value;
                }
            }

            if ($phrases === [] && $words === []) {
                return null;
            }

            return ['phrases' => $phrases, 'words' => $words];
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function payload(string $path): ?array
    {
        if (array_key_exists($path, $this->payloads)) {
            return $this->payloads[$path];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $this->payloads[$path] = null;
        }

        $decoded = json_decode($raw, true);

        return $this->payloads[$path] = is_array($decoded) ? $decoded : null;
    }
}