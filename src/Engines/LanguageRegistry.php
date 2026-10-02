<?php

declare(strict_types=1);

namespace Medel\Translator\Engines;

use Medel\Translator\Support\Text;

/**
 * Language code utilities and data discovery.
 *
 * Codes are normalized to lowercase ISO 639-1 ("es", "en", "de"), regional
 * variants are accepted ("es-MX" => "es") and ISO 639-3 aliases are mapped
 * ("spa" => "es") so callers can use whatever their data provides.
 */
final class LanguageRegistry
{
    /** @var array<string,string> */
    private const ALIASES = [
        'spa' => 'es', 'sp' => 'es', 'castellano' => 'es', 'espanol' => 'es', 'español' => 'es',
        'eng' => 'en', 'en-us' => 'en', 'en-gb' => 'en', 'ingles' => 'en', 'inglés' => 'en', 'english' => 'en',
        'deu' => 'de', 'ger' => 'de', 'de-de' => 'de', 'deutsch' => 'de',
        'fra' => 'fr', 'fre' => 'fr', 'fr-fr' => 'fr', 'francais' => 'fr', 'français' => 'fr',
        'ita' => 'it', 'ita-it' => 'it', 'italiano' => 'it',
        'por' => 'pt', 'pt-br' => 'pt', 'pt-pt' => 'pt', 'portugues' => 'pt', 'português' => 'pt',
        'nld' => 'nl', 'dut' => 'nl', 'nederlands' => 'nl',
        'cat' => 'ca', 'eus' => 'eu', 'baq' => 'eu',
        'glg' => 'gl', 'ell' => 'el', 'gre' => 'el', 'grc' => 'el',
        'jpn' => 'ja', 'ja-jp' => 'ja', 'japanese' => 'ja',
        'zho' => 'zh', 'chi' => 'zh', 'zh-cn' => 'zh', 'chinese' => 'zh',
        'kor' => 'ko', 'rus' => 'ru', 'ukr' => 'uk', 'pol' => 'pl',
        'ces' => 'cs', 'cze' => 'cs', 'heb' => 'he', 'ara' => 'ar',
        'hin' => 'hi', 'tur' => 'tr', 'swe' => 'sv', 'nor' => 'no', 'dan' => 'da', 'fin' => 'fi',
        'ron' => 'ro', 'rum' => 'ro', 'hun' => 'hu', 'ind' => 'id', 'msa' => 'ms', 'may' => 'ms',
        'vie' => 'vi', 'tha' => 'th', 'ukr' => 'uk', 'srp' => 'sr', 'hrv' => 'hr', 'slv' => 'sl',
        'bul' => 'bg', 'cat' => 'ca', 'est' => 'et', 'lav' => 'lv', 'lit' => 'lt', 'slk' => 'sk',
    ];

    /** @var array<string,string> */
    private const NAMES = [
        'ar' => 'Arabic', 'bg' => 'Bulgarian', 'ca' => 'Catalan', 'cs' => 'Czech', 'da' => 'Danish',
        'de' => 'German', 'el' => 'Greek', 'en' => 'English', 'es' => 'Spanish', 'et' => 'Estonian',
        'eu' => 'Basque', 'fi' => 'Finnish', 'fr' => 'French', 'gl' => 'Galician', 'he' => 'Hebrew',
        'hi' => 'Hindi', 'hr' => 'Croatian', 'hu' => 'Hungarian', 'id' => 'Indonesian', 'it' => 'Italian',
        'ja' => 'Japanese', 'ko' => 'Korean', 'lt' => 'Lithuanian', 'lv' => 'Latvian', 'ms' => 'Malay',
        'nl' => 'Dutch', 'no' => 'Norwegian', 'pl' => 'Polish', 'pt' => 'Portuguese', 'ro' => 'Romanian',
        'ru' => 'Russian', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'sr' => 'Serbian', 'sv' => 'Swedish',
        'th' => 'Thai', 'tr' => 'Turkish', 'uk' => 'Ukrainian', 'vi' => 'Vietnamese', 'zh' => 'Chinese',
    ];

    private function __construct()
    {
    }

