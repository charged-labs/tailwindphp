<?php

declare(strict_types=1);

namespace TailwindPHP;

use function TailwindPHP\CssParser\parse;

/**
 * TailwindCompiler - Compiled Tailwind instance for reuse.
 *
 * Provides a compiled Tailwind instance that can be reused for multiple
 * operations without re-parsing the CSS configuration.
 *
 * ```php
 * use TailwindPHP\tw;
 *
 * $tw = tw::compile('@import "tailwindcss"; @theme { --color-brand: #3b82f6; }');
 *
 * $css   = $tw->generate('<div class="flex p-4">');
 * $props = $tw->properties('p-4');
 * $value = $tw->value('p-4');
 * ```
 */
final class TailwindCompiler
{
    private readonly array $compiled;
    private readonly object $designSystem;
    private readonly Theme $theme;

    public function __construct(string $css = '@import "tailwindcss";', array $options = [])
    {
        $ast = parse($css);

        $this->compiled = compileAst($ast, $options);
        $this->designSystem = $this->compiled['designSystem'];
        $this->theme = $this->designSystem->getTheme();
    }

    /**
     * Generate CSS from content containing Tailwind classes.
     */
    public function generate(string $content): string
    {
        $candidates = extractCandidates($content);

        return $this->compiled['build']($candidates);
    }

    /**
     * Generate CSS from an array of class candidates.
     *
     * @param array<string> $candidates
     */
    public function css(array $candidates): string
    {
        return $this->compiled['build']($candidates);
    }

    /**
     * Raw CSS properties for a utility class (CSS variables not resolved).
     *
     * @param string|array<string> $utilities
     * @return array<string, string>
     */
    public function properties(string|array $utilities): array
    {
        $utilities = is_string($utilities) ? [$utilities] : $utilities;
        $result = [];

        foreach ($utilities as $utility) {
            foreach ($this->getDeclarations($utility) as $decl) {
                $result[$decl['property']] = $decl['value'];
            }
        }

        return $result;
    }

    /**
     * CSS properties with CSS variables resolved to their values.
     *
     * @param string|array<string> $utilities
     * @return array<string, string>
     */
    public function computedProperties(string|array $utilities): array
    {
        $utilities = is_string($utilities) ? [$utilities] : $utilities;
        $result = [];

        foreach ($utilities as $utility) {
            foreach ($this->getDeclarations($utility) as $decl) {
                $result[$decl['property']] = $this->resolveValue($decl['value']);
            }
        }

        return $result;
    }

    /**
     * Raw value for a utility class. If the first declaration is a CSS
     * variable (`--*`), skip it to return a more useful concrete value.
     */
    public function value(string $utility): ?string
    {
        $declarations = $this->getDeclarations($utility);
        if (empty($declarations)) {
            return null;
        }

        $first = $declarations[0];
        if (str_starts_with($first['property'], '--')) {
            foreach ($declarations as $decl) {
                if (!str_starts_with($decl['property'], '--')) {
                    return $decl['value'];
                }
            }
        }

        return $first['value'];
    }

    /**
     * Computed value for a utility class, with CSS variables resolved.
     */
    public function computedValue(string $utility): ?string
    {
        $value = $this->value($utility);

        return $value === null ? null : $this->resolveValue($value);
    }

    /**
     * Extract class name candidates from HTML content.
     *
     * @return array<string>
     */
    public function extractCandidates(string $html): array
    {
        return extractCandidates($html);
    }

    public function minify(string $css): string
    {
        return \TailwindPHP\Minifier\CssMinifier::minify($css);
    }

    /**
     * @return array{build: callable, sources: array, root: array, features: int, designSystem: object}
     */
    public function getCompiled(): array
    {
        return $this->compiled;
    }

    public function getDesignSystem(): object
    {
        return $this->designSystem;
    }

    public function getTheme(): Theme
    {
        return $this->theme;
    }

    /**
     * Map of color name → computed value.
     *
     * @return array<string, string>
     */
    public function colors(): array
    {
        return $this->getThemeNamespace('color');
    }

    /**
     * @return array<string, string>
     */
    public function breakpoints(): array
    {
        return $this->getThemeNamespace('breakpoint');
    }

    /**
     * Custom `--spacing-*` values defined in the theme. (Tailwind v4 uses
     * a single `--spacing` base; this returns only the explicit overrides.)
     *
     * @return array<string, string>
     */
    public function spacing(): array
    {
        return $this->getThemeNamespace('spacing');
    }

