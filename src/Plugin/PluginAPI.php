<?php

declare(strict_types=1);

namespace TailwindPHP\Plugin;

use TailwindPHP\Theme;
use TailwindPHP\Utilities\Utilities;
use TailwindPHP\Variants\Variants;

/**
 * The plugin API exposed to plugins during registration.
 *
 * Mirrors the TailwindCSS v4 plugin API exactly, so JS plugins ported to
 * PHP can reuse their structure (addBase / addUtilities / matchUtilities /
 * addComponents / matchComponents / addVariant / matchVariant / theme /
 * config / prefix).
 *
 * @see https://tailwindcss.com/docs/plugins
 */
final class PluginAPI
{
    private Theme $theme;
    private Utilities $utilities;
    private Variants $variants;
    private array $config;
    private array $baseStyles = [];
    private array $componentStyles = [];

    public function __construct(
        Theme $theme,
        Utilities $utilities,
        Variants $variants,
        array $config = [],
    ) {
        $this->theme = $theme;
        $this->utilities = $utilities;
        $this->variants = $variants;
        $this->config = $config;
    }

    /** Add base styles (applied to @layer base). */
    public function addBase(array $css): void
    {
        $this->baseStyles[] = $css;
    }

    public function getBaseStyles(): array
    {
        return $this->baseStyles;
    }

    /** Add static utility classes. */
    public function addUtilities(array $utilities, array $options = []): void
    {
        foreach ($utilities as $className => $css) {
            $this->registerUtility($className, $css, $options);
        }
    }

    /** Add functional utilities that accept values. */
    public function matchUtilities(array $utilities, array $options = []): void
    {
        $values = $options['values'] ?? [];
        $supportsNegativeValues = $options['supportsNegativeValues'] ?? false;

        foreach ($utilities as $name => $callback) {
            foreach ($values as $key => $value) {
                $className = $key === 'DEFAULT' ? $name : "{$name}-{$key}";
                $css = $callback($value, ['modifier' => null]);

                if ($css !== null) {
                    $this->registerUtility(".{$className}", $css, $options);
                }

                if ($supportsNegativeValues && is_numeric($value)) {
                    $negativeValue = $this->negate($value);
                    $negativeClassName = "-{$className}";
                    $negativeCss = $callback($negativeValue, ['modifier' => null]);

                    if ($negativeCss !== null) {
                        $this->registerUtility(".{$negativeClassName}", $negativeCss, $options);
                    }
                }
            }

            $this->utilities->addFunctional($name, $callback, $options);
        }
    }

    /** Add static component classes. */
    public function addComponents(array $components, array $options = []): void
    {
        foreach ($components as $className => $css) {
            $this->componentStyles[$className] = $css;
            $this->registerUtility($className, $css, array_merge($options, ['layer' => 'components']));
        }
    }

    public function getComponentStyles(): array
    {
        return $this->componentStyles;
    }

    /** Add functional components that accept values. */
    public function matchComponents(array $components, array $options = []): void
    {
        $this->matchUtilities($components, array_merge($options, ['layer' => 'components']));
    }

    /** Add a static variant. */
    public function addVariant(string $name, string|array $variant): void
    {
        $this->variants->addPluginVariant($name, $variant);
    }

    /** Add a functional variant that accepts values. */
    public function matchVariant(string $name, callable $callback, array $options = []): void
    {
        $values = $options['values'] ?? [];

        foreach ($values as $key => $value) {
            $variantName = $key === 'DEFAULT' ? $name : "{$name}-{$key}";
            $selector = $callback($value, ['modifier' => null]);
            $this->variants->addPluginVariant($variantName, $selector);
        }

        $this->variants->addFunctionalVariant($name, $callback, $options);
    }

