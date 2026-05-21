# charged/tailwindphp

A pure-PHP port of the Tailwind CSS v4 compiler. Generate utility-class CSS at
runtime — no Node.js, no build step, no `npx tailwindcss`.

```bash
composer require charged/tailwindphp
```

## Quick start

```php
require 'vendor/autoload.php';

use TailwindPHP\tw;

$css = tw::generate([
    'content' => '<div class="bg-blue-500 p-4 rounded-lg hover:bg-blue-600"></div>',
    'css'     => '@import "tailwindcss";',
    'minify'  => true,
]);

file_put_contents('public/app.css', $css);
```

The compiler scans `content` for utility-class candidates, resolves them
against the Tailwind design system declared in `css`, and emits compiled
CSS. Output is byte-for-byte equivalent to the upstream JavaScript compiler
for the supported subset.

## What's supported

- Tailwind v4 utility classes (color, spacing, typography, layout, etc.)
- Arbitrary values: `bg-[#1da1f2]`, `w-[42px]`
- Variants: `hover:`, `focus:`, `dark:`, `md:`, `[&>*]:`, etc.
- `@theme`, `@plugin`, `@source`, `@custom-variant` at-rules
- Bundled plugins: `@tailwindcss/forms`, `@tailwindcss/typography`
- Minification via a bundled `:where()`-safe minifier

## Use cases

- WordPress themes that want Tailwind without a Node toolchain
- Drupal themes / modules with the same need
- Symfony / Laravel apps where adding a JS build step is overkill
- CI/CD pipelines that need to generate CSS server-side
- Email rendering pipelines (Tailwind classes inlined per message)

## Command-line use

The package ships a small `tw` CLI for one-off compilations and CI:

```bash
# Compile a file, minify, write to disk
vendor/bin/tw --content=index.html --css=app.css --out=public/app.css --minify

# Pipe from stdin
cat index.html | vendor/bin/tw --content=- --minify > public/app.css

# Add @import search paths
vendor/bin/tw --content=index.html --import-path=./css/components --import-path=./css/themes
```

Run `vendor/bin/tw --help` for the full option list. Exit code 2
signals a compilation error (bad CSS, unknown plugin) so CI scripts
can distinguish "user error" from "tool crash" (exit 1).

## Calling from a theme

Pair with a thin integration layer that scans your templates for class
candidates and writes the compiled output to a cache:

```php
use TailwindPHP\tw;

$candidates = scan_my_templates_for_classes(); // your own scanner
$content    = '<div class="' . implode(' ', $candidates) . '"></div>';

$css = tw::generate([
    'content' => $content,
    'css'     => file_get_contents(__DIR__ . '/app.css'),
    'minify'  => true,
]);

file_put_contents(__DIR__ . '/cache/app.css', $css);
```

`tw::extractCandidates($html)` scans `class="..."` / `className="..."`
attributes only. For Blade/Twig templates, `cn(...)` helper calls, or any
other source, bring your own scanner and feed the resulting class list in
via the `content` option above.

Real-world integrations:

