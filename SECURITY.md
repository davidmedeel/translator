# 🔒 Security Policy

## 📦 Supported versions

| Version | Supported |
| --- | --- |
| 1.0.x | ✅ |

## 🕵️ Reporting a vulnerability

**Do not open a public issue.** Use GitHub's private vulnerability reporting
("Security" tab → "Report a vulnerability") or email the maintainer listed in
[composer.json](composer.json).

Please include: the affected version, the input that triggers it, and the impact
you observed. You can expect an answer within a few days.

## 🛡️ Threat model

This package runs **inside your application**, with your privileges. Its whole
purpose is to avoid network calls, which removes an entire class of risk.

### What it guarantees

* **Zero network access.** No HTTP client, no socket, no external process. A test
  in the suite greps `src/` for `curl_*`, `fsockopen`, `stream_context_create`
  and friends, so a regression fails CI.
* **No telemetry.** Nothing is measured, logged or sent anywhere.
* **No secrets required.** No API keys, no tokens, no accounts. The cache is a
  local file and can be pointed at a directory you control with
  `MEDEL_TRANSLATOR_CACHE` or `cache_dir`.
* **Local cache only.** SQLite (WAL, `synchronous = NORMAL`), JSON, or memory. It
  never contains anything you did not put through the engine.

### What you should still be aware of

* **The cache file is plaintext.** If your source text is sensitive, use
  `cache_dir` inside a protected directory or `cache_driver => 'memory'`.
* **`pdo_sqlite` is optional.** Without it the engine falls back to a JSON file,
  and if the directory is not writable it falls back to memory. Translation
  keeps working in every case.
* **Dictionary files are code-adjacent data.** A dictionary cannot execute code,
  but a malicious one could produce hostile output. Treat `data/*.json` like any
  other input you deploy.
* **Untrusted input is not sanitised for HTML.** The engine preserves markup; it
  does not sanitise it. Sanitise before translating if the source is untrusted.
* **A network filesystem is not a supported cache location** for SQLite. WAL
  needs local locking.