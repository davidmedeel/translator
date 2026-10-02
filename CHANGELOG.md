# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-02

### 🎉 First public release

#### ✨ Features

- **Offline engine** for plain text and HTML fragments. Zero HTTP requests, zero
  API keys, zero runtime dependencies beyond `ext-json` and `ext-mbstring`.
- **Nine languages**: `es`, `en`, `de`, `fr`, `it`, `pt`, `ja`, `ar`, `ru`, with
  **72 working combinations** (17 direct, the rest through the English hub).
- **Markup preserving HTML pipeline**: tags, attributes, `href`, entities,
  emoji sequences including `&zwj;`, `<code>`, `<pre>` and paths survive byte
  for byte. Offsets based, so there is no DOM reserialisation.
- **Protected terms** (`protected_terms`): byte exact, and no dictionary phrase
  may rewrite them.
- **Keep terms** (`keep`): copied verbatim while phrases may still cross them.
  Multi word entries such as `Medel Platforms` are masked, so a brand cannot be
  half translated.
- **WordPress spacers**: `<p>&nbsp;</p>` can be kept, turned into `<br>` or
  removed, never inside `<code>` or `<pre>`.
- **Local cache** with three tiers: SQLite (WAL), JSON and in-memory, with TTL,
  pruning and automatic invalidation when a dictionary file changes.
- **Coverage control**: `min_coverage` applies a *weighted* score where function
  words count `0.25`, so a sentence full of prepositions is not mistaken for a
  translated one.
- **Prose guard** (on by default): a fragment of 8 or more tokens that no
  phrase ever covered is returned untouched instead of being mangled word by
  word.
- **Pivot routing** with `pivot_fallback`, to keep what the hub leg translated
  instead of reverting to the source.
- **CJK support**: Japanese and Chinese are matched with a dictionary driven
  longest match over character runs, like a segmenter.
- **RTL awareness**: `Text::direction('ar')` returns `rtl` so the template can
  render it correctly without the engine touching the markup.
- **Language discovery helpers**: `availableLanguages()`, `availablePairs()`,
  `combinationMatrix()`, `languagesFor()`, `isTranslatable()`.
- **Batching** helpers: `translateBatch()` and `translateArray()`.
- **Maintenance tools**: `tools/invert.php` and `tools/refresh.php`.

#### 🧪 Tests

- `tests/smoke.php` — 179 assertions covering the public API, the HTML pipeline,
  the cache backends, multi word brands and the offline guarantees.
- `tests/acceptance.php` — real pages translated to every language with the
  markup, entities and emojis verified.
- `tests/i18n.php` — data integrity, correct script per language, no source
  language leftovers, no duplicated punctuation and no node lost between pivot
  legs. Runs over every fixture with a committed coverage floor.
- `tests/benchmark.php` — time, memory and cache cost against a real page.
- `tests/fixtures/` — `tokify.html` (SaaS product page) and `software-2026.html`
  (long form article with tables, entities and links).

#### ⚡ Performance

- Phrase matching rewritten as a single forward pass with memoised token keys:
  a 28 KB article went from **13.8 s to 588 ms** (23×).
- Whole fragment phrase lookup enabled for any length: it used to be disabled by
  any comma or period, which is every real sentence.
- SQLite cache moved to WAL with `synchronous = NORMAL`, prepared statements
  reused and read hits batched: a cache write went from **10.35 ms to 0.56 ms**
  and a cache hit from **3.03 ms to 0.018 ms**.
- JSON cache writes buffered and flushed once per request, removing the quadratic
  rewrite of the whole file on every entry.

#### 🔒 Privacy

- No telemetry, no analytics, no external requests of any kind. A test greps
  `src/` for `curl`, `fsockopen`, `stream_context_create` and friends.

[Unreleased]: https://github.com/davidmedeel/translator/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/davidmedeel/translator/releases/tag/v1.0.0