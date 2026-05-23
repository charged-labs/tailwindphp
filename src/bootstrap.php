<?php

/**
 * Canonical loader for the procedural TailwindPHP files.
 *
 * The compiler is a 1:1 port of the upstream JavaScript codebase, organised
 * into procedural PHP files that must be required in a specific dependency
 * order. Composer registers this file via the `files` autoload entry, so
 * `require 'vendor/autoload.php'` is enough to make `TailwindPHP\tw::generate()`
 * available.
 *
 * Class-shaped surface (`Tailwind`, `TailwindCompiler`, `CssMinifier`,
 * `LightningCss`, `PluginInterface`, `PluginAPI`, `PluginManager`, the
 * bundled plugins) is loaded via PSR-4 instead — see composer.json.
 *
 * @package Charged\Tailwind
 */

declare(strict_types=1);

if (defined('CHARGED_TAILWIND_LOADED')) {
    return;
}
define('CHARGED_TAILWIND_LOADED', true);

$lib = __DIR__;
$files = [
    'attribute-selector-parser.php',
    'utils/segment.php',
    'utils/escape.php',
    'utils/default-map.php',
    'utils/to-key-path.php',
    'utils/brace-expansion.php',
    'utils/compare.php',
    'utils/compare-breakpoints.php',
    'utils/dimensions.php',
    'utils/topological-sort.php',
    'utils/replace-shadow-colors.php',
    'utils/is-color.php',
    'utils/math-operators.php',
    'utils/is-valid-arbitrary.php',
    'utils/infer-data-type.php',
    'ast.php',
    'css-parser.php',
    'walk.php',
    'value-parser.php',
    'selector-parser.php',
    'constant-fold-declaration.php',
    'property-order.php',
    'expand-declaration.php',
    'theme.php',
    'utils/decode-arbitrary-value.php',
    'candidate.php',
    'compile.php',
    'utilities.php',
    'variants.php',
    'design-system.php',
    'apply.php',
    'at-import.php',
    'css-functions.php',
    'index.php',
    'TailwindCompiler.php',
    'Tailwind.php',
    'classnames.php',
];

foreach ($files as $file) {
    require_once $lib . '/' . $file;
}
