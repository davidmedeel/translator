<?php

declare(strict_types=1);

namespace Medel\Translator\Engines;

use Medel\Translator\Support\Text;

/**
 * One directional dictionary (for example es => en) loaded from a local file.
 *
 * A table is composed of:
 *  - phrases: full sentence/expression lookups (always tried first);
 *  - words:   token level lookups used when no phrase matched;
 *  - rules:   optional post processing of the produced target string
 *             (contractions, contractions merging, word reordering...);
 *  - keep:    source words that must stay verbatim even if a translation
 *             exists (names, units, brand leftovers);
 *  - plural:  plural suffix rules for the target language.
 *
 * The resolution order for a fragment is:
 *   1. whole string phrase lookup;
 *   2. greedy longest phrase match over the token stream (up to 6 words);
 *   3. per word lookup with light morphological tolerance;
 *   4. whatever is still unknown is copied verbatim.
 *
 * Nothing in this class performs I/O beyond the initial file read, and no
 * method ever touches the network.
 */
final class DictionaryTable
{
    /** Maximum amount of words considered for a phrase match. */
    public const MAX_PHRASE_WORDS = 32;

    /**
     * Longest run of characters matched at once in scripts without word
     * separators (Japanese, Chinese).
     */
    public const MAX_CJK_CHARS = 64;

    /** @var string */
    private $from;

    /** @var string */
    private $to;

    /** @var string */
    private $name = '';

    /** @var array<string,string> */
    private $phrases = [];

    /** @var array<string,string> */
    private $words = [];

    /**
     * Accent preserving indexes.
     *
     * They are consulted before the folded ones so that pairs which only differ
     * by a diacritic keep working: Italian "e" (and) vs "è" (is), French
     * "a" (has) vs "à" (to), Spanish "de" vs "dé".
     *
     * @var array<string,string>
     */
    private $phrasesStrict = [];

    /** @var array<string,string> */
    private $wordsStrict = [];

    /** @var array<int,string> */
    private $keep = [];

    /**
     * When true the case of the value wins over the case of the source word.
     *
     * German nouns are always capitalised ("Gebäude"), so mirroring the
     * lowercase Spanish source would produce "gebäude".
     *
     * @var bool
     */
    private $keepValueCase = false;

    /** @var array<int,string> */
    private $dropSource = [];

    /** @var array<int,array<string,mixed>> */
    private $rules = [];

    /** @var string */
    private $pluralSuffix = 's';

    /** @var array<int,string> */
    private $pluralAfter = ['s', 'x', 'z', 'ch', 'sh'];

    /** @var array<string,array{value:string,plural:bool}>|null */
    private $lemmaCache = [];

    /**
     * Keep entries made of several words.
     *
     * The keep list is consulted token by token, so a brand made of several
     * words ("Medel Platforms") cannot be protected by it. Those entries are
     * masked before the resolution instead.
     *
     * @var array<string,bool>
     */
    private $keepPhrases = [];

    /** @var array<string,array<string,string>> memoized token keys (s = strict, f = folded) */
    private $keyCache = ['s' => [], 'f' => []];

    /** @var string */
    private $source = '';

    /** @var array<string,mixed> */
    private $meta = [];

    /**
     * @param array<string,mixed> $data
     */
    private function __construct(string $from, string $to, array $data = [])
    {
        $this->from = $from;
        $this->to = $to;

        if ($data === []) {
            return;
        }

        $this->name = isset($data['name']) ? (string) $data['name'] : $from . ' => ' . $to;
        $this->source = isset($data['source']) ? (string) $data['source'] : '';
        $this->meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];

        foreach (['phrases', 'words'] as $section) {
            $rows = isset($data[$section]) && is_array($data[$section]) ? $data[$section] : [];
            foreach ($rows as $rawKey => $value) {
                $value = (string) $value;
                if (trim((string) $rawKey) === '' || $value === '') {
                    continue;
                }

                $strict = Text::strictKey((string) $rawKey);
                $folded = Text::normalizeKey((string) $rawKey);

                if ($strict === '') {
                    continue;
                }

                if ($section === 'phrases') {
                    $this->phrases[$folded] = $value;
                    $this->phrasesStrict[$strict] = $value;
                } else {
                    $this->words[$folded] = $value;
                    $this->wordsStrict[$strict] = $value;
                }
            }
        }

