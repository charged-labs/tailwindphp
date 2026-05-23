<?php

declare(strict_types=1);

namespace Charged\TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;

use function TailwindPHP\cn;
use function TailwindPHP\compose;
use function TailwindPHP\join;
use function TailwindPHP\merge;
use function TailwindPHP\variants;

/**
 * Covers the cn() / merge() / join() / variants() / compose() helpers.
 * These are the recommended templating surface, so they need explicit
 * locked-in behaviour.
 */
final class ClassNamesTest extends TestCase
{
    public function test_cn_resolves_tailwind_conflicts(): void
    {
        $this->assertSame('py-1 px-4', cn('px-2 py-1', 'px-4'));
    }

    public function test_cn_handles_conditional_array(): void
    {
        $this->assertSame('btn btn-lg', cn('btn', ['btn-lg' => true, 'btn-sm' => false]));
    }

    public function test_cn_drops_falsy_values(): void
    {
        $this->assertSame('btn', cn('btn', null, false, ''));
    }

    public function test_merge_later_wins(): void
    {
        $this->assertSame('text-blue-500', merge('text-red-500', 'text-blue-500'));
    }

    public function test_merge_respects_variant_prefix(): void
    {
        // hover:bg-* and bg-* are independent groups.
        $result = merge('hover:bg-red-500 bg-red-500', 'hover:bg-blue-500');
        $this->assertStringContainsString('bg-red-500', $result);
        $this->assertStringContainsString('hover:bg-blue-500', $result);
        $this->assertStringNotContainsString('hover:bg-red-500', $result);
    }

    public function test_join_skips_falsy(): void
    {
        $this->assertSame('foo bar', join('foo', null, 'bar'));
    }

    public function test_variants_applies_defaults(): void
    {
        $button = variants([
            'base' => 'btn',
            'variants' => [
                'size' => ['sm' => 'text-sm', 'md' => 'text-base'],
            ],
            'defaultVariants' => ['size' => 'md'],
        ]);

        $this->assertStringContainsString('btn', $button());
        $this->assertStringContainsString('text-base', $button());
    }

    public function test_variants_overrides_defaults(): void
    {
        $button = variants([
            'base' => 'btn',
            'variants' => [
                'size' => ['sm' => 'text-sm', 'md' => 'text-base'],
            ],
            'defaultVariants' => ['size' => 'md'],
        ]);

        $this->assertStringContainsString('text-sm', $button(['size' => 'sm']));
        $this->assertStringNotContainsString('text-base', $button(['size' => 'sm']));
    }

    public function test_variants_merges_extra_class(): void
    {
        $button = variants(['base' => 'btn']);
        $this->assertStringContainsString('mt-4', $button(['class' => 'mt-4']));
    }

    public function test_compose_merges_two_components(): void
    {
        $box = variants([
            'variants' => ['shadow' => ['sm' => 'shadow-sm', 'md' => 'shadow-md']],
        ]);
        $stack = variants([
            'variants' => ['gap' => ['1' => 'gap-1', '2' => 'gap-2']],
        ]);

        $card = compose($box, $stack);
        $result = $card(['shadow' => 'md', 'gap' => '2']);

        $this->assertStringContainsString('shadow-md', $result);
        $this->assertStringContainsString('gap-2', $result);
    }
}
