<?php

declare(strict_types=1);

namespace Charged\TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;
use TailwindPHP\tw;

/**
 * Regression suite for the @import path-traversal hardening.
 *
 * Without containment, a CSS input containing `@import "/etc/passwd"`
 * or `@import "../../../etc/passwd"` would let the compiler read any
 * file the PHP process can read — a real concern for any consumer that
 * accepts user-supplied CSS (CMS theme editors, multi-tenant SaaS, etc).
 *
 * The compiler now refuses any @import that resolves outside the
 * directories explicitly listed in `importPaths` (and the directory of
 * the importing file). These tests lock that behaviour in.
 */
final class ImportSecurityTest extends TestCase
{
    private string $sandbox;
    private string $outside;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/twphp-sandbox-' . bin2hex(random_bytes(6));
        $this->outside = sys_get_temp_dir() . '/twphp-outside-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0755, true);
        mkdir($this->outside, 0755, true);
    }

    protected function tearDown(): void
    {
        self::rmrf($this->sandbox);
        self::rmrf($this->outside);
    }

    public function test_absolute_path_outside_search_paths_is_blocked(): void
    {
        file_put_contents($this->outside . '/secret.css', '.leaked { content: "i-was-leaked"; }');

        $css = tw::generate([
            'content'     => '<div></div>',
            'css'         => '@import "' . $this->outside . '/secret.css";',
            'importPaths' => $this->sandbox,
        ]);

        $this->assertStringNotContainsString('i-was-leaked', $css);
        $this->assertStringNotContainsString('.leaked', $css);
    }

    public function test_relative_traversal_outside_search_paths_is_blocked(): void
    {
        // Drop the secret under the outside dir, then craft an @import
        // that escapes the sandbox via ../ traversal.
        file_put_contents($this->outside . '/secret.css', '.leaked { content: "i-was-leaked"; }');

        $relative = self::relativePath($this->sandbox, $this->outside . '/secret.css');

        $css = tw::generate([
            'content'     => '<div></div>',
            'css'         => '@import "' . $relative . '";',
            'importPaths' => $this->sandbox,
        ]);

        $this->assertStringNotContainsString('i-was-leaked', $css);
    }

    public function test_legitimate_import_inside_search_paths_still_resolves(): void
    {
        file_put_contents($this->sandbox . '/legit.css', '.legit-marker { color: red; }');

        $css = tw::generate([
            'content'     => '<div class="legit-marker"></div>',
            'css'         => '@import "legit.css";',
            'importPaths' => $this->sandbox,
        ]);

        $this->assertStringContainsString('.legit-marker', $css);
    }

    public function test_callable_resolver_bypasses_filesystem_containment(): void
    {
        // The opt-out for consumers that legitimately need cross-tree
        // imports: a custom resolver is opaque to the security layer.
        $resolver = static fn (?string $uri, ?string $fromFile): ?string =>
            '.from-resolver { content: "ok"; }';

        $css = tw::generate([
            'content'     => '<div></div>',
            'css'         => '/* resolver-only */',
            'importPaths' => $resolver,
        ]);

        $this->assertStringContainsString('.from-resolver', $css);
    }

    /**
     * Build a string that, joined to $base, resolves to $target via ../ traversal.
     */
    private static function relativePath(string $base, string $target): string
    {
        $base = realpath($base);
        $target = realpath(dirname($target)) . '/' . basename($target);

        $baseParts = explode('/', trim($base, '/'));
        $targetParts = explode('/', trim($target, '/'));

        $common = 0;
        $max = min(count($baseParts), count($targetParts));
        while ($common < $max && $baseParts[$common] === $targetParts[$common]) {
            $common++;
        }

        $up = str_repeat('../', count($baseParts) - $common);
        $down = implode('/', array_slice($targetParts, $common));

        return $up . $down;
    }

    private static function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
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
}
