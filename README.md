# charged-labs/tailwindphp

A pure-PHP port of the Tailwind CSS v4 compiler. Generate utility-class CSS at
runtime — no Node.js, no build step, no `npx tailwindcss`.

```bash
composer require charged-labs/tailwindphp
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
- Minification via the bundled LightningCSS port

## Use cases

- WordPress themes that want Tailwind without a Node toolchain
- Drupal themes / modules with the same need
- Symfony / Laravel apps where adding a JS build step is overkill
- CI/CD pipelines that need to generate CSS server-side
- Email rendering pipelines (Tailwind classes inlined per message)

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

Real-world integrations:

- WordPress: [charged-ui WP theme](https://github.com/charged/charged-ui-wp) — block theme with on-demand compilation
- Drupal: [charged_ui Drupal theme](https://github.com/charged/charged_ui) — same compiler, Drupal Render API integration

## Requirements

- PHP 8.1+
- No native extensions required

## Performance

The compiler is procedural PHP. Typical compile times on a modern machine:

- 100 utilities: ~10ms
- 500 utilities: ~30ms
- 2000 utilities: ~90ms

Cache the output. Don't compile on every request.

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