        // Short phrases also work at word level (single word expressions).
        foreach ($this->phrases as $key => $value) {
            if (strpos($key, ' ') === false && !isset($this->words[$key])) {
                $this->words[$key] = $value;
            }
        }

        foreach ($this->phrasesStrict as $key => $value) {
            if (strpos($key, ' ') === false && !isset($this->wordsStrict[$key])) {
                $this->wordsStrict[$key] = $value;
            }
        }

        // Precomputed accent preserving indexes (used when merging tables).
        foreach (['phrases_strict', 'words_strict'] as $input) {
            if (!isset($data[$input]) || !is_array($data[$input])) {
                continue;
            }

            $target = $input === 'phrases_strict' ? 'phrasesStrict' : 'wordsStrict';

            foreach ($data[$input] as $key => $value) {
                $key = (string) $key;
                $value = (string) $value;

                if ($key === '' || $value === '') {
                    continue;
                }

                $this->{$target}[$key] = $value;
            }
        }

        $this->keepValueCase = !empty($data['keep_value_case']);

        if (isset($data['keep']) && is_array($data['keep'])) {
            foreach ($data['keep'] as $term) {
                $term = Text::normalizeKey((string) $term);
                if ($term === '') {
                    continue;
                }

                $this->keep[] = $term;

                // Multi word entries cannot be matched by a single token, so
                // they are masked before the resolution: a brand such as
                // "Medel Platforms" has to come out byte exact, otherwise the
                // second word is looked up on its own ("Platforms" becoming
                // "Bahnsteige" in German).
                if (strpos($term, ' ') !== false) {
                    $this->keepPhrases[$term] = true;
                }
            }
        }

        if (isset($data['drop_source']) && is_array($data['drop_source'])) {
            foreach ($data['drop_source'] as $term) {
                $term = Text::normalizeKey((string) $term);
                if ($term !== '') {
                    $this->dropSource[] = $term;
                }
            }
        }

        if (isset($data['rules']) && is_array($data['rules'])) {
            foreach ($data['rules'] as $rule) {
                if (is_array($rule)) {
                    $this->rules[] = $rule;
                }
            }
        }

