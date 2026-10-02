<?php

declare(strict_types=1);

namespace Medel\Translator\Engines;

/**
 * Immutable result of a single translation request.
 *
 * Returning a result object (instead of a bare string) keeps the hot path
 * simple while still exposing what happened: which route was used, how much of
 * the text was actually covered by the dictionary and whether the value came
 * from the local cache.
 */
final class TranslationResult
{
    /** @var string */
    private $text;

    /** @var string */
    private $from;

    /** @var string */
    private $to;

    /** @var array<int,array{from:string,to:string}> */
    private $route;

    /** @var bool */
    private $matched;

    /** @var float */
    private $coverage;

    /** @var int */
    private $segments;

    /** @var int */
    private $matchedSegments;

    /** @var bool */
    private $cached;

    public function __construct(
        string $text,
        string $from,
        string $to,
        array $route,
        bool $matched,
        float $coverage = 0.0,
        int $segments = 0,
        int $matchedSegments = 0,
        bool $cached = false
    ) {
        $this->text = $text;
        $this->from = $from;
        $this->to = $to;
        $this->route = $route;
        $this->matched = $matched;
        $this->coverage = $coverage;
        $this->segments = $segments;
        $this->matchedSegments = $matchedSegments;
        $this->cached = $cached;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function from(): string
    {
        return $this->from;
    }

    public function to(): string
    {
        return $this->to;
    }

    /**
     * Legs actually used, e.g. [es=>en, en=>de] for a pivoted translation.
     *
     * @return array<int,array{from:string,to:string}>
     */
    public function route(): array
    {
        return $this->route;
    }

    /**
     * Human readable route: "es>en>de".
     */
    public function routeAsString(): string
    {
        if ($this->route === []) {
            return '';
        }

        $codes = [$this->route[0]['from']];
        foreach ($this->route as $leg) {
            $codes[] = $leg['to'];
        }

        return implode('>', $codes);
    }

    public function matched(): bool
    {
        return $this->matched;
    }

    /**
     * Percentage of translatable tokens found in the dictionaries (0..1).
     */
    public function coverage(): float
    {
        return $this->coverage;
    }

    public function segments(): int
    {
        return $this->segments;
    }

    public function matchedSegments(): int
    {
        return $this->matchedSegments;
    }

    public function wasCached(): bool
    {
        return $this->cached;
    }

    public function markCached(bool $cached = true): void
    {
        $this->cached = $cached;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'from' => $this->from,
            'to' => $this->to,
            'route' => $this->routeAsString(),
            'matched' => $this->matched,
            'coverage' => round($this->coverage, 4),
            'segments' => $this->segments,
            'matched_segments' => $this->matchedSegments,
            'cached' => $this->cached,
        ];
    }
}