    /**
     * Look up a value in the theme. Supports the `key/modifier` syntax for
     * opacity-style modifiers (e.g. `colors.red.500/50`).
     */
    public function theme(string $path, mixed $defaultValue = null): mixed
    {
        $modifier = null;
        if (str_contains($path, '/')) {
            $parts = explode('/', $path, 2);
            $path = trim($parts[0]);
            $modifier = trim($parts[1]);
        }

        // Config overrides (e.g. theme.typography from compile options) take
        // precedence over the resolved theme.
        $themeConfig = $this->config['theme'] ?? [];
        if (!empty($themeConfig)) {
            $configValue = $this->resolvePath($themeConfig, $path, null);
            if ($configValue !== null) {
                return $configValue;
            }
        }

        $value = $this->resolveThemePath($path, $defaultValue);

        if ($modifier !== null && is_string($value)) {
            return $this->applyOpacityModifier($value, $modifier);
        }

        return $value;
    }

    public function config(?string $path = null, mixed $defaultValue = null): mixed
    {
        if ($path === null) {
            return $this->config;
        }

        return $this->resolvePath($this->config, $path, $defaultValue);
    }

    public function prefix(string $className): string
    {
        $prefix = $this->theme->getPrefix();

        return $prefix === null ? $className : "{$prefix}:{$className}";
    }

    private function registerUtility(string $className, array $css, array $options): void
    {
        $name = ltrim($className, '.');
        $declarations = $this->cssToDeclarations($css);
        $this->utilities->addPluginUtility($name, $declarations, $options);
    }

    private function cssToDeclarations(array $css): array
    {
        $declarations = [];

        foreach ($css as $property => $value) {
            if (is_int($property)) {
                continue;
            }

            if (is_array($value)) {
                if ($this->isNestedSelector($property)) {
                    $declarations[$property] = $this->cssToDeclarations($value);
                } else {
                    foreach ($value as $v) {
                        $declarations[] = [$this->toKebabCase($property), (string) $v];
                    }
                }
            } else {
                $declarations[$this->toKebabCase($property)] = is_int($value) || is_float($value) ? (string) $value : $value;
            }
        }

        return $declarations;
    }

    private function isNestedSelector(string|int $property): bool
    {
        if (is_int($property)) {
            return false;
        }

        return str_starts_with($property, '&') ||
               str_starts_with($property, '@') ||
               str_starts_with($property, '.') ||
               str_contains($property, ' ') ||
               str_contains($property, ':') ||
               str_contains($property, '>');
    }

    private function toKebabCase(string|int $str): string
    {
        if (is_int($str)) {
            return (string) $str;
        }

        return strtolower(preg_replace('/([A-Z])/', '-$1', $str));
    }

    private function resolveThemePath(string $path, mixed $default): mixed
    {
        $parts = explode('.', $path);
        $namespace = array_shift($parts);

        $namespaceMap = [
            'colors' => '--color',
            'spacing' => '--spacing',
            'fontSize' => '--font-size',
            'fontFamily' => '--font-family',
            'fontWeight' => '--font-weight',
            'lineHeight' => '--line-height',
            'letterSpacing' => '--letter-spacing',
            'borderRadius' => '--radius',
            'borderWidth' => '--border-width',
            'boxShadow' => '--shadow',
            'opacity' => '--opacity',
            'zIndex' => '--z-index',
            'width' => '--width',
            'height' => '--height',
            'maxWidth' => '--max-width',
            'screens' => '--breakpoint',
        ];

        $themeNamespace = $namespaceMap[$namespace] ?? "--{$this->toKebabCase($namespace)}";

        if (empty($parts)) {
            return $this->theme->namespace($themeNamespace);
        }

        $themeKey = $themeNamespace . '-' . implode('-', $parts);
        $value = $this->theme->get([$themeKey]);

        return $value ?? $default;
    }

    private function resolvePath(array $data, string $path, mixed $default): mixed
    {
        $parts = explode('.', $path);
        $current = $data;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }

    private function negate(string $value): string
    {
        return str_starts_with($value, '-') ? substr($value, 1) : "-{$value}";
    }

    private function applyOpacityModifier(string $value, string $opacity): string
    {
        if (str_ends_with($opacity, '%')) {
            $opacity = rtrim($opacity, '%');
        }

        return "color-mix(in oklab, {$value} {$opacity}%, transparent)";
    }
}
