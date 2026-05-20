<?php

declare(strict_types=1);

namespace ChargedLabs\TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;
use TailwindPHP\tw;

/**
 * Sanity checks that the compiler boots and produces expected output.
 *
 * Kept minimal on purpose — exhaustive coverage of every utility/variant
 * would duplicate the upstream Tailwind test suite. These tests confirm
 * the Packagist package wires up correctly and the public entry point
 * returns plausible CSS.
 */
final class SmokeTest extends TestCase
{
    private const APP_CSS = '@import "tailwindcss";';

    public function test_generate_returns_non_empty_string(): void
    {
        $css = tw::generate([
            'content' => '<div class="bg-blue-500"></div>',
            'css'     => self::APP_CSS,
            'minify'  => true,
        ]);

        $this->assertIsString($css);
        $this->assertNotSame('', $css);
    }

    public function test_generate_emits_requested_utility(): void
    {
        $css = tw::generate([
            'content' => '<div class="rounded-2xl p-4"></div>',
            'css'     => self::APP_CSS,
            'minify'  => true,
        ]);

        $this->assertStringContainsString('.rounded-2xl', $css);
        $this->assertStringContainsString('.p-4', $css);
    }

    public function test_generate_emits_variant_selectors(): void
    {
        $css = tw::generate([
            'content' => '<div class="hover:bg-blue-600 dark:bg-neutral-900"></div>',
            'css'     => self::APP_CSS,
            'minify'  => true,
        ]);

        $this->assertStringContainsString('.hover\:bg-blue-600', $css);
        $this->assertStringContainsString('.dark\:bg-neutral-900', $css);
    }

    public function test_generate_handles_arbitrary_values(): void
    {
        $css = tw::generate([
            'content' => '<div class="w-[42px] bg-[#1da1f2]"></div>',
            'css'     => self::APP_CSS,
            'minify'  => true,
        ]);

        $this->assertStringContainsString('42px', $css);
        $this->assertStringContainsString('#1da1f2', $css);
    }

    public function test_generate_omits_unreferenced_utilities(): void
    {
        $css = tw::generate([
            'content' => '<div class="text-red-500"></div>',
            'css'     => self::APP_CSS,
            'minify'  => true,
        ]);

        $this->assertStringNotContainsString('.bg-purple-500', $css);
    }
}
