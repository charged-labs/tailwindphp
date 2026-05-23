<?php

declare(strict_types=1);

namespace TailwindPHP\Exception;

/**
 * Base class for all TailwindPHP runtime errors.
 *
 * Catching `\TailwindPHP\Exception\TailwindException` lets consumers
 * handle compiler failures (bad user CSS, missing plugin, cycle in
 * `@apply`) without also swallowing every other RuntimeException in
 * the request — useful when you want to log + fall back to a cached
 * stylesheet instead of returning a 500.
 */
abstract class TailwindException extends \RuntimeException
{
}
