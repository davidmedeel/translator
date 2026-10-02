<div align="center">

# 🌍 medel/translator

**Offline, dependency-free translation engine for PHP — text and HTML.**

Deterministic. No API keys. No network. No telemetry.

</div>

---

## 🔍 What is this

A translation engine for WordPress plugins, SaaS dashboards and static sites that
**cannot call an external API**. It ships with dictionaries for nine languages
and translates text and HTML fragments **without ever touching your markup**.

```php
use Medel\Translator\Translator;

$t = new Translator();

echo $t->translate('Hola mundo', 'en', 'es');          // Hello world
echo $t->translate('Añadir al carrito', 'de', 'es');   // In den Warenkorb
```

Real HTML in, real HTML out. Tags, attributes, entities, emojis and links come out
byte identical:

```php
echo $t->translate(
    '<h3>💻 Código fuente completo</h3><p>&nbsp;</p><p>Código fuente <strong>PHP</strong></p>',
    'de',
    'es',
    ['empty_paragraphs' => 'br']
);
```

```html
<h3>💻 Vollständiger Quellcode</h3><br><p>Vollständiger Quellcode <strong>PHP</strong></p>
```

The `&nbsp;` spacer became a `<br>`, and nothing else moved.

---

## 📖 Read this first

This is a **dictionary engine, not a neural one**. That single sentence explains
every design decision:

* ✅ A sentence stored in the glossary comes out **perfect**, with correct word
  order, because the dictionary decides the order.
* 🔒 A sentence that is **not** in the glossary comes back **untouched** in its
  original language. It never invents anything.

So the quality of the output is the quality of your glossary, and the failure mode
is always safe. Add a sentence, get a translation. Forget one, your Spanish stays
Spanish instead of turning into broken German.

```bash
composer test:i18n   # tells you exactly how much of each page is covered
```

---

## ✨ Highlights

| | |
| --- | --- |
| 🔌 **No API keys** | Nothing to leak, nothing to pay for, no vendor to depend on |
| 🧩 **Markup is untouchable** | Tags, attributes, `href`, `&nbsp;`, `&zwj;` and emoji sequences survive byte for byte |
| 🧪 **Deterministic** | Same input, same output, always. Ideal for tests and CI |
| 🌍 **Nine languages, 72 pairs** | English works as the hub, so adding a language unlocks 10 pairs at once |
| 🗄️ **Local cache** | SQLite with WAL, falling back to JSON and then memory |
| 🪶 **10 MB** | Peak memory for the whole engine, dictionaries included |
| ⚡ **~0.2 ms** | Per short string, and microseconds when the cache is warm |
| 📥 **Zero dependencies** | `php`, `ext-json`, `ext-mbstring`. That's it |

---

## 📦 Install

```bash
composer require medel/translator
```

```php
require 'vendor/autoload.php';
```

Works as a plain PHP library, inside WordPress, or in any framework that can
autoload PSR-4.

---

## 🌍 Supported languages

| Code | Language | Native | Role |
| --- | --- | --- | --- |
| `es` | Spanish | Español | Source language |
| `en` | English | English | **Hub** for indirect pairs |
| `de` | German | Deutsch | Source and target |
| `fr` | French | Français | Source and target |
| `it` | Italian | Italiano | Source and target |
| `pt` | Portuguese | Português | Source and target |
| `ja` | Japanese | 日本語 | Source and target |
| `ar` | Arabic | العربية | Source and target (RTL) |
| `ru` | Russian | Русский | Source and target |

Regional variants are accepted (`es-MX` → `es`, `pt-BR` → `pt`, `jpn` → `ja`).

### 🧮 Combination matrix

