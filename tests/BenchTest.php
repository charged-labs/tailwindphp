<?php

declare(strict_types=1);

namespace Charged\TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;
use TailwindPHP\tw;

/**
 * Performance regression guard.
 *
 * The README advertises specific compile-time targets (10/30/90 ms for
 * 100/500/2000 utilities). This test verifies them with generous bounds
 * (3× the advertised time) so CI flakiness doesn't trip the alarm, but
 * a future change that introduces O(n²) blowup or a cold-start
 * regression will fail loudly.
 *
 * Not part of the default suite — gated by an env var so devs running
 * `composer test` on warm caches don't get noise from CPU jitter.
 * Enable with:
 *
 *     TAILWINDPHP_BENCH=1 composer test
 *
 * CI runs this on a single PHP version on the matrix.
 */
final class BenchTest extends TestCase
{
    private const README_TARGETS_MS = [
        100  => 10,
        500  => 30,
        2000 => 90,
    ];

    private const TOLERANCE = 3.0; // 3x the README target

    protected function setUp(): void
    {
        if (getenv('TAILWINDPHP_BENCH') !== '1') {
            $this->markTestSkipped('set TAILWINDPHP_BENCH=1 to run perf benchmarks');
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function utilityCountProvider(): iterable
    {
        foreach (self::README_TARGETS_MS as $count => $targetMs) {
            yield "{$count} utilities" => [$count, $targetMs];
        }
    }

    /**
     * @dataProvider utilityCountProvider
     */
    public function test_compile_time_within_readme_bounds(int $count, int $targetMs): void
    {
        $content = self::generateContent($count);

        // Warmup: prime the static caches inside Tailwind/loadDefaultTheme.
        tw::generate(['content' => '<div class="p-1"></div>']);

        // Average across three runs to absorb single-shot CPU jitter.
        $samples = [];
        for ($i = 0; $i < 3; $i++) {
            $start = microtime(true);
            tw::generate(['content' => $content]);
            $samples[] = (microtime(true) - $start) * 1000;
        }
        sort($samples);
        $median = $samples[1];

        $bound = $targetMs * self::TOLERANCE;
        $this->assertLessThan(
            $bound,
            $median,
            sprintf(
                "Compile of %d utilities took %.1fms median (samples: %s); "
                . "README target is %dms, bound is %.0fms (%.1fx).",
                $count,
                $median,
                implode(', ', array_map(fn ($s) => sprintf('%.1f', $s), $samples)),
                $targetMs,
                $bound,
                self::TOLERANCE,
            ),
        );

        // Print the numbers regardless so CI logs surface trend data.
        fprintf(
            STDERR,
            "\n  bench: %d utilities -> %.1fms median (target %dms, bound %.0fms)\n",
            $count,
            $median,
            $targetMs,
            $bound,
        );
    }

    /**
     * Build an HTML blob containing the requested number of distinct utility
     * candidates. Mix of static and arbitrary values so the variant resolver
     * and value parser both see realistic load.
     */
    private static function generateContent(int $count): string
    {
        $pool = [
            // static
            'flex', 'grid', 'block', 'inline', 'hidden', 'absolute', 'relative', 'fixed', 'sticky',
            'p-1', 'p-2', 'p-3', 'p-4', 'p-6', 'p-8', 'px-1', 'px-2', 'px-4', 'py-1', 'py-2', 'py-4',
            'm-1', 'm-2', 'm-4', 'mt-2', 'mb-2', 'ml-4', 'mr-4',
            'w-full', 'w-1/2', 'w-1/3', 'w-1/4', 'h-full', 'h-screen',
            'rounded', 'rounded-md', 'rounded-lg', 'rounded-xl', 'rounded-full',
            'shadow', 'shadow-md', 'shadow-lg', 'shadow-xl',
            'border', 'border-2', 'border-blue-500', 'border-red-500',
            'bg-white', 'bg-black', 'bg-blue-500', 'bg-red-500', 'bg-green-500', 'bg-yellow-500',
            'text-sm', 'text-base', 'text-lg', 'text-xl', 'text-2xl', 'text-3xl',
            'font-bold', 'font-medium', 'font-light',
            'text-white', 'text-black', 'text-blue-700', 'text-gray-500',
            'hover:bg-blue-600', 'hover:text-white', 'hover:underline', 'focus:outline-none',
            'md:flex', 'md:hidden', 'lg:block', 'dark:bg-gray-900', 'dark:text-white',
        ];

        $classes = [];
        // Front-load the static pool so the resolver hits its hot paths.
        foreach ($pool as $cls) {
            if (count($classes) >= $count) {
                break;
            }
            $classes[] = $cls;
        }
        // Pad with unique arbitrary-value classes — each one is a distinct
        // candidate that exercises the value parser independently.
        $i = 0;
        while (count($classes) < $count) {
            $classes[] = 'p-[' . $i . 'px]';
            $i++;
        }

        return '<div class="' . implode(' ', $classes) . '"></div>';
    }
}