    /**
     * Normalizes a language code: "ES_MX" => "es", "spa" => "es".
     */
    public static function normalize(string $code): string
    {
        $code = strtolower(trim(str_replace('_', '-', $code)));
        if ($code === '') {
            return '';
        }

        if (isset(self::ALIASES[$code])) {
            return self::ALIASES[$code];
        }

        // Regional variant: keep only the primary subtag.
        if (strpos($code, '-') !== false) {
            $primary = substr($code, 0, (int) strpos($code, '-'));
            if (isset(self::ALIASES[$primary])) {
                return self::ALIASES[$primary];
            }

            return $primary;
        }

        return $code;
    }

    /**
     * @return array<string,string> ISO 639-1 => english name
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    public static function name(string $code): string
    {
        $code = self::normalize($code);

        return self::NAMES[$code] ?? strtoupper($code);
    }

    /**
     * Pairs shipped/available in the given data folders.
     *
     * Recognized file names: es_en.json, es-en.json, es_en.sqlite,
     * es_en.json.bak... no: only es_en.{json,sqlite} and es-en.json plus an
     * optional index.json manifest.
     *
     * @param array<int,string> $directories
     *
     * @return array<int,array{from:string,to:string,path:string}>
     */
    public static function discoverPairs(array $directories): array
    {
        $pairs = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = @scandir($directory);
            if ($iterator === false) {
                continue;
            }

            foreach ($iterator as $file) {
                $parsed = self::parsePairFilename($file);
                if ($parsed === null) {
                    continue;
                }

                $pairs[self::pairKey($parsed['from'], $parsed['to'])] = [
                    'from' => $parsed['from'],
                    'to' => $parsed['to'],
                    'path' => rtrim($directory, "/\\") . DIRECTORY_SEPARATOR . $file,
                ];
            }
        }

        return array_values($pairs);
    }

    /**
     * Parses "es_en.json" / "es-en.sqlite" into its two language codes.
     *
     * @return array{from:string,to:string}|null
     */
    public static function parsePairFilename(string $file): ?array
    {
        if (!preg_match('/^([a-z]{2,3})(?:[_-]([a-z]{2,3}))\.(json|sqlite|sqlite3)$/i', $file, $m)) {
            return null;
        }

        $from = self::normalize($m[1]);
        $to = self::normalize($m[2]);

        if ($from === '' || $to === '' || $from === $to) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param array<int,string> $directories
     *
     * @return array<int,string> sorted unique list of language codes
     */
    public static function discoverLanguages(array $directories): array
    {
        $languages = [];

        foreach (self::discoverPairs($directories) as $pair) {
            $languages[$pair['from']] = true;
            $languages[$pair['to']] = true;
        }

        $languages = array_keys($languages);
        sort($languages);

        return $languages;
    }

    public static function pairKey(string $from, string $to): string
    {
        return self::normalize($from) . '_' . self::normalize($to);
    }

    /**
     * Default file name of a data file for a pair.
     */
    public static function pairFilename(string $from, string $to): string
    {
        return self::pairKey($from, $to) . '.json';
    }

    /**
     * Heuristic language identification: scores the fragment against every
     * loaded dictionary and returns the best candidate.
     *
     * @param array<string,DictionaryTable> $tables keyed by "from>to"
     */
    public static function detect(string $text, array $tables): ?string
    {
        $tokens = Text::tokenize($text);
        $words = [];
        foreach ($tokens as $token) {
            if (Text::isWord($token) && Text::hasLetter($token) && !Text::hasDigit($token)) {
                $key = Text::normalizeKey($token);
                if ($key !== '') {
                    $words[] = $key;
                }
            }
        }

        if ($words === []) {
            return null;
        }

        $scores = [];

        foreach ($tables as $table) {
            $score = 0;

            foreach ($words as $word) {
                if ($table->hasPhrase($word)) {
                    $score += 2;
                    continue;
                }

                // A word level hit also counts, but with a lower weight so a
                // language with phrase matches wins over one that only shares
                // common vocabulary.
                if ($table->word($word) !== null) {
                    $score += 1;
                }
            }

            if ($score === 0) {
                continue;
            }

            $language = $table->from();
            $scores[$language] = ($scores[$language] ?? 0) + $score;
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);

        return (string) array_key_first($scores);
    }
}