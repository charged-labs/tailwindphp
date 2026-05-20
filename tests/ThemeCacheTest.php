<?php

declare(strict_types=1);

namespace ChargedLabs\TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;

use function TailwindPHP\CssParser\parse;
use function TailwindPHP\readResourceFile;

/**
 * Guard rail for the default-theme parse cache.
 *
 * src/theme.cache.php is a pre-parsed AST of resources/theme.css, generated
 * by bin/build-theme-cache.php and committed to the repo for cold-start
 * performance. If the source CSS changes (e.g. during an upstream Tailwind
 * sync) without regenerating the cache, the live compiler still works
 * (loadDefaultTheme falls through to parsing from source when the cache
 * is absent) but downstream consumers pay the parse cost on every cold
 * start.
 *
 * CI fails if the cache is missing or stale. Regenerate with:
 *
 *     php bin/build-theme-cache.php
 *
 * …and commit the result.
 */
final class ThemeCacheTest extends TestCase
{
    public function test_cache_file_exists(): void
    {
        $this->assertFileExists(
            __DIR__ . '/../src/theme.cache.php',
            'Run `php bin/build-theme-cache.php` to generate the default-theme parse cache.',
        );
    }

    public function test_cache_matches_freshly_parsed_theme(): void
    {
        $cached = require __DIR__ . '/../src/theme.cache.php';
        $fresh = parse(readResourceFile('theme.css'));

        $this->assertSame(
            $fresh,
            $cached,
            "src/theme.cache.php is stale. Regenerate it with `php bin/build-theme-cache.php` "
            . "and commit the result.",
        );
    }

    public function test_cache_records_source_sha256(): void
    {
        // The header comment in theme.cache.php carries the SHA-256 of the
        // theme.css it was generated from. This lets reviewers spot drift
        // at a glance (the SHA in the diff should change if and only if
        // resources/theme.css changed).
        $cacheContent = file_get_contents(__DIR__ . '/../src/theme.cache.php');
        $themeContent = file_get_contents(__DIR__ . '/../resources/theme.css');

        $expectedSha = hash('sha256', $themeContent);

        $this->assertStringContainsString(
            $expectedSha,
            $cacheContent,
            "Cache header SHA-256 doesn't match resources/theme.css. "
            . "Regenerate with `php bin/build-theme-cache.php`.",
        );
    }
}
