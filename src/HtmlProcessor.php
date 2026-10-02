<?php

declare(strict_types=1);

namespace Medel\Translator;

use Medel\Translator\Support\Text;

/**
 * HTML aware text pipeline.
 *
 * The processor never re-serializes the markup: it locates the *visible text
 * nodes* inside the raw source, hands them to a callback and stitches the
 * results back using the original byte offsets. Tags, attributes, comments,
 * doctypes, entity references and whitespace therefore survive untouched.
 *
 * It also owns the "protected terms" mechanism: every registered brand, product
 * name or reserved word is swapped for a temporary placeholder
 * (__NOTRANSLATE_0__) before translation and restored verbatim afterwards.
 */
final class HtmlProcessor
{
    /** Tags whose content is never translated by default. */
    public const DEFAULT_SKIP_TAGS = [
        'script',
        'style',
        'code',
        'pre',
        'textarea',
        'noscript',
        'title',
        'option',
        'svg',
        'math',
    ];

    /** Elements that never have a closing tag. */
    private const VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /** Every legal "tag like" construct, matched in one single pass. */
    private const TAG_PATTERN = '/\G(?:<!--.*?-->|<!\[CDATA\[.*?\]\]>|<\?.*?\?>|<!\[^>]*>|<\s*!?[a-zA-Z][^>]*?>|<\/\s*[a-zA-Z][^>]*?>)/s';

    /** Literal non breaking space (U+00A0). */
    private const NBSP = "\xC2\xA0";

    /** @var array<int,string> */
    private $skipTags;

    /** @var bool */
    private $translateSkipped;

    /** @var array<int,string> */
    private $protectedTerms = [];

    /**
     * Multi word "keep" terms, masked like protected ones.
     *
     * @var array<int,string>
     */
    private $keepPhrases = [];

    /** @var string */
    private $placeholderTemplate = '__NOTRANSLATE_%d__';

    /** @var int */
    private $protectedHits = 0;

    /** @var int */
    private $nodeCount = 0;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(array $options = [])
    {
        $skip = isset($options['skip_tags']) && is_array($options['skip_tags'])
            ? $options['skip_tags']
            : self::DEFAULT_SKIP_TAGS;

        $this->skipTags = [];
        foreach ($skip as $tag) {
            $this->skipTags[] = self::lower((string) $tag);
        }

        $this->translateSkipped = !empty($options['translate_skipped_tags']);
        $this->placeholderTemplate = isset($options['placeholder'])
            ? (string) $options['placeholder']
            : '__NOTRANSLATE_%d__';

        if (isset($options['protected_terms']) && is_array($options['protected_terms'])) {
            $this->setProtectedTerms($options['protected_terms']);
        }

        if (isset($options['keep']) && is_array($options['keep'])) {
            $this->setKeepPhrases($options['keep']);
        }
    }

    /**
     * Cheap check: does this string need the HTML pipeline at all?
     */
    public function looksLikeHtml(string $value): bool
    {
        return Text::containsMarkup($value);
    }

