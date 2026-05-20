<?php

declare(strict_types=1);

namespace TailwindPHP\Exception;

/**
 * Raised when `@plugin "name"` references a plugin that hasn't been
 * registered with the plugin manager.
 *
 * Built-in plugins (`@tailwindcss/forms`, `@tailwindcss/typography`)
 * are auto-registered; third-party plugins must be passed through
 * `registerPlugin()` (or `PluginManager::register()`) before use.
 */
class UnknownPluginException extends InvalidCssException
{
}
