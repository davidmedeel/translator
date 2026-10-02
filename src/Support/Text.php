<?php

declare(strict_types=1);

namespace Medel\Translator\Support;

/**
 * Low level text helpers: normalization, folding, tokenization, entity masking
 * and placeholder awareness.
 *
 * Everything in this class is intentionally free of I/O so it can be unit
 * tested and reused by any engine.
 */
final class Text
{
    /** Placeholder written by the HtmlProcessor for protected (non translatable) terms. */
    public const PLACEHOLDER_PATTERN = '/__[A-Z]{2,}[A-Z0-9]*_\d+__/';

    /**
     * sprintf template of the placeholders above.
     *
     * Shared by the protected terms of the HtmlProcessor and by the multi word
     * keep terms of a dictionary table, so the two never collide.
     */
    public const PLACEHOLDER_FORMAT = '__KEEP_%d__';

    /**
     * HTML entities are replaced by Private Use Area surrogates before the text
     * reaches the engine. Two ranges are used:
     *
     *  - WORD entities (&iacute; inside a word) get a surrogate that behaves
     *    like a letter, so "m&iacute;nimos" stays a single token;
     *  - SPACING entities (&nbsp;, &emsp;...) get a surrogate that behaves like
     *    a separator, so "mundo&nbsp;fin" stays two tokens.
     */
    private const WORD_GUARD_START = 0xF900;
    private const WORD_GUARD_END = 0xFAFF;
    private const SPACE_GUARD_START = 0xE000;
    private const SPACE_GUARD_END = 0xE0FF;

/**
     * ASCII entities that are safe to decode: punctuation, symbols and the
     * invisible characters that must not glue words together.
     */
    private const SAFE_ASCII_ENTITIES = [
        'amp' => true, 'lt' => true, 'gt' => true, 'quot' => true, 'apos' => true,
        'nbsp' => true, 'ensp' => true, 'emsp' => true, 'thinsp' => true, 'hairsp' => true,
        'zwnj' => true, 'zwj' => true, 'shy' => true, 'zwsp' => true, 'lrm' => true, 'rlm' => true,
        'deg' => true, 'plusmn' => true, 'times' => true, 'divide' => true, 'euro' => true,
        'pound' => true, 'yen' => true, 'sect' => true, 'para' => true, 'middot' => true,
        'bull' => true, 'dagger' => true, 'permil' => true, 'prime' => true, 'trade' => true,
        'reg' => true, 'copy' => true, 'frac12' => true, 'frac14' => true, 'frac34' => true,
        'larr' => true, 'rarr' => true, 'uarr' => true, 'darr' => true, 'harr' => true,
        'ne' => true, 'le' => true, 'ge' => true, 'minus' => true, 'lowast' => true,
        'iexcl' => true, 'iquest' => true, 'brvbar' => true, 'uml' => true, 'macr' => true,
        'sup2' => true, 'sup3' => true, 'micro' => true, 'cent' => true, 'not' => true,
    ];

    /** Entities that must simply disappear from the text. */
    private const DROPPED_ENTITIES = ['shy' => true, 'zwnj' => true, 'zwj' => true, 'zwsp' => true, 'lrm' => true, 'rlm' => true];

    /** Surrogates that must be treated as letters. */
    private const WORD_GUARD_CHARS = '\x{F900}-\x{FAFF}';

    /** Surrogates that must be treated as separators. */
    private const SPACE_GUARD_CHARS = '\x{E000}-\x{E0FF}';

    /**
     * Scripts written without word separators.
     *
     * Japanese (and Chinese) do not put spaces between words, so the tokenizer
     * splits those runs character by character and the engine matches them with
     * a dictionary driven longest match, exactly like a segmenter.
     */
    private const CJK_CHARS = '\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF66}-\x{FF9D}';

    /** Scripts written from right to left. */
    private const RTL_LANGUAGES = ['ar' => true, 'he' => true, 'fa' => true, 'ur' => true, 'ps' => true, 'dv' => true, 'yi' => true];

