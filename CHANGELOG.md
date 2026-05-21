# Changelog

All notable changes to `charged/tailwindphp` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **Path-traversal containment for `@import`.** A CSS input containing
  `@import "/etc/passwd"` or `@import "../../../etc/passwd"` could
  previously cause the compiler to read any file the PHP process had
  access to. The resolver now refuses any `@import` that resolves
  outside the directories explicitly listed in `importPaths` (and the
  directory of the importing file). Consumers that legitimately need
  cross-tree imports should pass a callable resolver via `importPaths`,
  which bypasses the filesystem layer entirely.

### Added

- **Typed exception hierarchy** under `TailwindPHP\Exception\`:
  `TailwindException` (base), `InvalidCssException`,
  `CircularDependencyException`, `UnknownPluginException`. Consumers
  can catch compile errors granularly instead of catching `\Exception`.
- **Per-compile plugin DI.** `tw::generate(['plugins' => [...]])` and
  `tw::compile($css, ['plugins' => [...]])` accept plugin instances
  per call. Each compile gets its own `PluginManager`, so plugin
  registrations no longer leak across calls.
- **Bounded cache eviction.** `tw::generate(['cacheMax' => 500])`
  LRU-evicts oldest entries to keep the cache directory size bounded.
- **Atomic cache writes.** Cache files are written via tmp-file +
  `rename` so concurrent web requests can't corrupt an entry.
- **Static compiler-instance cache.** Sequential `tw::properties()` /
  `tw::value()` / `tw::colors()` calls reuse a compiled instance per
  CSS source instead of parsing on every call.
- **Default-theme parse cache** (`src/theme.cache.php`). Ships a
  pre-parsed AST of `resources/theme.css` for ~3 ms cold-start savings
  per request. Regenerate with `php bin/build-theme-cache.php`.
- **Fixture-driven parity tests.** `tests/fixtures/` holds end-to-end
  cases (HTML + CSS + expected output) covering basic utilities,
  variants, arbitrary values, theme overrides, `@apply`, and the
  bundled plugins. See [tests/fixtures/README.md](tests/fixtures/README.md).
- **PHPStan static analysis at level 5** with a baseline for the
  procedural port files. The OO surface is expected to stay green
  forever; new regressions in the procedural code surface as
  baseline-diff in PRs.
- **`bin/tw` CLI** — compile Tailwind utility CSS from the command
  line for ad-hoc use and CI pipelines.
- **Benchmark suite** — `tests/bench/` measures compile times against
  the perf claims in the README so future changes can be flagged for
  regression.

### Changed

- **Renamed `LightningCss` → `Normalizer\ValueNormalizer`.** The old
  name oversold the class as a port of the Rust LightningCSS library;
  it's actually a small set of value normalisations. Public API
  unchanged; the FQ class name has moved.
- **PSR-4 layout** for the OO surface (`Tailwind`, `TailwindCompiler`,
  `CssMinifier`, `ValueNormalizer`, plugins, exceptions). The
  procedural port files continue to load via the `files` autoload,
  preserving the 1:1 mapping with upstream Tailwind JS sources.
- **CSS minifier hardening.** A single-pass tokeniser now protects
  string literals, unquoted `url()` args, and `/*! ... */` loud
  comments from every transform. Fixes silent corruption of
  `content: "hello   world"` (whitespace), `url(./0px.png)` (zero-unit
  stripping), `content: "#ffffff"` (hex shortening), and
  `var(--tw-empty,/*!*/ /*!*/)` structural markers.

### Deprecated

- `TailwindPHP\getPluginManager()` and `TailwindPHP\registerPlugin()`
  are now deprecated in favour of the per-compile `plugins` option
  (see Added → Per-compile plugin DI). They continue to work for
  back-compat; new code should not rely on the process-wide singleton.

### Fixed

- `tw::generate()` cache key previously hashed only
  `content + css + minify`, so two calls that differed only in
  `importPaths` could collide and return the wrong cached output.
  The key now includes all compile options.
- `splitByCommaRespectingParens()` is now quote- and escape-aware. A
  comma inside `"a,b"` or `'rgb(...)'` no longer splits the value.
- `TailwindCompiler::__construct` no longer parses the input CSS twice.

### Removed

- Dead/stub files: `src/canonicalize-candidates.php` (TODO
  placeholder), `src/Cli/` (unloaded, no `bin` entry — replaced by
  the new `bin/tw` CLI), and the `@port-deviation:omitted` doc-only
  files (`src/compat/`, `src/source-maps/`, `src/intellisense.php`,
  `src/types.php`).
