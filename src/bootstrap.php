<?php
/**
 * Canonical loader for the TailwindPHP procedural files.
 *
 * The library does not ship as PSR-4 namespaced classes; it is a port of an
 * upstream JavaScript codebase organised into procedural PHP files that must
 * be required in a specific order. Composer registers this file via the
 * `files` autoload entry, so calling `require 'vendor/autoload.php'` is
 * enough to make `TailwindPHP\tw::generate()` available.
 *
 * @package Charged\Tailwind
 */

declare(strict_types=1);

if ( ! defined( 'CHARGED_TAILWIND_LOADED' ) ) {
	define( 'CHARGED_TAILWIND_LOADED', true );

	$lib = __DIR__;
	$files = array(
		'_tailwindphp/LightningCss.php',
		'_tailwindphp/CssMinifier.php',
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
		'plugin.php',
		'plugin/plugins/typography-plugin.php',
		'plugin/plugins/forms-plugin.php',
		'index.php',
	);

	foreach ( $files as $file ) {
		require_once $lib . '/' . $file;
	}
}