    /** @var array<string,string> */

    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ċ' => 'c',
        'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j',
        'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w',
        'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss', 'þ' => 'th', 'ð' => 'd',
    ];

    private function __construct()
    {
    }

    /**
     * Multibyte lowercase (falls back gracefully when mbstring is missing).
     */
    public static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return (string) mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    /**
     * Multibyte uppercase.
     */
    public static function upper(string $value): string
    {
        if (function_exists('mb_strtoupper')) {
            return (string) mb_strtoupper($value, 'UTF-8');
        }

        return strtoupper($value);
    }

    /**
     * Uppercase the first letter of a UTF-8 string, leaving the rest untouched.
     */
    public static function ucfirst(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return self::upper(self::substr($value, 0, 1)) . self::substr($value, 1);
    }

    /**
     * Multibyte safe substr().
     */
    public static function substr(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? (string) mb_substr($value, $start, null, 'UTF-8')
                : (string) mb_substr($value, $start, $length, 'UTF-8');
        }

        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }

/**
     * Multibyte safe strlen().
     */
    public static function length(string $value): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /**
     * Removes leading and trailing whitespace, non breaking spaces included.
     *
     * Native trim() works on bytes, so a charlist holding a multibyte character
     * ("\u{00A0}" compiles to C2 A0) also lists its individual bytes: trimming
     * "¿Por qué?" would delete the C2 lead byte and leave a broken BF. That
     * silent corruption is why this helper exists; callers that need to strip
     * HTML nodes should use it instead of a hand written charlist.
     */
    public static function trim(string $value, string $extra = ''): string
    {
        $class = '\p{Z}\s\x{FEFF}';

        if ($extra !== '') {
            $class .= preg_quote($extra, '/');
        }

        $out = preg_replace('/^[' . $class . ']+|[' . $class . ']+$/u', '', $value);

        return $out === null ? $value : $out;
    }

/**
     * Removes diacritics: "Español" -> "Espanol", "mañana" -> "manana".
     *
     * The mapping is purely table driven (no iconv): transliteration tables are
     * platform dependent and would turn "ñ" into "~n" on some builds.
     */
    public static function foldAccents(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // 1. decomposed sequences: "a" + U+0301 -> "a"
        $value = (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $value);

        // 2. precomposed characters covered by the table
        $value = (string) preg_replace_callback(
            '/[\x{00C0}-\x{024F}]+/u',
            static function (array $m): string {
                $chunk = $m[0];
                $out = '';
                $len = self::length($chunk);
                for ($i = 0; $i < $len; $i++) {
                    $char = self::substr($chunk, $i, 1);
                    $lower = self::lower($char);
                    $out .= self::ACCENTS[$lower] ?? $char;
                }

                return $out;
            },
            $value
        );

        // 3. leftovers from the Latin ranges are dropped, they carry no meaning
        return (string) preg_replace('/[\x{00C0}-\x{024F}]+/u', '', $value);
    }

/**
 * Canonical lookup key: lowercase, typographic characters normalized,
 * whitespace collapsed and, by default, accents folded.
 *
 * Folding "ñ" and "n" together is what allows a dictionary written without
 * accents to match real text. Set $fold = false to keep the diacritics, which
 * is needed to tell apart pairs such as Italian "e"/"è", French "a"/"à" or
 * Spanish "de"/"dé".
 */
    public static function normalizeKey(string $value, bool $fold = true): string
    {
        $value = str_replace(
            ["\u{00A0}", "\u{2007}", "\u{202F}", "\u{2009}", "\u{200B}", "\u{FEFF}"],
            [' ', ' ', ' ', ' ', '', ''],
            $value
        );

        $value = strtr($value, [
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201A}" => "'",
            "\u{201B}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
            "\u{201E}" => '"',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
            "\u{2212}" => '-',
            "\u{00AB}" => '"',
            "\u{00BB}" => '"',
        ]);

        $value = self::lower($value);

        if ($fold) {
            $value = self::foldAccents($value);
        }

        // Word guard surrogates stand for entities that must survive untouched
        // (&shy;, &zwj;, unknown entities...). They are invisible for the
        // lookup, so "ma&shy;nana" resolves to the key "manana" while the text
        // keeps its entity.
        $value = (string) preg_replace('/[' . self::WORD_GUARD_CHARS . ']/u', '', $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}_' . self::WORD_GUARD_CHARS . '\'\- ]+/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