        if (isset($data['plural']) && is_array($data['plural'])) {
            if (isset($data['plural']['suffix'])) {
                $this->pluralSuffix = (string) $data['plural']['suffix'];
            }
            if (isset($data['plural']['after']) && is_array($data['plural']['after'])) {
                $this->pluralAfter = array_map('strval', $data['plural']['after']);
            }
        }
    }

    /**
     * Builds a table from the decoded content of a data file.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data, string $from, string $to, string $source = ''): self
    {
        if (!isset($data['source'])) {
            $data['source'] = $source;
        }

        return new self($from, $to, $data);
    }

    public function from(): string
    {
        return $this->from;
    }

    public function to(): string
    {
        return $this->to;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return array<string,mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function entryCount(): int
    {
        return count($this->phrases) + count($this->words);
    }

    public function phraseCount(): int
    {
        return count($this->phrases);
    }

    public function wordCount(): int
    {
        return count($this->words);
    }

    /**
     * True when the table has no usable entry at all.
     */
    public function isEmpty(): bool
    {
        return $this->phrases === [] && $this->words === [];
    }

    /**
     * Raw content of the table, ready to be merged or dumped.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'phrases' => $this->phrases,
            'words' => $this->words,
            'phrases_strict' => $this->phrasesStrict,
            'words_strict' => $this->wordsStrict,
            'keep' => $this->keep,
            'keep_value_case' => $this->keepValueCase,
            'drop_source' => $this->dropSource,
            'rules' => $this->rules,
            'plural' => [
                'suffix' => $this->pluralSuffix,
                'after' => $this->pluralAfter,
            ],
            'meta' => $this->meta,
        ];
    }

    public function hasPhrase(string $phrase): bool
    {
        return isset($this->phrasesStrict[Text::strictKey($phrase)])
            || isset($this->phrases[Text::normalizeKey($phrase)]);
    }

    public function phrase(string $phrase): ?string
    {
        $hit = $this->lookupPhrase($phrase);
        if ($hit !== null) {
            return $hit;
        }

        $word = $this->lookupWordStrictValue($phrase);
        if ($word !== null) {
            return $word;
        }

        $hit = $this->lookupWord($phrase);

        return $hit === null ? null : $hit['value'];
    }

    /**
     * Cached lookup keys of a single token.
     *
     * The same words appear in every fragment of a page, and normalizing them
     * with the regex engine is not free.
     */
    private function tokenStrictKey(string $token): string
    {
        if (!isset($this->keyCache['s'][$token])) {
            $this->keyCache['s'][$token] = Text::strictKey($token);
        }

        return $this->keyCache['s'][$token];
    }

    private function tokenFoldedKey(string $token): string
    {
        if (!isset($this->keyCache['f'][$token])) {
            $this->keyCache['f'][$token] = Text::normalizeKey($token);
        }

        return $this->keyCache['f'][$token];
    }

    /**
     * Longest dictionary match over a run of characters written without word
     * separators (Japanese, Chinese).
     *
     * @param array<int,string>    $tokens
     * @param array<int,int>       $positions
     *
     * @return array{length:int,value:string,phrase:bool}|null
     */
    private function matchCjkRun(array $tokens, array $positions, int $cursor): ?array
    {
        $first = $positions[$cursor];
        $available = count($positions) - $cursor;

        for ($span = min(self::MAX_CJK_CHARS, $available); $span >= 1; $span--) {
            $last = $positions[$cursor + $span - 1];

            // The run has to be contiguous: a latin word or a space in between
            // means the characters are not one dictionary key.
            if ($last - $first !== $span - 1) {
                break;
            }

            $piece = '';
            for ($i = $first; $i <= $last; $i++) {
                if (!Text::isCjkUnit($tokens[$i])) {
                    continue 2;
                }
                $piece .= $tokens[$i];
            }

            $strictKey = Text::strictKey($piece);
            if ($strictKey !== '' && isset($this->phrasesStrict[$strictKey])) {
                return ['length' => $span, 'value' => $this->phrasesStrict[$strictKey], 'phrase' => true];
            }

            $key = Text::normalizeKey($piece);
            if ($key !== '' && isset($this->phrases[$key])) {
                return ['length' => $span, 'value' => $this->phrases[$key], 'phrase' => true];
            }

            if ($span === 1) {
                $word = $this->lookupWordToken($tokens[$first]);
                if ($word !== null) {
                    return ['length' => 1, 'value' => $word['value'], 'phrase' => false];
                }
            }
        }

        return null;
    }

    /**
     * Phrase lookup: accent preserving first, folded index second.
     */
    private function lookupPhrase(string $phrase): ?string
    {
        $strict = Text::strictKey($phrase);
        if ($strict !== '' && isset($this->phrasesStrict[$strict])) {
            return $this->phrasesStrict[$strict];
        }

        $folded = Text::normalizeKey($phrase);

        return $folded === '' ? null : ($this->phrases[$folded] ?? null);
    }

    public function word(string $word): ?string
    {
        $hit = $this->lookupWord($word);

        return $hit === null ? null : $hit['value'];
    }

    /**
     * Returns a copy of the table with extra "keep" terms.
     *
     * Kept terms are copied verbatim but still take part in phrase matching.
     * That is what distinguishes them from protected terms: those are masked
     * out before the engine sees the text, so no dictionary phrase can span
     * them and a sentence such as "Instala Tokify en X dominios" falls back to
     * word by word translation.
     *
     * The receiver is never modified, since tables are shared between calls.
     *
     * @param array<int,string> $terms
     */
    public function withKeep(array $terms): self
    {
        $clean = [];

        foreach ($terms as $term) {
            $key = Text::normalizeKey((string) $term);
            if ($key !== '') {
                $clean[] = $key;
            }
        }

        if ($clean === []) {
            return $this;
        }

        $clone = clone $this;

        foreach ($clean as $key) {
            if (!in_array($key, $clone->keep, true)) {
                $clone->keep[] = $key;
            }

            if (strpos($key, ' ') !== false) {
                $clone->keepPhrases[$key] = true;
            }
        }

        return $clone;
    }

    /**
     * Applies the case policy of the target language to a whole fragment value.
     *
     * German nouns stay capitalised (keep_value_case) and the first letter
     * still mirrors the source, but the ALL CAPS mirroring must not shout a
     * 100 word paragraph.
     */
    private function mirrorValueCase(string $source, string $value): string
    {
        if ($this->keepValueCase) {
            return Text::mirrorFirstLetter($source, $value);
        }

        return $this->looksShouted($source) ? Text::upper($value) : $value;
    }

    /**
     * True when the source fragment is written in capitals.
     */
    private function looksShouted(string $text): bool
    {
        $letters = (string) preg_replace('/[^\p{L}]/u', '', $text);

        if (mb_strlen($letters, 'UTF-8') < 2) {
            return false;
        }

        return $letters === Text::upper($letters) && $letters === Text::lower($letters) ? false : $letters === Text::upper($letters);
    }

    /**
     * Translates a plain text fragment.
     *
     * @return array{text:string,matched:bool,coverage:float,matched_tokens:int,tokens:int}
     */
    public function translate(string $text): array
    {
        if ($this->keepPhrases !== []) {
            [$masked, $phrases] = $this->maskKeepPhrases($text);
            $outcome = $this->resolve($masked);
            $outcome['text'] = $this->unmaskKeepPhrases($outcome['text'], $phrases);

            return $outcome;
        }

        return $this->resolve($text);
    }

    /**
     * Replaces the multi word keep terms by placeholders.
     *
     * A dictionary phrase may not cross them and the token lookup never sees
     * the inside of a brand name, so "Medel Platforms" cannot become
     * "Medel Bahnsteige".
     *
     * @return array{0:string,1:array<string,string>} text and the placeholder map
     */
    private function maskKeepPhrases(string $text): array
    {
        $map = [];
        $index = 0;
        $terms = array_keys($this->keepPhrases);

        usort($terms, static function (string $a, string $b): int {
            $diff = Text::length($b) - Text::length($a);
            return $diff !== 0 ? $diff : strcmp($a, $b);
        });

        foreach ($terms as $term) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu';

            $masked = preg_replace_callback(
                $pattern,
                static function (array $m) use (&$map, &$index): string {
                    $key = sprintf(Text::PLACEHOLDER_FORMAT, $index++);
                    $map[$key] = $m[0];

                    return $key;
                },
                $text
            );

            if ($masked !== null) {
                $text = $masked;
            }
        }

        return [$text, $map];
    }

    /**
     * @param array<string,string> $map
     */
    private function unmaskKeepPhrases(string $text, array $map): string
    {
        if ($map === []) {
            return $text;
        }

        $keys = array_keys($map);
        usort($keys, static function (string $a, string $b): int {
            $diff = strlen($b) - strlen($a);
            return $diff !== 0 ? $diff : strcmp($a, $b);
        });

        $replacements = [];
        foreach ($keys as $key) {
            $replacements[$key] = $map[$key];
        }

        return strtr($text, $replacements);
    }

    /**
     * The resolution itself, without the multi word keep masking.
     *
     * @return array{text:string,matched:bool,coverage:float,matched_tokens:int,tokens:int}
     */
    private function resolve(string $text): array
    {
        $text = $this->dropSourceWords($text);

        $key = Text::normalizeKey($text);

        if ($key === '') {
            return ['text' => $text, 'matched' => false, 'coverage' => 0.0, 'weighted' => 0.0, 'matched_tokens' => 0, 'tokens' => 0];
        }

        // Whole fragment fast path: one hash lookup, whatever its length.
        //
        // normalizeKey() folds punctuation into spaces, so a 100 word
        // paragraph full of commas resolves in a single lookup instead of the
        // token loop. It used to be gated by isFullyKeyed(), which rejected any
        // text with a comma or a period, i.e. every real sentence.
        //
        // Punctuation may be folded away because the value brings its own, but
        // an emoji, an arrow or a masked entity must not: the replacement would
        // swallow it. That is what isPhraseSafe() decides.
        if (Text::isPhraseSafe($text)) {
            $strictHit = Text::isFullyKeyed($text) ? $this->lookupPhraseStrict($text) : null;
            if ($strictHit !== null) {
                return $this->success($this->withEdges($text, Text::restoreCase($text, $strictHit)), 1, 1);
            }

            if (isset($this->phrases[$key])) {
                return $this->success($this->withEdges($text, $this->mirrorValueCase($text, $this->phrases[$key])), 1, 1);
            }
        }

        if (Text::isFullyKeyed($text)) {
            $strictWord = $this->lookupWordStrictValue($text);
            if ($strictWord !== null) {
                return $this->success($this->withEdges($text, Text::restoreCase($text, $strictWord)), 1, 1);
            }

            if (isset($this->words[$key])) {
                return $this->success($this->withEdges($text, Text::restoreCase($text, $this->words[$key])), 1, 1);
            }
        }

        return $this->translateTokens($text);
    }

    /**
     * Keeps the original leading/trailing whitespace around a replacement.
     */
    private function withEdges(string $original, string $translated): string
    {
        if (preg_match('/^(\s*)([\s\S]*?)(\s*)$/u', $original, $m) !== 1) {
            return $translated;
        }

        return $m[1] . $translated . $m[3];
    }

    /**
     * Removes the source terms declared in "drop_source".
     *
     * Essential when a language is used as a pivot: the German articles would
     * otherwise travel through the English bridge and show up in the final
     * French/Italian/Portuguese text ("der Panier" instead of "le panier").
     */
    private function dropSourceWords(string $text): string
    {
        if ($this->dropSource === [] || $text === '') {
            return $text;
        }

        foreach ($this->dropSource as $word) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/iu';
            $cleaned = preg_replace($pattern, '', $text);

            if (is_string($cleaned)) {
                $text = $cleaned;
            }
        }

        $text = (string) preg_replace('/[ \t]{2,}/u', ' ', $text);

        return trim($text, " \t\n\r\0\x0B.,;:!?");
    }

    /**
     * Token by token resolution with longest phrase matching.
     *
     * @return array{text:string,matched:bool,coverage:float,matched_tokens:int,tokens:int}
     */
    private function translateTokens(string $text): array
    {
        $tokens = Text::tokenize($text);
        if ($tokens === []) {
            return ['text' => $text, 'matched' => false, 'coverage' => 0.0, 'weighted' => 0.0, 'matched_tokens' => 0, 'tokens' => 0];
        }

$positions = $this->translatablePositions($tokens);
        if ($positions === []) {
            return ['text' => $text, 'matched' => false, 'coverage' => 0.0, 'weighted' => 0.0, 'matched_tokens' => 0, 'tokens' => 0];
        }

        [$contentPositions, $functionPositions] = $this->splitPositions($tokens, $positions);
        $isFunction = array_flip($functionPositions);

        $out = '';
        $written = 0;
        $matchedTokens = 0;
        $weightedMatched = 0.0;
        $weightedTotal = 0.0;
        $phraseHits = [];
        $cursor = 0;
        $total = count($positions);

        foreach ($positions as $position) {
            $weightedTotal += isset($isFunction[$position]) ? Text::stopwordWeight() : 1.0;
        }

        while ($cursor < $total) {
            $length = 0;
            $replacement = null;
            $fromPhrase = false;
            $startToken = $positions[$cursor];

            // Japanese, Chinese and Korean are written without word separators:
            // the dictionary replaces the longest run of characters it knows,
            // which is how a segmenter behaves.
            if (Text::isCjkUnit($tokens[$startToken])) {
                $hit = $this->matchCjkRun($tokens, $positions, $cursor);
                if ($hit !== null) {
                    $length = $hit['length'];
                    $replacement = $hit['value'];
                    $fromPhrase = $hit['phrase'];
                }
            }

            if ($replacement === null) {
                // Longest phrase match in a single forward pass.
                //
                // Rebuilding the key for every span from 32 down to 1 was
                // quadratic: a 100 word paragraph spent most of its time
                // concatenating strings (73 ms per node). Walking the tokens
                // once, appending each word to a growing key, turns it into a
                // couple of hash lookups per token.
                $strictKey = '';
                $foldedKey = '';
                $words = 0;
                $limit = min(self::MAX_PHRASE_WORDS, $total - $cursor);
                $tokenCount = count($tokens);
                $joiner = '';

                for ($i = $startToken; $i < $tokenCount && $words < $limit; $i++) {
                    $token = $tokens[$i];

                    // A protected term is masked on purpose: no phrase may
                    // replace the span that contains one of them.
                    if (Text::isPlaceholder($token)) {
                        break;
                    }

                    if (!Text::isWord($token)) {
                        // Punctuation and blanks are skipped ("Hola, mundo"),
                        // but anything else (emoji, arrows) would be swallowed
                        // by the replacement of the phrase.
                        if (!Text::isPhraseSafe($token)) {
                            break;
                        }

                        // Hyphens and apostrophes belong to the word for the
                        // dictionary: "D-ID" and "l'application".
                        if (Text::isWordJoiner($token)) {
                            $joiner .= $token;
                        }

                        continue;
                    }

                    $words++;
                    $space = $joiner !== '' ? $joiner : ($words > 1 ? ' ' : '');
                    $joiner = '';
                    $strictKey .= $space . $this->tokenStrictKey($token);
                    $foldedKey .= $space . $this->tokenFoldedKey($token);

                    // Accent preserving match first: "è" must not resolve to
                    // the entry of the plain "e". The last hit of the walk is
                    // the longest one, which is the one that wins.
                    if ($strictKey !== '' && isset($this->phrasesStrict[$strictKey])) {
                        $length = $words;
                        $replacement = $this->phrasesStrict[$strictKey];
                        $fromPhrase = true;
                        continue;
                    }

                    if ($foldedKey !== '' && isset($this->phrases[$foldedKey])) {
                        $length = $words;
                        $replacement = $this->phrases[$foldedKey];
                        $fromPhrase = true;
                    }
                }
            }

            if ($replacement === null) {
                // Word level hit: casing and punctuation are handled inside.
                // Numbers and versions are not vocabulary.
                $hit = $this->isKept($tokens[$startToken]) || Text::hasDigit($tokens[$startToken])
                    ? null
                    : $this->lookupWordToken($tokens[$startToken]);
                if ($hit !== null) {
                    $length = 1;
                    $replacement = $hit['value'];
                }
            }

            if ($replacement === null) {
                $cursor++;
                continue;
            }

            if ($fromPhrase) {
                // Phrase hits mirror the casing of the original fragment. The
                // slice must cover the whole span (separators included), not
                // just the first $length tokens of the array.
                $lastToken = $positions[$cursor + $length - 1];
                $slice = implode('', array_slice($tokens, $startToken, $lastToken - $startToken + 1));
            if ($this->keepValueCase) {
                $replacement = Text::mirrorFirstLetter($slice, $replacement);
            } else {
                $replacement = Text::restoreCase($slice, $replacement);
            }
            }

$out .= implode('', array_slice($tokens, $written, $startToken - $written));
            $out .= $replacement;

            $written = $positions[$cursor + $length - 1] + 1;
            $matchedTokens += $length;

            if ($fromPhrase) {
                $phraseHits[] = $length;
            }

            for ($i = $cursor; $i < $cursor + $length; $i++) {
                $weightedMatched += isset($isFunction[$positions[$i]]) ? Text::stopwordWeight() : 1.0;
            }

            $cursor += $length;
        }

        $out .= implode('', array_slice($tokens, $written));

        $phraseTokens = 0;

        foreach ($phraseHits as $hit) {
            $phraseTokens += $hit;
        }

        if ($matchedTokens === 0) {
            return [
                'text' => $text,
                'matched' => false,
                'coverage' => 0.0,
                'weighted' => 0.0,
                'matched_tokens' => 0,
                'phrase_tokens' => 0,
                'tokens' => $total,
            ];
        }

        $out = $this->applyRules($out);

        $outcome = $this->success($out, $matchedTokens, $total);
        $outcome['weighted'] = $weightedTotal > 0 ? min(1.0, $weightedMatched / $weightedTotal) : 0.0;
        $outcome['phrase_tokens'] = $phraseTokens;

        return $outcome;
    }

    /**
     * Indices of the tokens eligible for the phrase matcher: real words with
     * letters, without digits and that are not placeholders.
     *
     * "keep" words ARE included so a phrase can span them (the Spanish
     * "extensiones de PHP necesarias" must still match with "php" in keep);
     * they are simply never translated on their own.
     *
     * @param array<int,string> $tokens
     *
     * @return array<int,int>
     */
    private function translatablePositions(array $tokens): array
    {
        $positions = [];

        foreach ($tokens as $index => $token) {
            if (!Text::isWord($token) || !Text::hasLetter($token)) {
                continue;
            }

            if (Text::isPlaceholder($token)) {
                continue;
            }

            // Paths, file names and URLs are never vocabulary.
            if (Text::isPathLike($token)) {
                continue;
            }

            // Tokens with digits take part in phrases ("Amazon S3", "GPT-4",
            // "H264") but are never looked up as words, so "8.2" or a version
            // number is left alone.
            $positions[] = $index;
        }

        return $positions;
    }

    /**
     * Splits the translatable tokens into content words and function words.
     *
     * @param array<int,string> $tokens
     *
     * @return array{0:array<int,int>,1:array<int,int>}
     */
    private function splitPositions(array $tokens, array $positions): array
    {
        $content = [];
        $function = [];

        foreach ($positions as $index) {
            if (Text::isStopword($tokens[$index])) {
                $function[] = $index;
            } else {
                $content[] = $index;
            }
        }

        return [$content, $function];
    }

    private function isKept(string $token): bool
    {
        return $this->keep !== [] && in_array(Text::normalizeKey($token), $this->keep, true);
    }

    /**
     * Lookup key of a phrase spanning $first..$last token indices.
     *
     * @param array<int,string> $tokens
     * @param bool               $fold true = fold accents, false = keep them
     */
    private function phraseKey(array $tokens, int $first, int $last, bool $fold = true): string
    {
        $parts = [];
        $joiner = '';

        for ($i = $first; $i <= $last; $i++) {
            // Protected terms are masked before the engine sees the text, and
            // their whole point is to stay byte exact: a phrase must never
            // replace the span that contains one of them. They look like words
            // ("__NOTRANSLATE_0__"), so this check comes first.
            if (Text::isPlaceholder($tokens[$i])) {
                return '';
            }

            if (Text::isWord($tokens[$i])) {
                $key = $fold
                    ? Text::normalizeKey($tokens[$i])
                    : Text::strictKey($tokens[$i]);

                // Hyphens and apostrophes are part of the word for the
                // dictionary ("D-ID", "l'application") even though the
                // tokenizer splits them into separate units.
                if ($joiner !== '' && $parts !== []) {
                    $parts[count($parts) - 1] .= $joiner . $key;
                    $joiner = '';
                    continue;
                }

                $parts[] = $key;
                continue;
            }

            // Punctuation and blanks are simply skipped ("Hola, mundo"), but a
            // span containing anything else (emoji, arrows, symbols) would lose
            // that character when the phrase value replaces the whole span.
            if (!Text::isPhraseSafe($tokens[$i])) {
                return '';
            }

            if (Text::isWordJoiner($tokens[$i])) {
                $joiner = $tokens[$i];
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Word lookup preserving the original punctuation and casing.
     */
    private function lookupWordToken(string $token): ?array
    {
        [$prefix, $core, $suffix] = Text::splitEdges($token);

        if ($core === '') {
            return null;
        }

        $value = $this->lookupWordStrictValue($core);
        if ($value === null) {
            $hit = $this->lookupWord($core);
            $value = $hit === null ? null : $hit['value'];
        }

        if ($value === null) {
            return null;
        }

        $value = $this->keepValueCase
            ? Text::mirrorFirstLetter($core, $value)
            : Text::restoreCase($core, $value);

        return ['value' => $prefix . $value . $suffix];
    }

    /**
     * Accent preserving single word lookup (phrases then words).
     */
    private function lookupWordStrictValue(string $word): ?string
    {
        $key = Text::strictKey($word);

        if ($key === '') {
            return null;
        }

        return $this->wordsStrict[$key] ?? $this->phrasesStrict[$key] ?? null;
    }

    /**
     * Accent preserving phrase lookup of a whole fragment.
     */
    private function lookupPhraseStrict(string $text): ?string
    {
        $key = Text::strictKey($text);

        if ($key === '' || strpos($key, ' ') === false) {
            return null;
        }

        return $this->phrasesStrict[$key] ?? null;
    }

    /**
     * Word lookup with light morphological tolerance:
     *   "casas" -> "casa" -> "houses" (the removed plural suffix is re-applied).
     *
     * @return array{value:string,plural:bool}|null
     */
    private function lookupWord(string $word): ?array
    {
        $key = Text::normalizeKey($word);
        if ($key === '') {
            return null;
        }

        if (isset($this->lemmaCache[$key])) {
            $cached = $this->lemmaCache[$key];

            return $cached === null ? null : $cached;
        }

        $resolved = $this->resolveWord($key);
        $this->lemmaCache[$key] = $resolved;

        return $resolved;
    }

    /**
     * @return array{value:string,plural:bool}|null
     */
    private function resolveWord(string $key): ?array
    {
        if (isset($this->words[$key])) {
            return ['value' => $this->words[$key], 'plural' => false];
        }

        foreach ($this->variants($key) as $variant) {
            if (!isset($this->words[$variant['key']])) {
                continue;
            }

            $value = $this->words[$variant['key']];
            if ($variant['plural']) {
                $value = $this->pluralize($value);
            }

            return ['value' => $value, 'plural' => $variant['plural']];
        }

        return null;
    }

    /**
     * Singular/plural tolerant candidates for a source word.
     *
     * The candidates only remove plural endings, never consonants: "docs" may
     * become "doc", but never "do" (which in English is a verb of its own and
     * would turn the folder /docs into /tune).
     *
     * @return array<int,array{key:string,plural:bool}>
     */
    private function variants(string $key): array
    {
        $variants = [];
        $length = Text::length($key);

        if ($length > 3 && substr($key, -2) === 'es') {
            $variants[] = ['key' => substr($key, 0, -2), 'plural' => true];
        }

        if ($length > 2 && substr($key, -1) === 's' && substr($key, -2) !== 'ss') {
            $variants[] = ['key' => substr($key, 0, -1), 'plural' => true];
        }

        return $variants;
    }

    /**
     * Applies the target language plural suffix.
     */
    private function pluralize(string $value): string
    {
        if ($this->pluralSuffix === '') {
            return $value;
        }

        if ($this->pluralAfter !== []) {
            foreach ($this->pluralAfter as $condition) {
                if (substr($value, -strlen($condition)) === $condition) {
                    return $value . $this->pluralSuffix;
                }
            }
        }

        return $value . $this->pluralSuffix;
    }

    /**
     * Applies the declarative rules of the data file, never touching the
     * placeholders that guard protected terms.
     */
    private function applyRules(string $text): string
    {
        if ($this->rules === []) {
            return $text;
        }

        $chunks = preg_split('/(__[A-Z]{2,}[A-Z0-9]*_\d+__)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($chunks === false) {
            return $text;
        }

        foreach ($chunks as $index => $chunk) {
            if ($index % 2 === 1) {
                continue;
            }
            $chunks[$index] = $this->applyRulesToChunk($chunk);
        }

        return implode('', $chunks);
    }

    private function applyRulesToChunk(string $text): string
    {
        foreach ($this->rules as $rule) {
            if (isset($rule['terms']) && is_array($rule['terms'])) {
                $terms = array_map(
                    static function ($term): string {
                        return (string) $term;
                    },
                    $rule['terms']
                );

                $replacement = isset($rule['replacement']) ? (string) $rule['replacement'] : '';
                $text = str_ireplace($terms, $replacement, $text);
                continue;
            }

            if (isset($rule['pattern'])) {
                $replacement = isset($rule['replacement']) ? (string) $rule['replacement'] : '';
                $flags = !empty($rule['case_insensitive']) ? 'iu' : 'u';
                $result = @preg_replace('/' . $rule['pattern'] . '/' . $flags, $replacement, $text);
                if (is_string($result)) {
                    $text = $result;
                }
            }
        }

        return $text;
    }

    /**
     * @return array{text:string,matched:bool,coverage:float,matched_tokens:int,tokens:int}
     */
    private function success(string $text, int $matchedTokens, int $tokens): array
    {
        return [
            'text' => $text,
            'matched' => true,
            'coverage' => $tokens > 0 ? $matchedTokens / $tokens : 0.0,
            'weighted' => 1.0,
            'matched_tokens' => $matchedTokens,
            'tokens' => $tokens,
        ];
    }
}