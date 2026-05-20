<?php

declare(strict_types=1);

namespace TailwindPHP\Plugin;

/**
 * Contract for TailwindPHP plugins.
 *
 * Plugins implement this interface to register utilities, variants,
 * and components with TailwindPHP.
 *
 * Port of: packages/tailwindcss/src/plugin-api.ts
 */
interface PluginInterface
{
    /**
     * The plugin name/identifier referenced from `@plugin` directives.
     *
     * Example: `@tailwindcss/typography` for `@plugin "@tailwindcss/typography"`.
     */
    public function getName(): string;

    /**
     * Execute the plugin, registering utilities/variants/components.
     *
     * @param array<string, mixed> $options Options passed from the @plugin directive
     */
    public function __invoke(PluginAPI $api, array $options = []): void;

    /**
     * Theme extensions to merge into the theme config (equivalent to the
     * second argument of `plugin.withOptions()` in the JS plugin API).
     *
     * @param array<string, mixed> $options Options passed from the @plugin directive
     * @return array<string, mixed>
     */
    public function getThemeExtensions(array $options = []): array;
}
