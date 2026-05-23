#!/usr/bin/env php
<?php

/**
 * build-theme-cache.php — regenerate src/theme.cache.php from resources/theme.css.
 *
 * The default Tailwind theme is shipped as ~17 KB of CSS that has to be
 * parsed every cold PHP request. The parsed AST is identical across runs,
 * so we ship a pre-parsed PHP array that opcache can keep in memory at
 * near-zero load cost.
 *
 * Run this script after any change to resources/theme.css (or after an
 * upstream Tailwind sync that brings in new theme defaults). The generated
 * file is committed to the repo so consumers get the fast path on install.
 *
 * Usage:
 *   php bin/build-theme-cache.php
 *
 * The companion test (tests/ThemeCacheTest.php) confirms the cache stays
 * in sync — CI fails if you forget to regenerate.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$themeCss = file_get_contents(__DIR__ . '/../resources/theme.css');
if ($themeCss === false) {
    fwrite(STDERR, "error: cannot read resources/theme.css\n");
    exit(1);
}

$ast = TailwindPHP\CssParser\parse($themeCss);

$header = <<<PHP
<?php

/**
 * GENERATED FILE — do not edit by hand.
 *
 * Regenerate with `php bin/build-theme-cache.php` after any change to
 * resources/theme.css. The maintainer is expected to run this and commit
 * the result whenever the upstream theme is synced.
 *
 * Format: a pre-parsed AST of resources/theme.css. loadDefaultTheme()
 * uses this to skip the parse step on cold starts (~3ms saving per
 * request on a typical CGI deployment).
 *
 * Source SHA-256: %s
 */

declare(strict_types=1);

return %s;

PHP;

$sha = hash('sha256', $themeCss);
$body = var_export($ast, true);

file_put_contents(
    __DIR__ . '/../src/theme.cache.php',
    sprintf($header, $sha, $body),
);

printf("Wrote src/theme.cache.php (%d nodes, sha256=%s)\n", count($ast), substr($sha, 0, 12));
