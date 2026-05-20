<?php

declare(strict_types=1);

namespace TailwindPHP;

/**
 * TailwindPHP - Main facade for CSS generation.
 *
 * ```php
 * use TailwindPHP\tw;
 *
 * $css = tw::generate('<div class="flex p-4">Hello</div>');
 * $css = tw::generate($html, '@import "tailwindcss"; @theme { --color-brand: #3b82f6; }');
 *
 * $props = tw::properties('p-4');         // ['padding' => 'calc(var(--spacing) * 4)']
 * $props = tw::computedProperties('p-4'); // ['padding' => '1rem']
 *
 * $tw = tw::compile('@import "tailwindcss";');
 * $css = $tw->generate('<div class="flex">');
 * ```
 */
final class Tailwind
{
    /**
     * Cache of compiled instances keyed by CSS source.
     *
     * Multiple `tw::` introspection calls in the same request (`properties`,
     * `value`, `colors`, ...) all need a TailwindCompiler against the same
     * CSS. Building one is non-trivial — it parses CSS, resolves `@import`,
     * builds the design system. We memoize so the second call is free.
     *
     * @var array<string, TailwindCompiler>
     */
    private static array $compilers = [];

    private static function compilerFor(string $css): TailwindCompiler
    {
        return self::$compilers[$css] ??= new TailwindCompiler($css);
    }

    /**
     * Forget memoized compiler instances. Mainly useful in long-running
     * processes (queue workers, dev servers) that want to reclaim memory,
     * or in tests that mutate global plugin state.
     */
    public static function resetCompilerCache(): void
    {
        self::$compilers = [];
    }

    /**
     * Generate CSS from content containing Tailwind classes.
     *
     * @param string|array{
     *     content: string,
     *     css?: string,
     *     importPaths?: string|array<string>|callable(string|null, string|null): ?string,
     *     minify?: bool,
     *     cache?: string|bool|null,
     *     cacheTtl?: int|null
     * } $input
     */
    public static function generate(string|array $input, string $css = '@import "tailwindcss";'): string
    {
        return generate($input, $css);
    }

    public static function compile(string $css = '@import "tailwindcss";', array $options = []): TailwindCompiler
    {
        return new TailwindCompiler($css, $options);
    }

    /**
     * Raw CSS properties for utility class(es).
     *
     * @return array<string, string>
     */
    public static function properties(string|array $input, string $css = '@import "tailwindcss";'): array
    {
        [$utilities, $cssConfig] = self::parseInput($input, $css);

        return self::compilerFor($cssConfig)->properties($utilities);
    }

    /**
     * @return array<string, string>
     */
    public static function computedProperties(string|array $input, string $css = '@import "tailwindcss";'): array
    {
        [$utilities, $cssConfig] = self::parseInput($input, $css);

        return self::compilerFor($cssConfig)->computedProperties($utilities);
    }

    public static function value(string|array $input, string $css = '@import "tailwindcss";'): ?string
    {
        [$utilities, $cssConfig] = self::parseInput($input, $css);
        $utility = is_array($utilities) ? $utilities[0] : $utilities;

        return self::compilerFor($cssConfig)->value($utility);
    }

    public static function computedValue(string|array $input, string $css = '@import "tailwindcss";'): ?string
    {
        [$utilities, $cssConfig] = self::parseInput($input, $css);
        $utility = is_array($utilities) ? $utilities[0] : $utilities;

        return self::compilerFor($cssConfig)->computedValue($utility);
    }

    /**
     * @return array<string>
     */
    public static function extractCandidates(string $html): array
    {
        return extractCandidates($html);
    }

    public static function minify(string $css): string
    {
        return \TailwindPHP\Minifier\CssMinifier::minify($css);
    }

    public static function clearCache(string|bool|null $cache = true): int
    {
        return clearCache($cache);
    }

    /**
     * @return array<string, string>
     */
    public static function colors(string $css = '@import "tailwindcss";'): array
    {
        return self::compilerFor($css)->colors();
    }

    /**
     * @return array<string, string>
     */
    public static function breakpoints(string $css = '@import "tailwindcss";'): array
    {
        return self::compilerFor($css)->breakpoints();
    }

    /**
     * @return array<string, string>
     */
    public static function spacing(string $css = '@import "tailwindcss";'): array
    {
        return self::compilerFor($css)->spacing();
    }

    /**
     * @return array{0: string|array<string>, 1: string}
     */
    private static function parseInput(string|array $input, string $css): array
    {
        if (is_string($input)) {
            return [$input, $css];
        }

        if (isset($input['content'])) {
            return [$input['content'], $input['css'] ?? $css];
        }

        return [$input, $css];
    }
}

// Short alias: `tw::generate()` instead of `Tailwind::generate()`.
class_alias(Tailwind::class, 'TailwindPHP\\tw');