    /**
     * @return array<string, string>
     */
    private function getThemeNamespace(string $namespace): array
    {
        $prefix = "--{$namespace}-";
        $result = [];

        foreach ($this->theme->entries() as [$key, $entry]) {
            if (str_starts_with($key, $prefix)) {
                $name = substr($key, strlen($prefix));
                $result[$name] = $this->resolveValue($entry['value']);
            }
        }

        return $result;
    }

    /**
     * @return array<array{property: string, value: string}>
     */
    private function getDeclarations(string $utility): array
    {
        $candidates = $this->designSystem->parseCandidate($utility);
        if (empty($candidates)) {
            return [];
        }

        $declarations = [];
        foreach ($candidates as $candidate) {
            $rules = $this->designSystem->compileAstNodes($candidate, \TailwindPHP\Compile\COMPILE_FLAG_NONE);
            if (empty($rules)) {
                continue;
            }

            foreach ($rules as $ruleInfo) {
                if (!isset($ruleInfo['node'])) {
                    continue;
                }
                $this->extractDeclarations($ruleInfo['node'], $declarations);
            }

            if (!empty($declarations)) {
                break;
            }
        }

        return $declarations;
    }

    /**
     * Recursively collect declaration nodes, skipping `@property` internals
     * (syntax, inherits, initial-value) which are implementation detail.
     */
    private function extractDeclarations(array $node, array &$declarations): void
    {
        if ($node['kind'] === 'at-rule' && ($node['name'] ?? '') === '@property') {
            return;
        }

        if ($node['kind'] === 'declaration' && isset($node['property'], $node['value'])) {
            $prop = $node['property'];
            if ($prop === 'syntax' || $prop === 'inherits' || $prop === 'initial-value') {
                return;
            }

            $declarations[] = ['property' => $prop, 'value' => $node['value']];

            return;
        }

        if (isset($node['nodes']) && is_array($node['nodes'])) {
            foreach ($node['nodes'] as $child) {
                $this->extractDeclarations($child, $declarations);
            }
        }
    }

    /**
     * Resolve CSS variables in a value. Recognizes the common Tailwind
     * patterns (`calc(var(--spacing) * N)`, `var(--name)`,
     * `var(--name, fallback)`) and falls back to LightningCSS-style value
     * optimization for everything else.
     */
    private function resolveValue(string $value): string
    {
        // calc(var(--spacing) * N)
        if (preg_match('/^calc\(var\(--spacing\)\s*\*\s*(\d+(?:\.\d+)?)\)$/', $value, $matches)) {
            $multiplier = (float) $matches[1];
            $spacing = $this->theme->get(['--spacing']);
            if ($spacing !== null && preg_match('/^([\d.]+)(.*)$/', $spacing, $spacingMatches)) {
                $baseValue = (float) $spacingMatches[1];
                $unit = $spacingMatches[2];
                $computed = $baseValue * $multiplier;
                $formatted = rtrim(rtrim(number_format($computed, 4, '.', ''), '0'), '.');

                return $formatted . $unit;
            }
        }

        // var(--name)
        if (preg_match('/^var\(--([^)]+)\)$/', $value, $matches)) {
            $resolved = $this->theme->get(['--' . $matches[1]]);
            if ($resolved !== null) {
                return $this->resolveValue($resolved);
            }
        }

        // var(--name, fallback)
        if (preg_match('/^var\(--([^,)]+),\s*([^)]+)\)$/', $value, $matches)) {
            $resolved = $this->theme->get(['--' . $matches[1]]);
            if ($resolved !== null) {
                return $this->resolveValue($resolved);
            }

            return $this->resolveValue(trim($matches[2]));
        }

        // var() embedded inside a larger expression
        if (str_contains($value, 'var(')) {
            $value = preg_replace_callback('/var\(--([^,)]+)(?:,\s*([^)]+))?\)/', function ($m) {
                $resolved = $this->theme->get(['--' . $m[1]]);
                if ($resolved !== null) {
                    return $this->resolveValue($resolved);
                }
                if (isset($m[2])) {
                    return $this->resolveValue(trim($m[2]));
                }

                return $m[0];
            }, $value) ?? $value;
        }

        return \TailwindPHP\Normalizer\ValueNormalizer::optimizeValue($value);
    }
}
