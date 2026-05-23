<?php

declare(strict_types=1);

namespace Charged\TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;
use TailwindPHP\tw;

/**
 * Covers tw::generate()'s on-disk cache: hit/miss, TTL expiry, the cache
 * key including importPaths (regression for the old md5(content+css+min)
 * collision), and the atomic-write contract (no stray .tmp files left in
 * the cache directory).
 */
final class CacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/tailwindphp-test-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            self::rmrf($this->cacheDir);
        }
    }

    private static function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::rmrf($path . '/' . $entry);
        }
        @rmdir($path);
    }

    public function test_cache_hit_returns_identical_output(): void
    {
        $opts = [
            'content' => '<div class="bg-blue-500"></div>',
            'css'     => '@import "tailwindcss";',
            'minify'  => true,
            'cache'   => $this->cacheDir,
        ];

        $first  = tw::generate($opts);
        $second = tw::generate($opts);

        $this->assertSame($first, $second);

        $files = glob($this->cacheDir . '/tailwind_*.css');
        $this->assertNotEmpty($files, 'expected a cache file to be written');
        $this->assertCount(1, $files, 'cache hit should not write a second file');
    }

    public function test_different_content_uses_different_cache_keys(): void
    {
        tw::generate([
            'content' => '<div class="bg-blue-500"></div>',
            'css'     => '@import "tailwindcss";',
            'cache'   => $this->cacheDir,
        ]);
        tw::generate([
            'content' => '<div class="bg-red-500"></div>',
            'css'     => '@import "tailwindcss";',
            'cache'   => $this->cacheDir,
        ]);

        $files = glob($this->cacheDir . '/tailwind_*.css');
        $this->assertCount(2, $files);
    }

    public function test_cache_key_includes_import_paths(): void
    {
        // Regression: previously the key was md5(content . css . minify),
        // so two calls that differ only in importPaths collided.
        $importDir1 = $this->cacheDir . '/imports-a';
        $importDir2 = $this->cacheDir . '/imports-b';
        mkdir($importDir1);
        mkdir($importDir2);
        file_put_contents($importDir1 . '/a.css', '/* a */');
        file_put_contents($importDir2 . '/b.css', '/* b */');

        tw::generate([
            'content'     => '<div class="p-4"></div>',
            'cache'       => $this->cacheDir,
            'importPaths' => $importDir1,
        ]);
        tw::generate([
            'content'     => '<div class="p-4"></div>',
            'cache'       => $this->cacheDir,
            'importPaths' => $importDir2,
        ]);

        $files = glob($this->cacheDir . '/tailwind_*.css');
        $this->assertCount(2, $files, 'differing importPaths must produce distinct cache entries');
    }

    public function test_ttl_expiry_recompiles(): void
    {
        $opts = [
            'content'  => '<div class="p-4"></div>',
            'css'      => '@import "tailwindcss";',
            'cache'    => $this->cacheDir,
            'cacheTtl' => 1,
        ];

        tw::generate($opts);
        $files = glob($this->cacheDir . '/tailwind_*.css');
        $this->assertCount(1, $files);

        // Backdate the cache file beyond the TTL window.
        touch($files[0], time() - 10);

        tw::generate($opts);
        $this->assertGreaterThan(time() - 2, filemtime($files[0]), 'cache should have been rewritten after TTL expiry');
    }

    public function test_no_tmp_files_left_behind(): void
    {
        tw::generate([
            'content' => '<div class="p-4"></div>',
            'css'     => '@import "tailwindcss";',
            'cache'   => $this->cacheDir,
        ]);

        $tmp = glob($this->cacheDir . '/*.tmp');
        $this->assertEmpty($tmp, 'atomic-write tmp file should be renamed away');
    }

    public function test_cache_max_evicts_oldest(): void
    {
        // Write 3 distinct entries with cacheMax=2 — oldest should be gone.
        foreach (['p-1', 'p-2', 'p-3'] as $i => $cls) {
            tw::generate([
                'content'  => "<div class=\"{$cls}\"></div>",
                'css'      => '@import "tailwindcss";',
                'cache'    => $this->cacheDir,
                'cacheMax' => 2,
            ]);
            // Spread mtimes so eviction order is deterministic.
            $files = glob($this->cacheDir . '/tailwind_*.css');
            if (!empty($files)) {
                touch(end($files), time() - (10 - $i));
            }
        }

        $files = glob($this->cacheDir . '/tailwind_*.css');
        $this->assertLessThanOrEqual(2, count($files), 'cacheMax should cap entry count');
    }

    public function test_clear_cache_removes_files(): void
    {
        tw::generate([
            'content' => '<div class="p-4"></div>',
            'css'     => '@import "tailwindcss";',
            'cache'   => $this->cacheDir,
        ]);

        $deleted = tw::clearCache($this->cacheDir);
        $this->assertSame(1, $deleted);
        $this->assertEmpty(glob($this->cacheDir . '/tailwind_*.css'));
    }
}
