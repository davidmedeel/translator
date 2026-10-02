# 📚 /data — dictionary storage

This is the only place the engine reads from. Every file describes **one
directional pair**: `data/es_en.json` translates Spanish into English.

## 🌍 The English hub

A language only needs to talk to English to reach every other one:

```
data/en_XX.json   English -> XX   used when XX is the target
data/XX_en.json   XX -> English   used when XX is the source
```

## 📦 Shipped tables

```
en_es.json  es_en.json      Spanish  <-> English  (hub)
en_de.json  de_en.json      English <-> German
en_fr.json  fr_en.json      English <-> French
en_it.json  it_en.json      English <-> Italian
en_pt.json  pt_en.json      English <-> Portuguese
en_ja.json  ja_en.json      English <-> Japanese
en_ar.json  ar_en.json      English <-> Arabic
en_ru.json  ru_en.json      English <-> Russian
es_de.json                 Spanish -> German (direct, richer than the pivot)
```

Nine languages, **17 tables**, **72 ordered combinations** reachable (17 direct,
the rest in two legs through the hub).

## 📄 File format

```json
{
  "from": "es",
  "to": "de",
  "name": "Spanish => German",
  "version": 1,
  "phrases": {
    "codigo fuente completo de la aplicacion php": "Vollständiger Quellcode der PHP-Anwendung"
  },
  "words": {
    "carrito": "Warenkorb"
  },
  "keep": ["php", "saas", "medel platforms"],
  "drop_source": ["el", "la"],
  "keep_value_case": true,
  "plural": { "suffix": "e", "after": [] }
}
```

| Key | Meaning |
| --- | --- |
| `phrases` | Matched first, longest wins, up to 32 words in a row |
| `words` | Token lookup with light singular/plural tolerance |
| `keep` | Copied verbatim. Multi word entries are **masked**, single words are not |
| `rules` | Declarative post processing of the target text |
| `drop_source` | Source words removed when this language is used as a pivot |
| `keep_value_case` | The value keeps its own capitalisation (German nouns) |
| `plural` | Plural suffix rule for the target language |

## 🧠 Writing entries

* **Whole sentences, not isolated words.** Word by word translation cannot fix
  word order.
* **One entry per HTML text node.** Nodes are translated separately, so a phrase
  can never cross a tag.
* **Keys are matched case and accent insensitively**; the value is written
  literally, with its accents.
* **No trailing period and no leading comma in the value**: the punctuation of
  the source node is preserved, and repeating it produces `betreiben..`.
* **Do not repeat an article** that lives in another node, or you get
  `Die Die Standardlizenz`.
* **Paths are never translated** (`/docs`, `config.php`).

## 🔧 Maintenance

```bash
php tools/refresh.php                                   # regenerate inverse tables
php tools/invert.php data/en_de.json data/de_en.json    # one pair
php tests/i18n.php                                      # integrity and quality gates
```

`refresh.php` regenerates every reverse table, restores the hand written
additions, removes keys that only differ in case and drops single word phrases
that would shadow the words table.

## ➕ Adding a language

```bash
cp data/en_de.json data/en_nl.json
php tools/invert.php data/en_nl.json data/nl_en.json
php tests/i18n.php
```

That unlocks 10 pairs at once, because every other language reaches Dutch through
the English hub.

## 🔄 Reverse tables

The `XX_en.json` files of Japanese, Arabic and Russian are deliberately small
(about 50 entries): product names and technical terms only. Inverting
`Fully customisable` into Japanese produces nonsense, so abstract vocabulary is
not inverted on purpose.