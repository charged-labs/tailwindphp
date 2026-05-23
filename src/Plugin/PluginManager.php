<?php

declare(strict_types=1);

namespace TailwindPHP\Plugin;

use TailwindPHP\Theme;
use TailwindPHP\Utilities\Utilities;
use TailwindPHP\Variants\Variants;

/**
 * Handles plugin registration and execution.
 *
 * Built-in plugins (`@tailwindcss/forms`, `@tailwindcss/typography`) are
 * known statically; consumer plugins can be added via {@see register()}.
 * Used as a process-wide singleton via the `getPluginManager()` accessor
 * in src/index.php.
 */
final class PluginManager
{
    /** @var array<string, PluginInterface> */
    private array $plugins = [];

    /** @var array<string, class-string<PluginInterface>> */
    private static array $builtInPlugins = [
        '@tailwindcss/typography' => Plugins\TypographyPlugin::class,
        '@tailwindcss/forms' => Plugins\FormsPlugin::class,
    ];

    public function register(PluginInterface $plugin): void
    {
        $this->plugins[$plugin->getName()] = $plugin;
    }

    public static function registerBuiltIn(string $name, string $class): void
    {
        self::$builtInPlugins[$name] = $class;
    }

    public function has(string $name): bool
    {
        return isset($this->plugins[$name]) || isset(self::$builtInPlugins[$name]);
    }

    public function get(string $name): ?PluginInterface
    {
        if (isset($this->plugins[$name])) {
            return $this->plugins[$name];
        }

        if (isset(self::$builtInPlugins[$name])) {
            $class = self::$builtInPlugins[$name];
            $this->plugins[$name] = new $class();

            return $this->plugins[$name];
        }

        return null;
    }

    public function execute(string $name, PluginAPI $api, array $options = []): bool
    {
        $plugin = $this->get($name);

        if ($plugin === null) {
            return false;
        }

        $plugin($api, $options);

        return true;
    }

    public function getThemeExtensions(string $name, array $options = []): array
    {
        $plugin = $this->get($name);

        return $plugin === null ? [] : $plugin->getThemeExtensions($options);
    }

    public function getRegisteredPlugins(): array
    {
        return array_unique(array_merge(
            array_keys($this->plugins),
            array_keys(self::$builtInPlugins),
        ));
    }

    /**
     * Forget all consumer-registered plugins (built-ins stay).
     *
     * Lets test suites isolate state and lets long-running processes drop
     * stale plugin instances between requests.
     */
    public function reset(): void
    {
        $this->plugins = [];
    }

    public function createAPI(
        Theme $theme,
        Utilities $utilities,
        Variants $variants,
        array $config = [],
    ): PluginAPI {
        return new PluginAPI($theme, $utilities, $variants, $config);
    }

    public function applyPlugins(
        array $pluginRefs,
        Theme $theme,
        Utilities $utilities,
        Variants $variants,
        array $config = [],
    ): PluginAPI {
        $api = $this->createAPI($theme, $utilities, $variants, $config);

        foreach ($pluginRefs as $ref) {
            if (is_string($ref)) {
                $name = $ref;
                $options = [];
            } else {
                $name = $ref['name'];
                $options = $ref['options'] ?? [];
            }

            $themeExtensions = $this->getThemeExtensions($name, $options);
            $this->applyThemeExtensions($theme, $themeExtensions);
            $this->execute($name, $api, $options);
        }

        return $api;
    }

    private function applyThemeExtensions(Theme $theme, array $extensions): void
    {
        foreach ($extensions as $namespace => $values) {
            if (!is_array($values)) {
                continue;
            }

            $themeNamespace = '--' . strtolower(preg_replace('/([A-Z])/', '-$1', $namespace));

            foreach ($values as $key => $value) {
                if ($key === 'DEFAULT') {
                    $theme->add($themeNamespace, $value);
                } else {
                    $theme->add("{$themeNamespace}-{$key}", $value);
                }
            }
        }
    }
}