- WordPress: [charged-ui WP theme](https://github.com/charged/charged-ui-wp) — block theme with on-demand compilation
- Drupal: [charged_ui Drupal theme](https://github.com/charged/charged_ui) — same compiler, Drupal Render API integration

## Caching

`tw::generate()` accepts a `cache` option that writes compiled CSS to disk
keyed by a hash of all inputs (`content`, `css`, `importPaths`, `minify`).
Subsequent calls with identical inputs serve the cached file instead of
recompiling. Writes are atomic (write-to-tmp + `rename`), so concurrent
requests can't corrupt entries.

```php
tw::generate([
    'content'  => $html,
    'css'      => '@import "tailwindcss";',
    'cache'    => '/var/cache/tailwind',  // or `true` for sys_get_temp_dir()
    'cacheTtl' => 3600,                   // optional: seconds before recompile
    'cacheMax' => 500,                    // optional: LRU-evict to keep ≤500 entries
]);

tw::clearCache('/var/cache/tailwind');    // wipe the cache directory
```

Without `cacheMax`, the cache grows unbounded — set a sensible cap for
long-running apps with dynamic content.

## Security: `@import` containment

When the compiler resolves `@import` from the filesystem, every
resolved path must lie under one of the directories you explicitly
list in `importPaths` (or the directory of the importing file).
A CSS input containing `@import "/etc/passwd"` or
`@import "../../../etc/passwd"` is silently refused — treated
identically to "file not found" — so a compiler accepting
user-supplied CSS can't be coerced into reading arbitrary files.

If you legitimately need cross-tree imports (e.g. compiling a CMS
theme that references vendor CSS outside the theme directory), pass
a callable resolver via `importPaths`:

```php
tw::generate([
    'content' => $html,
    'css'     => $css,
    'importPaths' => function (?string $uri, ?string $fromFile): ?string {
        // Return CSS content (or null to signal "not found").
        return $myThemeLoader->fetch($uri);
    },
]);
```

A callable resolver bypasses the filesystem layer entirely; you
own the security model for whatever it returns.

## Custom plugins

Pass plugin instances directly through the compile call:

```php
use TailwindPHP\Plugin\PluginInterface;
use TailwindPHP\Plugin\PluginAPI;

final class BrandPlugin implements PluginInterface
{
    public function getName(): string { return 'my/brand'; }

    public function __invoke(PluginAPI $api, array $options = []): void
    {
        $api->addUtilities(['.brand-bg' => ['background' => 'oklch(72% .15 240)']]);
    }

    public function getThemeExtensions(array $options = []): array { return []; }
}

$css = tw::generate([
    'content' => '<div class="brand-bg"></div>',
    'css'     => '@import "tailwindcss"; @plugin "my/brand";',
    'plugins' => [new BrandPlugin()],
]);
```

Plugins passed via the `plugins` option are scoped to that single
compile call — they don't leak into other compilations. The legacy
`TailwindPHP\registerPlugin()` helper still works but is deprecated;
prefer the per-compile DI form above.

## Inspecting the design system

For tooling, design-system explorers, or computed-style lookups:

```php
$tw = tw::compile('@import "tailwindcss";');

$tw->properties('p-4');         // ['padding' => 'calc(var(--spacing) * 4)']
$tw->computedProperties('p-4'); // ['padding' => '1rem']
$tw->value('bg-blue-500');      // CSS variable form
$tw->computedValue('bg-blue-500'); // resolved colour

$tw->colors();        // ['blue-500' => 'oklch(...)', ...]
$tw->breakpoints();   // ['md' => '48rem', ...]
```

`tw::compile()` returns a reusable instance — call it once and reuse for
many lookups instead of paying the parse cost per call. The static
`tw::properties()` / `tw::value()` / `tw::colors()` shortcuts internally
memoize compiler instances per CSS source, so calling them in sequence
in the same request is cheap.

## Class-name helpers

PHP ports of the canonical JS companion libraries:

```php
use function TailwindPHP\{cn, merge, variants};

cn('px-2 py-1', 'px-4');                       // 'py-1 px-4' (tailwind-merge)
cn('btn', ['btn-lg' => $isLarge]);             // conditional (clsx)
merge('text-red-500', 'text-blue-500');        // 'text-blue-500'

$button = variants([                            // CVA-style variants
    'base' => 'btn font-semibold',
    'variants' => [
        'size' => ['sm' => 'text-sm', 'lg' => 'text-lg'],
    ],
    'defaultVariants' => ['size' => 'sm'],
]);

$button(['size' => 'lg', 'class' => 'mt-4']);
```

## Error handling

Compiler errors throw typed exceptions under `TailwindPHP\Exception\`:

- `InvalidCssException` — bad user CSS (malformed `@source`, empty
  `@utility`, `@apply` inside `@keyframes`, unbalanced brace expansion)
- `CircularDependencyException` — `@apply` cycles (extends `InvalidCssException`)
- `UnknownPluginException` — `@plugin "name"` not registered (extends `InvalidCssException`)

All extend a common `TailwindException` base, so you can catch broadly or
specifically:

```php
use TailwindPHP\Exception\{TailwindException, UnknownPluginException};

try {
    $css = tw::generate([...]);
} catch (UnknownPluginException $e) {
    // log and serve the last-known-good cached stylesheet
} catch (TailwindException $e) {
    // any TailwindPHP compile error
}
```

## Requirements

- PHP 8.2+
- No native extensions required

## Performance

The compiler is procedural PHP. Typical compile times on a modern machine:

- 100 utilities: ~10ms
- 500 utilities: ~30ms
- 2000 utilities: ~90ms

Cache the output. Don't compile on every request.

## Development

```bash
composer install
composer test     # phpunit
composer lint     # phpcs (PSR-12 on the OO surface + tests)
composer analyse  # phpstan level 5, with a baseline for the procedural port
```

CI runs against PHP 8.2 / 8.3 / 8.4, plus PHPStan and a perf benchmark
suite on the 8.3 leg. The benchmark suite is gated behind the
`TAILWINDPHP_BENCH=1` env var locally — set it if you want to run the
perf-regression tests outside CI:

```bash
TAILWINDPHP_BENCH=1 composer test
```

### Default-theme parse cache

`src/theme.cache.php` is a pre-parsed AST of `resources/theme.css`,
checked into the repo so PHP processes skip ~3 ms of CSS parsing on
cold start. Regenerate after editing `resources/theme.css` (typically
during an upstream Tailwind sync):

```bash
php bin/build-theme-cache.php
```

A test (`tests/ThemeCacheTest.php`) fails CI if the cache file goes
stale — you can't forget to regenerate it.

### Fixture parity suite

`tests/fixtures/` holds end-to-end cases (input HTML + input CSS +
expected output). [tests/FixtureParityTest.php](tests/FixtureParityTest.php)
runs every fixture through the PHP compiler and asserts byte-identical
output.

Each `expected.css` declares its provenance in a header comment:

- `source=php-seeded` — locks in current PHP behaviour as a regression
  guard. Useful, but doesn't verify upstream parity.
- `source=upstream` — generated by [bin/regen-fixtures.sh](bin/regen-fixtures.sh),
  which runs the official `@tailwindcss/cli` JavaScript compiler against
  the inputs. This is the authoritative oracle.

Maintainers with Node available should periodically run the regen
script to promote `php-seeded` fixtures to `upstream` and catch any
drift. See [tests/fixtures/README.md](tests/fixtures/README.md) for
the methodology.

## Credits

The PHP port of Tailwind v4's compiler originated as
[tailwindphp/tailwindphp](https://github.com/dnnsjsk/tailwindphp) by
**Dennis Josek**. Substantial credit for the original implementation goes to
Dennis — this package preserves his copyright as required by the MIT license.

Charged maintains this distribution to power the
[Charged UI](https://charged.dev) component ecosystem across Drupal,
WordPress, and other PHP CMS targets. It ships with cascade fixes, a
configurable forms plugin, and a `:where()`-safe minifier on top of the
original port.

Tailwind CSS itself is designed and maintained by
[Tailwind Labs, Inc.](https://tailwindcss.com) under the MIT license. This
port carries the same license.

## License

MIT. See [LICENSE](LICENSE). Copyright (c) Dennis Josek (original port author)
and Charged.