    /**
     * @param array<int,string> $terms
     */
    public function setProtectedTerms(array $terms): void
    {
        $this->protectedTerms = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term !== '') {
                $this->protectedTerms[] = $term;
            }
        }

        // Longest first so "WordPress Pro" wins over "WordPress".
        usort(
            $this->protectedTerms,
            static function (string $a, string $b): int {
                $diff = Text::length($b) - Text::length($a);

                return $diff !== 0 ? $diff : strcmp($a, $b);
            }
        );
    }

    /**
     * @return array<int,string>
     */
    public function getProtectedTerms(): array
    {
        return $this->protectedTerms;
    }

    /**
     * Registers multi word "keep" terms.
     *
     * The "keep" list of a dictionary works token by token, which is what
     * allows a phrase to cross it ("publicación en YouTube"). A brand made of
     * several words needs the opposite: "Medel Platforms" must come out byte
     * exact, otherwise the second word is looked up and "Platforms" becomes
     * "Bahnsteige" in German. Those terms are therefore masked like protected
     * ones, so nothing inside them can ever change.
     *
     * @param array<int,string> $terms
     */
    public function setKeepPhrases(array $terms): void
    {
        $this->keepPhrases = [];

        foreach ($terms as $term) {
            $term = trim((string) $term);

            // Only multi word terms need masking; single words are handled by
            // the keep list of the dictionary.
            if ($term !== '' && preg_match('/\s/u', $term) === 1) {
                $this->keepPhrases[] = $term;
            }
        }

        usort(
            $this->keepPhrases,
            static function (string $a, string $b): int {
                $diff = Text::length($b) - Text::length($a);
                return $diff !== 0 ? $diff : strcmp($a, $b);
            }
        );
    }

    /**
     * @return array<int,string>
     */
    public function getKeepPhrases(): array
    {
        return $this->keepPhrases;
    }

    public function addProtectedTerm(string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $this->setProtectedTerms(array_merge($this->protectedTerms, [$term]));
    }

    /**
     * Replaces every protected term by a temporary placeholder.
     *
     * Matching is case insensitive but the *original* casing is restored, and
     * terms already masked are never touched twice.
     *
     * @return array{text:string,map:array<string,string>}
     */
    public function protect(string $value): array
    {
        if ($value === '') {
            return ['text' => $value, 'map' => []];
        }

        $terms = array_merge($this->protectedTerms, $this->keepPhrases);

        if ($terms === []) {
            return ['text' => $value, 'map' => []];
        }

        $map = [];
        $index = 0;
        $template = $this->placeholderTemplate;

        foreach ($terms as $term) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu';

            $value = (string) preg_replace_callback(
                $pattern,
                static function (array $m) use (&$map, &$index, $template): string {
                    $key = sprintf($template, $index++);
                    $map[$key] = $m[0];

                    return $key;
                },
                $value
            );
        }

        if ($map !== []) {
            $this->protectedHits += count($map);
        }

        return ['text' => $value, 'map' => $map];
    }

    /**
     * Puts the protected terms back, exactly as they were written.
     *
     * @param array<string,string> $map
     */
    public function restore(string $value, array $map): string
    {
        if ($map === []) {
            return $value;
        }

        // Longest placeholder first: __NOTRANSLATE_10__ before __NOTRANSLATE_1__.
        $keys = array_keys($map);
        usort($keys, static function (string $a, string $b): int {
            $diff = strlen($b) - strlen($a);

            return $diff !== 0 ? $diff : strcmp($a, $b);
        });

        $replacements = [];
        foreach ($keys as $key) {
            $replacements[$key] = $map[$key];
        }

        return strtr($value, $replacements);
    }

    /**
     * Translates every visible text node of an HTML fragment.
     *
     * The callback receives the raw text node (with HTML entities masked) and
     * must return a string. Returning the input untouched keeps that node as-is.
     *
     * @param callable(string):string $translator
     * @param array<string,mixed>    $options
     */
    public function translateNodes(string $html, callable $translator, array $options = []): string
    {
        $this->nodeCount = 0;

        $nodes = $this->textNodes($html, $options);
        if ($nodes === []) {
            return $html;
        }

        $out = '';
        $cursor = 0;

        foreach ($nodes as $node) {
            $out .= substr($html, $cursor, $node['start'] - $cursor);

            $entityMap = [];
            $masked = Text::maskEntities($node['text'], $entityMap);

            $replacement = (string) $translator($masked);
            $out .= Text::unmaskEntities($replacement, $entityMap);

            $cursor = $node['end'];
            $this->nodeCount++;
        }

        return $out . substr($html, $cursor);
    }

    /**
     * Returns the translatable text nodes of a fragment with their byte
     * offsets in the original source.
     *
     * @param array<string,mixed> $options
     *
     * @return array<int,array{start:int,end:int,text:string,tag:string}>
     */
    public function textNodes(string $html, array $options = []): array
    {
        $skipTags = $this->skipTags;
        if (isset($options['skip_tags']) && is_array($options['skip_tags'])) {
            $skipTags = [];
            foreach ($options['skip_tags'] as $tag) {
                $skipTags[] = self::lower((string) $tag);
            }
        }

        $translateSkipped = array_key_exists('translate_skipped_tags', $options)
            ? (bool) $options['translate_skipped_tags']
            : $this->translateSkipped;

        $nodes = [];
        $length = strlen($html);
        $cursor = 0;
        $stack = [];

        while ($cursor < $length) {
            $nextTagPos = strpos($html, '<', $cursor);
            if ($nextTagPos === false) {
                break;
            }

            if (!preg_match(self::TAG_PATTERN, $html, $match, 0, $nextTagPos)) {
                // A stray "<" used as a mathematical symbol.
                $this->appendNode($nodes, $cursor, $nextTagPos, $html, $stack);
                $cursor = $nextTagPos + 1;
                continue;
            }

            $tag = $match[0];
            $this->appendNode($nodes, $cursor, $nextTagPos, $html, $stack);

            $tagEnd = $nextTagPos + strlen($tag);
            $isClosing = (bool) preg_match('/^<\s*\//', $tag);
            $name = '';

            if (preg_match('/^<\s*\/?\s*([a-zA-Z][a-zA-Z0-9:_-]*)/', $tag, $nameMatch)) {
                $name = self::lower($nameMatch[1]);
            }

            $selfClosing = substr(rtrim($tag), -1) === '/';

            if ($isClosing) {
                if (array_key_exists($name, $stack)) {
                    array_pop($stack);
                }
                $cursor = $tagEnd;
                continue;
            }

            $skipped = $name !== ''
                && !$selfClosing
                && !in_array($name, self::VOID_TAGS, true)
                && !$translateSkipped
                && in_array($name, $skipTags, true);

            if ($skipped) {
                $closePos = $this->findClosingTag($html, $tagEnd, $name);
                if ($closePos === null) {
                    // Unclosed raw element: nothing else in the fragment is safe.
                    break;
                }

                $gtPos = strpos($html, '>', $closePos);
                $tagEnd = $gtPos === false ? $length : $gtPos + 1;
            }

            if (!$selfClosing && $name !== '' && !in_array($name, self::VOID_TAGS, true)) {
                $stack[] = $name;
            }

            $cursor = $tagEnd;
        }

        $this->appendNode($nodes, $cursor, $length, $html, $stack);

        return $nodes;
    }

    /**
     * Replaces the classic WordPress "spacer paragraph" (<p>&nbsp;</p>) with
     * a line break, or removes it.
     *
     * Paragraphs that only contain non breaking spaces, numeric equivalents
     * or plain whitespace are considered empty. Content inside skipped
     * elements (<pre>, <code>, <script>...) is never touched, so code samples
     * keep their literal markup.
     *
     * @param string $replacement "<br>", "" to remove, or null to keep as is
     */
    public function compactEmptyParagraphs(string $html, ?string $replacement = '<br>'): string
    {
        if ($replacement === null || stripos($html, '<p') === false) {
            return $html;
        }

        $protected = $this->skippedRegions($html);

        $count = preg_match_all(
            '~<p(\s[^>]*)?>(?:\s|&nbsp;|&#0*160;|&#x0*a0;|&#xa0;|' . self::NBSP . ')*</p>~iu',
            $html,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        if (!$count || $matches === []) {
            return $html;
        }

        $out = '';
        $cursor = 0;

        foreach ($matches[0] as $match) {
            $text = (string) $match[0];
            $start = (int) $match[1];

            $out .= substr($html, $cursor, $start - $cursor);

            $insideSkipped = false;
            foreach ($protected as $region) {
                if ($start >= $region[0] && $start < $region[1]) {
                    $insideSkipped = true;
                    break;
                }
            }

            $out .= $insideSkipped ? $text : $replacement;
            $cursor = $start + strlen($text);
        }

        return $out . substr($html, $cursor);
    }

    /**
     * Byte ranges of the raw text of every skipped element.
     *
     * @return array<int,array{0:int,1:int}>
     */
    private function skippedRegions(string $html): array
    {
        $regions = [];
        $length = strlen($html);
        $cursor = 0;
        $stack = [];
        $openTag = '';

        while ($cursor < $length) {
            $next = strpos($html, '<', $cursor);
            if ($next === false) {
                break;
            }

            if (!preg_match(self::TAG_PATTERN, $html, $match, 0, $next)) {
                $cursor = $next + 1;
                continue;
            }

            $isClosing = (bool) preg_match('/^<\s*\//', $match[0]);
            $name = '';
            if (preg_match('/^<\s*\/?\s*([a-zA-Z][a-zA-Z0-9:_-]*)/', $match[0], $nameMatch) === 1) {
                $name = self::lower($nameMatch[1]);
            }

            if ($isClosing) {
                if ($openTag === $name) {
                    $regions[] = [$stack['start'], $next];
                    $openTag = '';
                    $stack = [];
                }
            } elseif ($name !== ''
                && in_array($name, $this->skipTags, true)
                && substr(rtrim($match[0]), -1) !== '/'
                && !in_array($name, self::VOID_TAGS, true)) {
                $openTag = $name;
                $stack = ['start' => $next];
            }

            $cursor = $next + strlen($match[0]);
        }

        return $regions;
    }

    /**
     * Number of text nodes touched by the last translateNodes() call.
     */
    public function lastNodeCount(): int
    {
        return $this->nodeCount;
    }

    /**
     * Number of protected-term placeholders created so far.
     */
    public function protectedHits(): int
    {
        return $this->protectedHits;
    }

    /**
     * Position of the closing tag that ends a raw text element.
     */
    private function findClosingTag(string $html, int $from, string $tagName): ?int
    {
        $pattern = '#</\s*' . preg_quote($tagName, '#') . '\s*>#i';

        if (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $from)) {
            return (int) $m[0][1];
        }

        return null;
    }

    /**
     * Registers a text node when it holds something worth translating.
     *
     * @param array<int,array{start:int,end:int,text:string,tag:string}> $nodes
     * @param array<int,string>                                        $stack
     */
    private function appendNode(array &$nodes, int $start, int $end, string $html, array $stack): void
    {
        if ($end <= $start) {
            return;
        }

        $raw = substr($html, $start, $end - $start);
        if (trim($raw) === '') {
            return;
        }

        $nodes[] = [
            'start' => $start,
            'end' => $end,
            'text' => $raw,
            'tag' => $stack === [] ? '' : (string) end($stack),
        ];
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? (string) mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}