```
        de   en   es   fr   it   ja   pt   ru
  de     -    2    1    2    2    2    2    2
  en     1    -    1    1    1    1    1    1
  es     2    1    -    2    2    2    2    2
  fr     2    1    2    -    2    2    2    2
  it     2    1    2    2    -    2    2    2
  ja     2    1    2    2    2    -    2    2
  pt     2    1    2    2    2    2    -    2
  ru     2    1    2    2    2    2    2    -

1 = direct dictionary   2 = two legs through the English hub
```

```php
$matrix = $translator->combinationMatrix();   // what to enable in a language picker
$targets = array_keys($matrix['es'] ?? []);
$translator->languagesFor('es');              // ['de','en','fr','it','ja','pt','ru']
$translator->isTranslatable('es', 'ja');      // true
```

### 🈯 Right to left languages

Arabic is written right to left. The engine **never reorders your markup**, so the
rendering decision belongs to your template:

```php
use Medel\Translator\Support\Text;

Text::direction('ar');        // 'rtl'
Text::direction('ru');        // 'ltr'
Text::isRtlLanguage('he');    // true

echo '<html dir="' . Text::direction($lang) . '">';
```

### 🈯 Languages without word separators

Japanese and Chinese do not put spaces between words. The tokenizer splits those
runs character by character and the engine applies a **dictionary driven longest
match**, which is how a segmenter behaves:

```
データベース  ->  database      (matched as a run, not as characters)
ソースコード  ->  source code
```

---

## 🧱 How the markup is preserved

| You write | You get back |
| --- | --- |
| `<a href="https://medel.es/">Medel Platforms</a>` | Same tag, same `href`, same text |
| `👨&zwj;💻` | Same emoji sequence, joiner included |
| `&nbsp;` | Same entity, or `<br>` if you ask for it |
| `/docs`, `config.php` | Never translated, they are paths |
| `<code>`, `<pre>` | Skipped unless `translate_skipped_tags` is on |
| `PHP 8.2`, `S3` | Version numbers and acronyms untouched |

The engine works on byte offsets, not on a DOM, so **there is no reserialisation
and no way for it to normalise your markup**.

---

## 🛡️ Keep, mask, protect

Three different mechanisms, each with a different purpose:

| | `protected_terms` | `keep` (one word) | `keep` (several words) |
| --- | --- | --- | --- |
| Output identical | ✅ | ✅ | ✅ |
| A dictionary phrase can cross it | ❌ | ✅ | ❌ |
| Masked before translating | ✅ | ❌ | ✅ |
| Use it for | critical fragments, API keys | `PHP`, `SaaS`, `API` | `Medel Platforms`, `Amazon S3` |

```php
$translator = new Translator([
    // Byte exact, and no phrase may rewrite it
    'protected_terms' => ['Medel Platforms', 'Acme Cloud'],

    // Copied verbatim, but phrases may span it
    'keep' => ['PHP', 'MySQL', 'Stripe', 'Tokify', 'SaaS'],
]);

// Per call
$translator->translate($html, 'de', 'es', ['keep' => ['Pexels']]);
```

> [!IMPORTANT]
> A brand made of several words **must** be in one of these lists. The `keep`
> list is consulted token by token, so `Platforms` on its own would be looked up
> and turned into `Bahnsteige` in German. Multi word entries are masked, which is
> why `Medel Platforms` comes back untouched in all nine languages.

---

## 🎯 The prose guard

Word by word translation of a long sentence is **always** wrong word order,
whatever the coverage says:

```
La decisión entre software a medida no es trivial
   word by word →  Die decision zwischen Maßanfertigung nein ist trivial   ✗
   glossary hit →  Die Entscheidung zwischen Standardsoftware und          ✓
                   Maßanfertigung ist nicht trivial
```

So a fragment with **8 or more tokens where no phrase ever matched a quarter of
them is returned untouched**. Showing the original Spanish is always better than
showing broken German.

```php
$t = new Translator(['prose_guard' => true]);   // default
$t->setProseGuard(false);                        // translate anyway
```

### Coverage control

