<?php

declare(strict_types=1);

namespace Charged\TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;
use TailwindPHP\tw;

/**
 * Covers the introspection surface of TailwindCompiler / tw — properties(),
 * value(), computedValue(), colors(), breakpoints(), extractCandidates().
 * These are documented entry points; regressing them would silently break
 * downstream consumers (Charged UI, etc).
 */
final class CompilerApiTest extends TestCase
{
    public function test_properties_returns_raw_css_variables(): void
    {
        $props = tw::properties('p-4');
        $this->assertArrayHasKey('padding', $props);
        $this->assertStringContainsString('var(--spacing)', $props['padding']);
    }

    public function test_computed_properties_resolves_variables(): void
    {
        $props = tw::computedProperties('p-4');
        $this->assertSame('1rem', $props['padding']);
    }

    public function test_value_returns_concrete_value_skipping_css_variable(): void
    {
        $this->assertSame('calc(var(--spacing) * 4)', tw::value('p-4'));
    }

    public function test_computed_value(): void
    {
        $this->assertSame('1rem', tw::computedValue('p-4'));
    }

    public function test_value_returns_null_for_unknown_utility(): void
    {
        $this->assertNull(tw::value('nope-not-a-real-utility-xyz'));
    }

    public function test_compile_is_reusable(): void
    {
        $compiler = tw::compile('@import "tailwindcss";');

        $a = $compiler->generate('<div class="p-4"></div>');
        $b = $compiler->generate('<div class="bg-red-500"></div>');

        $this->assertStringContainsString('.p-4', $a);
        $this->assertStringContainsString('.bg-red-500', $b);
        // Second build can additionally retain prior candidates, but must
        // at minimum include the new one.
        $this->assertStringContainsString('.bg-red-500', $compiler->generate('<div class="bg-red-500"></div>'));
    }

    public function test_colors_populated_from_default_theme(): void
    {
        $colors = tw::colors();
        $this->assertGreaterThan(100, count($colors));
        $this->assertArrayHasKey('blue-500', $colors);
    }

    public function test_breakpoints_populated(): void
    {
        $bp = tw::breakpoints();
        $this->assertArrayHasKey('md', $bp);
        $this->assertArrayHasKey('lg', $bp);
    }

    public function test_extract_candidates_dedupes_and_splits(): void
    {
        $html = '<div class="p-4 p-4 bg-red-500"><span class="text-white"></span></div>';
        $candidates = tw::extractCandidates($html);

        sort($candidates);
        $this->assertSame(['bg-red-500', 'p-4', 'text-white'], $candidates);
    }

    public function test_custom_theme_value_overrides_default(): void
    {
        $css = '@import "tailwindcss"; @theme { --color-brand: #1da1f2; }';
        $compiler = tw::compile($css);

        $colors = $compiler->colors();
        $this->assertArrayHasKey('brand', $colors);
        $this->assertStringContainsString('1da1f2', strtolower($colors['brand']));
    }
}