/**
 * True for tokens that name a place in a filesystem or a URL: "/docs",
 * "config.php", "uploads/img".
 *
 * They must never be translated: a folder called /docs has nothing to do with
 * the dictionary entry of the word "docs".
 */
    public static function isPathLike(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        if (strpbrk($token, '/\\') !== false) {
            return true;
        }

        // file.php, README.md, index.html...
        return preg_match('/^[\p{L}\p{N}_-]{1,40}\.[a-z0-9]{1,8}$/iu', $token) === 1;
    }

/**
 * True for the characters that glue two words into one for the dictionary:
 * "D-ID", "l'application", "S3-compatible".
 *
 * The tokenizer splits them into separate units, but a phrase key must rebuild
 * them or the entry would never be found.
 */
    public static function isWordJoiner(string $token): bool
    {
        return $token !== '' && preg_match('/^[\x{0027}\x{2019}\x{2018}-]+$/u', $token) === 1;
    }

/**
 * True when a token may be silently ignored inside a dictionary phrase.
 *
 * Punctuation and blanks are fine ("Hola, mundo" -> key "hola mundo"), but an
 * emoji, an arrow or a variation selector must never be part of a phrase span:
 * the replacement of the phrase would swallow it and the character would be
 * lost from the sentence.
 */
    public static function isPhraseSafe(string $token): bool
    {
        return preg_match('/[^\p{L}\p{N}\p{P}\p{Z}' . self::WORD_GUARD_CHARS . ']/u', $token) !== 1;
    }

    /**
     * Lookup key that preserves diacritics: "è" stays "è", "e" stays "e".
     */
    public static function strictKey(string $value): string
    {
        return self::normalizeKey($value, false);
    }

    /**
     * Splits a string into units keeping delimiters.
     *
     * Every letter run, every digit run and every symbol is a separate unit, so
     * rebuilding the sentence never loses a single character.
     *
     * @return array<int,string>
     */
    public static function tokenize(string $value): array
    {
        $parts = preg_split(
            '/([\p{L}\p{N}_' . self::WORD_GUARD_CHARS . ']+)/u',
            $value,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        if ($parts === false) {
            return [];
        }

        // Japanese and Chinese have no word separators: a run of ideographs or
        // kana is one token as far as the regex is concerned, which would make
        // it impossible to look up. Splitting them per character lets the engine
        // do a dictionary driven longest match, and the sentence is rebuilt by
        // concatenation so nothing is lost.
        $tokens = [];

        foreach ($parts as $part) {
            if (self::hasCjk($part) && mb_strlen($part, 'UTF-8') > 1) {
                foreach (preg_split('//u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                    $tokens[] = $char;
                }
                continue;
            }

            $tokens[] = $part;
        }

        return $tokens;
    }

    /**
     * True when the unit contains an ideograph, kana or a CJK symbol.
     */
    public static function hasCjk(string $token): bool
    {
        return preg_match('/[' . self::CJK_CHARS . ']/u', $token) === 1;
    }

    /**
     * True when the token is a single character written without word
     * separators, which the engine matches with a longest match.
     */
    public static function isCjkUnit(string $token): bool
    {
        return mb_strlen($token, 'UTF-8') === 1 && self::hasCjk($token);
    }

    /**
     * True for languages written from right to left.
     *
     * The engine never reorders anything (that would break the markup), but the
     * caller needs to know whether to render the document in RTL.
     */
    public static function isRtlLanguage(string $language): bool
    {
        $code = strtolower(substr(trim($language), 0, 2));

        return isset(self::RTL_LANGUAGES[$code]);
    }

    /**
     * 'rtl' or 'ltr' for a language code.
     */
    public static function direction(string $language): string
    {
        return self::isRtlLanguage($language) ? 'rtl' : 'ltr';
    }

    /**
     * True when the unit is a "word" (letters/digits/underscores only).
     */
    public static function isWord(string $token): bool
    {
        return (bool) preg_match('/^[\p{L}\p{N}_' . self::WORD_GUARD_CHARS . ']+$/u', $token);
    }

    /**
     * True when the unit contains at least one letter.
     */
    public static function hasLetter(string $token): bool
    {
        return (bool) preg_match('/\p{L}/u', $token);
    }

    /**
     * True when the unit contains digits (dates, prices, quantities...).
     */
    public static function hasDigit(string $token): bool
    {
        return (bool) preg_match('/\p{N}/u', $token);
    }

    /**
     * True for the temporary placeholders generated for protected terms.
     */
    public static function isPlaceholder(string $token): bool
    {
        return (bool) preg_match(self::PLACEHOLDER_PATTERN, $token);
    }

    /**
     * True when the unit contains a placeholder anywhere.
     */
    public static function containsPlaceholder(string $value): bool
    {
        return (bool) preg_match(self::PLACEHOLDER_PATTERN, $value);
    }

    /**
     * "ALL CAPS" / "Capitalized" / "lowercase" detection used to mirror the
     * original casing onto the translated word.
     */
    public static function restoreCase(string $sourceToken, string $translated): string
    {
        if ($translated === '' || $sourceToken === '') {
            return $translated;
        }

        $letters = (string) preg_replace('/[^\p{L}]/u', '', $sourceToken);
        if ($letters === '') {
            return $translated;
        }

        $upper = self::upper($letters);
        $first = self::substr($letters, 0, 1);

        // Scripts without case (Japanese, Chinese, Arabic...) must not be
        // "mirrored": 'データベース' has no upper case form, so the test below
        // would consider it capitalised and shout the translation.
        if ($letters === $upper && $letters === self::lower($letters)) {
            return $translated;
        }

        if (self::length($letters) > 1 && $letters === $upper) {
            return self::upper($translated);
        }

        if ($first !== '' && $first === self::upper($first) && $first !== self::lower($first)) {
            return self::ucfirst($translated);
        }

        return $translated;
    }

    /**
     * Extracts non letter/digit characters surrounding a word.
     *
     * @return array{0:string,1:string,2:string} [prefix, core, suffix]
     */
    public static function splitEdges(string $token): array
    {
        $prefix = '';
        while ($token !== '' && (bool) preg_match('/^[^\p{L}\p{N}_' . self::WORD_GUARD_CHARS . ']/u', $token)) {
            $prefix .= self::substr($token, 0, 1);
            $token = self::substr($token, 1);
        }

        $suffix = '';
        while ($token !== '' && (bool) preg_match('/[^\p{L}\p{N}_' . self::WORD_GUARD_CHARS . ']$/u', $token)) {
            $suffix = self::substr($token, -1) . $suffix;
            $token = self::substr($token, 0, self::length($token) - 1);
        }

        return [$prefix, $token, $suffix];
    }

    /**
     * Replaces every HTML entity with a Private Use Area surrogate character so
     * the engine can never translate or destroy it.
     *
     * @param array<string,string> $map receives surrogate => original entity
     */
public static function maskEntities(string $value, array &$map): string
    {
        $out = preg_replace_callback(
            '/&(?:#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z][a-zA-Z0-9]{1,31});/',
            static function (array $m) use (&$map): string {
                $entity = $m[0];
                $name = self::entityName($entity);

                // Invisible entities (&shy;, &zwj;, &lrm;...) become word guards
                // instead of being deleted: the lookup ignores them, so
                // "ma&shy;nana" behaves like "manana", and the sentence is
                // rebuilt with the very same entity. Dropping them would break
                // emoji sequences such as "&#128104;&zwj;&#128187;", which are
                // a single emoji joined by a zero width joiner.
                if ($name !== null && isset(self::DROPPED_ENTITIES[$name])) {
                    $span = self::WORD_GUARD_END - self::WORD_GUARD_START + 1;
                    $char = (string) mb_chr(self::WORD_GUARD_START + (count($map) % $span), 'UTF-8');

                    if (!in_array($char, $map, true)) {
                        $map[$char] = $entity;
                    }

                    return $char;
                }
                $decoded = html_entity_decode($entity, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($decoded !== $entity && $decoded !== '') {
                    if (self::isDecodable($name, $decoded)) {
                        if (!in_array($decoded, $map, true)) {
                            $map[$decoded] = $entity;
                        }

                        return $decoded;
                    }
                }

                foreach ($map as $char => $known) {
                    if ($known === $entity) {
                        return (string) $char;
                    }
                }

                $spacing = self::isSpacingEntity($entity);
                $start = $spacing ? self::SPACE_GUARD_START : self::WORD_GUARD_START;
                $end = $spacing ? self::SPACE_GUARD_END : self::WORD_GUARD_END;
                $span = $end - $start + 1;

                $char = (string) mb_chr($start + (count($map) % $span), 'UTF-8');
                $map[$char] = $entity;

                return $char;
            },
            $value
        );

        return $out === null ? $value : $out;
    }

    /**
     * Lowercase name of a named entity, null for numeric ones.
     */
    private static function entityName(string $entity): ?string
    {
        if (preg_match('/^&([a-zA-Z][a-zA-Z0-9]{1,31});$/', $entity, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * A decoded entity is usable when it is a single character that either
     * carries a diacritic/symbol (non ASCII) or is on the ASCII whitelist.
     * Anything else falls back to a surrogate, so no word is ever lost.
     */
    private static function isDecodable(?string $name, string $decoded): bool
    {
        if (Text::length($decoded) !== 1) {
            return false;
        }

        if (!preg_match('/^[\x20-\x7E]$/', $decoded)) {
            return true;
        }

        return $name !== null && isset(self::SAFE_ASCII_ENTITIES[$name]);
    }

    /**
     * True for entities that stand for a space or an invisible separator.
     */
    private static function isSpacingEntity(string $entity): bool
    {
        $name = self::entityName($entity);

        if ($name !== null) {
            return isset(self::SAFE_ASCII_ENTITIES[$name]) && !isset(self::DROPPED_ENTITIES[$name]);
        }

        if (preg_match('/^&#[xX]([0-9a-fA-F]{1,6});/', $entity, $m) === 1) {
            return self::isSpacingCode((int) hexdec($m[1]));
        }

        if (preg_match('/^&#([0-9]{1,7});/', $entity, $m) === 1) {
            return self::isSpacingCode((int) $m[1]);
        }

        return false;
    }

    private static function isSpacingCode(int $code): bool
    {
        return in_array($code, [32, 160, 173, 8194, 8195, 8201, 8202, 8203, 8204, 8287, 8291, 8292, 8293], true);
    }

    /**
     * Restores the entities previously masked by {@see self::maskEntities()}.
     *
     * @param array<string,string> $map surrogate => original entity
     */
    public static function unmaskEntities(string $value, array $map): string
    {
        if ($map === []) {
            return $value;
        }

        return strtr($value, $map);
    }

    /**
     * Removes HTML comments, CDATA and tags. Used only to decide whether a
     * string needs the HTML pipeline (never for output).
     */
    public static function containsMarkup(string $value): bool
    {
        return (bool) preg_match('/<(\/?[a-zA-Z][a-zA-Z0-9:-]*|\!--)/', $value);
    }

/**
 * True when every character of the string survives the normalization, i.e. the
 * fragment is made only of letters, digits, apostrophes, hyphens and spaces.
 *
 * Fragments that are not fully keyed (they contain emojis, symbols, entities
 * that were masked, ...) must go through the token pass, otherwise a whole
 * string match would silently drop those characters.
 */
    public static function isFullyKeyed(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        return !preg_match('/[^\p{L}\p{N}_\'\- \s\t\r\n' . self::WORD_GUARD_CHARS . ']/u', $value);
    }

/**
 * Function words (articles, prepositions, pronouns, auxiliaries...) of the
 * shipped languages.
 *
 * Translating them is easy and says nothing about the quality of a sentence,
 * so they are down-weighted when the engine decides whether a fragment is
 * translated well enough to be returned.
 *
 * @var array<string,bool>
 */
    private const STOPWORDS = [
        // articles, pronouns
        'el' => true, 'la' => true, 'los' => true, 'las' => true, 'un' => true, 'una' => true,
        'unos' => true, 'unas' => true, 'lo' => true, 'le' => true, 'les' => true, 'me' => true,
        'te' => true, 'se' => true, 'su' => true, 'sus' => true, 'mi' => true, 'mis' => true,
        'tu' => true, 'tus' => true, 'yo' => true, 'tu' => true, 'el' => true, 'ella' => true,
        'nosotros' => true, 'vosotros' => true, 'ellos' => true, 'ellas' => true, 'usted' => true,
        'the' => true, 'a' => true, 'an' => true, 'this' => true, 'that' => true, 'these' => true,
        'those' => true, 'it' => true, 'they' => true, 'them' => true, 'he' => true, 'she' => true,
        'i' => true, 'you' => true, 'we' => true, 'my' => true, 'your' => true, 'his' => true,
        'our' => true, 'their' => true, 'its' => true, 'who' => true, 'which' => true,
        'le' => true, 'la' => true, 'les' => true, 'un' => true, 'une' => true, 'des' => true,
        'du' => true, 'au' => true, 'aux' => true, 'ce' => true, 'cet' => true, 'cette' => true,
        'il' => true, 'lo' => true, 'gli' => true, 'i' => true, 'uno' => true, 'una' => true,
        'o' => true, 'os' => true, 'as' => true, 'um' => true, 'uma' => true, 'uns' => true,
        'de' => true, 'do' => true, 'da' => true, 'no' => true, 'na' => true, 'nos' => true,
        // prepositions
        'de' => true, 'del' => true, 'a' => true, 'al' => true, 'en' => true, 'con' => true,
        'por' => true, 'para' => true, 'sobre' => true, 'entre' => true, 'hasta' => true,
        'desde' => true, 'hacia' => true, 'tras' => true, 'durante' => true, 'sin' => true,
        'según' => true, 'tras' => true, 'ante' => true, 'bajo' => true, 'sobre' => true,
        'of' => true, 'in' => true, 'on' => true, 'at' => true, 'to' => true, 'for' => true,
        'from' => true, 'by' => true, 'with' => true, 'without' => true, 'about' => true,
        'into' => true, 'onto' => true, 'over' => true, 'under' => true, 'through' => true,
        'against' => true, 'between' => true, 'among' => true, 'during' => true, 'before' => true,
        'after' => true, 'dans' => true, 'avec' => true, 'sans' => true, 'pour' => true,
        'sur' => true, 'sous' => true, 'chez' => true, 'entre' => true, 'depuis' => true,
        'in' => true, 'zu' => true, 'zur' => true, 'von' => true, 'vom' => true, 'mit' => true,
        'auf' => true, 'für' => true, 'über' => true, 'unter' => true, 'durch' => true,
        'da' => true, 'por' => true, 'para' => true, 'com' => true, 'sem' => true, 'sobre' => true,
        'entre' => true, 'até' => true, 'desde' => true, 'depois' => true, 'até' => true,
        // conjunctions and adverbs
        'y' => true, 'o' => true, 'u' => true, 'e' => true, 'pero' => true, 'porque' => true,
        'que' => true, 'como' => true, 'cuando' => true, 'donde' => true, 'si' => true,
        'aunque' => true, 'mientras' => true, 'entonces' => true, 'luego' => true,
        'and' => true, 'or' => true, 'but' => true, 'if' => true, 'then' => true, 'than' => true,
        'so' => true, 'because' => true, 'et' => true, 'mais' => true, 'donc' => true,
        'ou' => true, 'mais' => true, 'cependant' => true, 'ne' => true, 'que' => true,
        'come' => true, 'quando' => true, 'dove' => true, 'se' => true, 'anche' => true,
        'poi' => true, 'ma' => true, 'però' => true, 'quindi' => true, 'e' => true,
        'mas' => true, 'se' => true, 'porque' => true, 'quando' => true, 'onde' => true,
        'también' => true, 'tampoco' => true, 'muy' => true, 'más' => true, 'menos' => true,
        'también' => true, 'aussi' => true, 'très' => true, 'plus' => true, 'moins' => true,
        'sehr' => true, 'mehr' => true, 'weniger' => true, 'auch' => true, 'nur' => true,
        'também' => true, 'muito' => true, 'mais' => true, 'menos' => true, 'apenas' => true,
        'muy' => true, 'solo' => true, 'solamente' => true, 'también' => true,
        'ya' => true, 'todavía' => true, 'aún' => true, 'siempre' => true, 'nunca' => true,
        'ahora' => true, 'antes' => true, 'después' => true, 'durante' => true,
        'schon' => true, 'noch' => true, 'immer' => true, 'nie' => true, 'jetzt' => true,
        'vorher' => true, 'nachher' => true, 'già' => true, 'ancora' => true, 'sempre' => true,
        'mai' => true, 'adesso' => true, 'prima' => true, 'dopo' => true,
        'já' => true, 'ainda' => true, 'sempre' => true, 'nunca' => true, 'agora' => true,
        // auxiliaries and very common verbs
        'es' => true, 'son' => true, 'era' => true, 'fue' => true, 'fueron' => true,
        'sea' => true, 'ser' => true, 'estar' => true, 'está' => true, 'están' => true,
        'he' => true, 'has' => true, 'ha' => true, 'hay' => true, 'han' => true,
        'hemos' => true, 'hay' => true, 'was' => true, 'were' => true, 'is' => true,
        'are' => true, 'am' => true, 'be' => true, 'been' => true, 'being' => true,
        'do' => true, 'does' => true, 'did' => true, 'have' => true, 'has' => true,
        'had' => true, 'having' => true, 'will' => true, 'would' => true, 'can' => true,
        'could' => true, 'should' => true, 'may' => true, 'might' => true, 'must' => true,
        'est' => true, 'sont' => true, 'suis' => true, 'été' => true, 'avoir' => true,
        'ai' => true, 'as' => true, 'avons' => true, 'ont' => true, 'sera' => true,
        'essere' => true, 'sono' => true, 'avere' => true, 'sto' => true, 'ci' => true,
        'l' => true, 'lo' => true, 'gli' => true, 'ho' => true, 'hai' => true, 'ha' => true,
        // numbers written out
        'uno' => true, 'dos' => true, 'tres' => true, 'cuatro' => true, 'cinco' => true,
        'seis' => true, 'siete' => true, 'ocho' => true, 'nueve' => true, 'diez' => true,
        'one' => true, 'two' => true, 'three' => true, 'four' => true, 'five' => true,
        'six' => true, 'seven' => true, 'eight' => true, 'nine' => true, 'ten' => true,
        'un' => true, 'deux' => true, 'trois' => true, 'quatre' => true, 'cinq' => true,
        'six' => true, 'sept' => true, 'huit' => true, 'neuf' => true, 'dix' => true,
        'due' => true, 'tre' => true, 'quattro' => true, 'cinque' => true, 'sei' => true,
        'sete' => true, 'otto' => true, 'nove' => true, 'dieci' => true,
    ];

    /** Weight of a translated function word when measuring quality. */
    private const STOPWORD_WEIGHT = 0.25;

    /**
     * True when the word carries little meaning on its own (article,
     * preposition, auxiliary...). Used to score translations honestly.
     */
    public static function isStopword(string $word): bool
    {
        return isset(self::STOPWORDS[self::normalizeKey($word)]);
    }

    public static function stopwordWeight(): float
    {
        return self::STOPWORD_WEIGHT;
    }

/**
 * Mirrors only the case of the FIRST letter of the source.
 *
 * Used by languages whose values carry their own capitalization (German nouns
 * are always capitalised): "imágenes" must become "Bilder" and not "bilder",
 * while a sentence starting with a capital must still start with one.
 */
    public static function mirrorFirstLetter(string $source, string $translated): string
    {
        if ($source === '' || $translated === '') {
            return $translated;
        }

        $letters = (string) preg_replace('/[^\p{L}]/u', '', $source);
        if ($letters === '') {
            return $translated;
        }

        $first = self::substr($letters, 0, 1);

        return $first !== '' && $first === self::upper($first) && $first !== self::lower($first)
            ? self::ucfirst($translated)
            : $translated;
    }

    /**
     * Stable hash helper used to build cache keys.
     */
    public static function fingerprint(string ...$parts): string
    {
        return sha1(implode("\x1F", $parts));
    }
}