`de`, `con`, `y` and `la` are trivial to translate and say nothing about quality.
A sentence can be 85 % translated by token count and still be nonsense, so the
`min_coverage` threshold is applied to a **weighted** score: function words count
`0.25`, content words count `1`.

```php
$t = new Translator(['min_coverage' => 0.5]);

$t->result($fragment, 'de', 'es')->coverage();   // informative token ratio
$t->result($fragment, 'de', 'es')->matched();    // false = left as it was
$t->result($fragment, 'de', 'es')->routeAsString();  // 'es>en>de'
```

### Pivot fallback

On an indirect route the text passes through English. If the last leg falls below
the threshold, the engine can return what it already achieved instead of
reverting to the source:

```php
$t->translate($html, 'fr', 'es', ['pivot_fallback' => 'last-leg']);
```

---

## 🗄️ Cache

Local only, nothing leaves the machine. Three tiers, picked automatically:

```
SQLite (WAL)  →  JSON file  →  in-memory  →  none
```

```php
$t = new Translator([
    'cache' => true,                      // default
    'cache_driver' => 'sqlite',           // sqlite|json|memory|none|auto
    'cache_dir' => WP_CONTENT_DIR . '/cache/medel-translator',
    'cache_ttl' => 0,                     // 0 = no expiration
]);

$t->cache()->driver();   // 'sqlite'
$t->cache()->stats();    // hits, writes, bytes
$t->pruneCache();
$t->clearCache();
```

The directory is discovered automatically: the `MEDEL_TRANSLATOR_CACHE` env var,
then `var/cache` of the package, then `storage/translator-cache` of the project,
then the system temp directory. If nothing is writable the engine keeps working
in memory.

### ⚡ Measured cost

Real page, 4.6 KB of HTML with 65 text nodes, PHP 8.2 on Windows (a pessimistic
environment):

| | |
| --- | --- |
| Loading the 17 dictionaries | 17 ms, once per request |
| Full page, cold cache | 104 ms |
| Full page, warm cache | 17 ms |
| Full page, cache disabled | 65 ms |
| 5 languages, all cached | 0.3 ms |
| 2000 short strings, no cache | 404 ms (0.2 ms each) |
| 2000 unknown strings, worst case | 915 ms (0.46 ms each) |
| Peak memory | 10 MB |

Run it yourself:

```bash
composer benchmark
```

---

## 📖 API

### `Translator`

| Method | Description |
| --- | --- |
| `translate($text, $to, $from = null, $options = [])` | Translates text or HTML |
| `result($text, $to, $from = null, $options = [])` | Same, returning a `TranslationResult` |
| `translateHtml($html, $to, $from = null, $options = [])` | Forces the HTML pipeline |
| `translateBatch(array $items, $to, $from = null, $options = [])` | Arrays and batches |
| `translateArray(...)` | Alias of `translateBatch` |
| `translateWithTerms($text, $to, $from, array $terms, $options = [])` | Protected terms for one call |
| `setProtectedTerms()` / `protect()` / `addProtectedTerm()` | Protected terms |
| `setKeepTerms()` / `keep()` / `addKeepTerm()` | Verbatim terms |
| `keepPhrases()` | Multi word keep terms being masked |
| `minCoverage()` / `setMinCoverage($c)` | Coverage floor |
| `pivotFallback()` / `setPivotFallback($mode)` | Weak pivot route behaviour |
| `hasProseGuard()` / `setProseGuard($bool)` | The prose guard |
| `isTranslatable($from, $to)` | Is there a dictionary, direct or pivoted? |
| `has($text, $from, $to)` | Does the phrase exist? |
| `detectLanguage($text)` | Most likely language |
| `route($from, $to)` | Legs used (`es>en`, `en>de`) |
| `availablePairs()` / `availableLanguages()` | What is on disk |
| `combinationMatrix()` / `languagesFor($lang)` | What a language picker needs |
| `pivot()` / `setPivot($code)` | Hub language |
| `cache()` / `clearCache()` / `pruneCache()` / `commitCache()` | Cache control |
| `engine()` / `htmlProcessor()` | Component access |
| `stats()` | Counters, cache, engine |

