<?php

/**
 * Class name helpers — PHP ports of the popular JS companion libraries:
 *
 * - clsx / classnames (conditional class construction)
 * - tailwind-merge   (conflict resolution between Tailwind utilities)
 * - cva              (variant-driven component class composition)
 *
 * Implementation lives under src/_tailwindphp/lib/; this file exposes the
 * thin `cn() / merge() / join() / variants() / compose()` wrappers in the
 * top-level `TailwindPHP` namespace.
 */

declare(strict_types=1);

namespace TailwindPHP;

require_once __DIR__ . '/_tailwindphp/lib/clsx/clsx.php';
require_once __DIR__ . '/_tailwindphp/lib/tailwind-merge/index.php';
require_once __DIR__ . '/_tailwindphp/lib/cva/cva.php';

/**
 * Conditional classes + Tailwind conflict resolution. The recommended way
 * to compose class strings in PHP templates.
 *
 * ```php
 * cn('px-2 py-1', 'px-4');                       // 'py-1 px-4'
 * cn('text-red-500', ['text-blue-500' => true]); // 'text-blue-500'
 * cn('hidden', ['block' => $isVisible]);         // 'block' (if true)
 * ```
 */
function cn(mixed ...$inputs): string
{
    return \TailwindPHP\Lib\TailwindMerge\cn(...$inputs);
}

/**
 * Merge Tailwind classes, resolving conflicts. Later wins.
 *
 * ```php
 * merge('px-2 py-1', 'px-4');                      // 'py-1 px-4'
 * merge('hover:bg-red-500', 'hover:bg-blue-500');  // 'hover:bg-blue-500'
 * ```
 */
function merge(mixed ...$args): string
{
    return \TailwindPHP\Lib\TailwindMerge\twMerge(...$args);
}

/**
 * Join classes without conflict resolution. Faster than `cn()`/`merge()`
 * when you know the inputs don't conflict.
 */
function join(mixed ...$args): string
{
    return \TailwindPHP\Lib\TailwindMerge\twJoin(...$args);
}

/**
 * Create component style variants — PHP port of CVA
 * (https://github.com/joe-bell/cva).
 *
 * ```php
 * $button = variants([
 *     'base' => 'btn font-semibold',
 *     'variants' => [
 *         'intent' => [
 *             'primary'   => 'bg-blue-500 text-white',
 *             'secondary' => 'bg-gray-200 text-gray-800',
 *         ],
 *         'size' => [
 *             'sm' => 'text-sm px-2 py-1',
 *             'md' => 'text-base px-4 py-2',
 *         ],
 *     ],
 *     'defaultVariants' => ['intent' => 'primary', 'size' => 'md'],
 * ]);
 *
 * $button();                                    // defaults
 * $button(['intent' => 'secondary']);           // override
 * $button(['size' => 'sm', 'class' => 'mt-4']); // override + extension
 * ```
 */
function variants(?array $config = null): callable
{
    return \TailwindPHP\Lib\Cva\cva($config);
}

/**
 * Compose multiple variant components into one (CVA `compose()` port).
 *
 * ```php
 * $box   = variants(['variants' => ['shadow' => ['sm' => 'shadow-sm', 'md' => 'shadow-md']]]);
 * $stack = variants(['variants' => ['gap'    => ['1'  => 'gap-1',     '2'  => 'gap-2']]]);
 * $card  = compose($box, $stack);
 *
 * $card(['shadow' => 'md', 'gap' => '2']); // 'shadow-md gap-2'
 * ```
 */
function compose(callable ...$components): callable
{
    return \TailwindPHP\Lib\Cva\compose(...$components);
}
