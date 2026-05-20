<?php

declare(strict_types=1);

namespace TailwindPHP\Exception;

/**
 * Raised when user-supplied CSS violates a TailwindPHP directive contract.
 *
 * Examples: nested `@source`, missing quotes around an `@source` path,
 * empty `@utility`, `@apply` inside `@keyframes`, unbalanced
 * brace-expansion pattern. The message names the offending directive.
 */
class InvalidCssException extends TailwindException
{
}