### ⚙️ Options

| Option | Default | What it does |
| --- | --- | --- |
| `from` | auto detected | Source language |
| `protected_terms` | `[]` | Byte exact terms, no phrase may cross them |
| `keep` | `[]` | Verbatim terms; phrases **may** cross one word entries |
| `min_coverage` | `0.5` | Weighted coverage floor to accept a translation |
| `pivot_fallback` | `'source'` | `'last-leg'` keeps what the hub leg translated |
| `prose_guard` | `true` | Reject long fragments no phrase ever covered |
| `html` | `true` | HTML pipeline when markup is detected |
| `empty_paragraphs` | `'keep'` | `'br'`, `'remove'` or `'keep'` for `<p>&nbsp;</p>` |
| `skip_tags` | `code`, `pre`, `kbd`, `samp`, `var`, `script`, `style` | Tags never translated |
| `translate_skipped_tags` | `false` | Translate `<code>` and `<pre>` too |
| `detect_source` | `true` | Allow calling without `from` |
| `exact_only` | `false` | Only exact phrase hits, no token guessing |
| `on_miss` | `null` | `'keep'` or a `callable(string $text, $from, $to)` |

### `TranslationResult`

```php
$translator->result('Hola mundo', 'en', 'es')->toArray();
/*
[
  'text'             => 'Hello world',
  'from'             => 'es',
  'to'               => 'en',
  'route'            => 'es>en',
  'matched'          => true,
  'coverage'         => 1.0,
  'segments'         => 1,
  'matched_segments' => 1,
  'cached'           => false,
]
*/
```

---

## 📚 Dictionaries

A table is a JSON file per language pair:

```json
{
  "from": "es",
  "to": "de",
  "phrases": {
    "codigo fuente completo de la aplicacion php": "Vollständiger Quellcode der PHP-Anwendung"
  },
  "words": {
    "carrito": "Warenkorb"
  },
  "keep": ["php", "saas", "medel platforms"],
  "drop_source": ["el", "la"],
  "keep_value_case": true
}
```

| Key | Meaning |
| --- | --- |
| `phrases` | Matched first, longest wins, up to 32 words in a row |
| `words` | Token lookup with light singular/plural tolerance |
| `keep` | Copied verbatim; multi word entries are masked |
| `rules` | Declarative post processing of the target text |
| `drop_source` | Source words removed when the language is used as a pivot |
| `keep_value_case` | The value keeps its own capitalisation (German nouns) |
| `plural` | Plural suffix rule for the target language |

### 🧠 Writing a good glossary

Word by word translation cannot fix word order, so **store whole sentences**. One
entry per HTML text node:

* ✅ `codigo fuente completo de la aplicacion php` → `Vollständiger Quellcode der PHP-Anwendung`
* ❌ translating that node word by word

Rules that avoid the bugs this package already had:

1. **One entry per node.** A phrase cannot cross a tag, because the nodes are
   translated separately.
2. **Up to 32 words** fit in a key, which covers a full sentence.
3. **No trailing period and no leading comma** in the value: the punctuation of
   the source node is preserved, and repeating it gives `betreiben..`
4. **Do not repeat an article** that lives in another node, or you get
   `Die Die Standardlizenz`.
5. **Hyphens and apostrophes** are part of the word: `D-ID`, `l'application`,
   `multi-moneda` all match.
6. **Paths are never translated**: `/docs`, `config.php`, `uploads/img`.
7. For pivot languages, write the phrase **in English** in `en_XX.json`, because
   that is what the first leg produces.

See [`data/README.md`](data/README.md) for the full format.

### 🔧 Maintenance

