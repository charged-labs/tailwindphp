<?php

declare(strict_types=1);

namespace TailwindPHP\Exception;

/**
 * Raised when `@apply` directives form a cycle.
 *
 * The message contains the resolved dependency chain (e.g. `a → b → a`)
 * to make the offending utility names easy to find.
 */
class CircularDependencyException extends InvalidCssException
{
}