```bash
php tools/refresh.php                       # regenerate the inverse tables
php tools/invert.php data/en_de.json data/de_en.json
composer test:i18n                          # data integrity + quality gates
```

---

## 🧪 Tests

No PHPUnit required, everything runs offline:

```bash
composer test              # 179 assertions, public API and guarantees
composer test:acceptance   # markup, entities and emojis survive
composer test:i18n         # data integrity, script, quality, no lost legs
composer benchmark         # time, memory and cache cost
composer demo              # usage examples
```

`test:i18n` runs against every file in `tests/fixtures/` and enforces four gates:

1. **Data** — every value written in the script of its language, no empty values,
   no duplicated keys.
2. **Structure** — tags, attributes, entities, emojis, `href`, paths and brand
   names identical in every language.
3. **Quality** — no fragment keeps the source language, no duplicated
   punctuation, correct script per language.
4. **No lost legs** — a pivot route never drops what the first leg produced.

Coverage floors are committed per fixture and language, so the test tracks how
much of each page is really covered instead of pretending to be green:

```
de  70/186 nodos  cobertura 38%  suelo 35%  ok
en  66/186 nodos  cobertura 35%  suelo 32%  ok
fr  41/186 nodos  cobertura 22%  suelo 20%  ok
```

### 📄 Included fixtures

* `tokify.html` — SaaS product page, 65 nodes, 46 emoji sequences, `&zwj;`,
  `<code>`, `<strong>`, a path and 24 brands
* `software-2026.html` — long form article, 186 nodes, `<table>`, `&iquest;`,
  `&Uacute;`, `<strong>` inside `<li>`, `<a href>`

---

## 🔌 WordPress

```php
add_action('init', function (): void {
    $translator = new Translator([
        'data_path' => WP_CONTENT_DIR . '/languages/medel-translator',
        'cache_dir' => WP_CONTENT_DIR . '/cache/medel-translator',
        'protected_terms' => [get_bloginfo('name')],
        'keep' => ['WordPress', 'WooCommerce', 'PHP', 'SaaS'],
        'empty_paragraphs' => 'br',
    ]);

    echo $translator->translate($post->post_content, pll_current_language(), 'es');
});
```

WordPress spacer paragraphs are handled natively:

```php
'<p>&nbsp;</p>'   →  <p>&nbsp;</p>     // empty_paragraphs => 'keep' (default)
                  →  <br>               // 'br'
                  →  removed            // 'remove'
```

Only outside `<code>` and `<pre>`.

---

## ⚠️ Honest limitations

* It is a **dictionary engine, not a neural one**. Quality is exactly the quality
  of your glossary. Anything unknown comes back untouched, which is the safe
  failure mode.
* No plural, gender or agreement engine. German nouns stay capitalised thanks to
  `keep_value_case`, but articles and adjectives are not inflected.
* No locale formatting for dates, numbers or currency.
* The reverse tables of `ja`, `ar` and `ru` are deliberately small (product
  names and technical terms only): inverting `Fully customisable` into Japanese
  produces nonsense.
* HTML is processed with byte offsets, not a DOM parser. Malformed markup
  (unclosed tags) is left alone on purpose.
* Concurrent writers to the same SQLite cache rely on WAL plus a 3 s busy
  timeout. Multiple workers are fine; a network filesystem is not.

---

## 🤝 Contributing

```bash
git clone https://github.com/davidmedeel/translator.git
cd translator
composer install
composer test
```

Adding a language is **two files**:

```bash
cp data/en_de.json data/en_nl.json   # English -> Dutch
php tools/invert.php data/en_nl.json data/nl_en.json
```

That unlocks 10 pairs at once, because every other language reaches it through
the English hub.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the dictionary guidelines, and please
run the three test commands before opening a pull request.

---

## 📄 License

MIT. See [LICENSE](LICENSE).

The dictionaries are sample content shipped with the package and are also MIT.

---

<div align="center">

Made with care for sites that cannot call an API. 🌍

</div>