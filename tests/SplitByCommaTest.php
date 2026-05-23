<?php

declare(strict_types=1);

namespace Charged\TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;

use function TailwindPHP\splitByCommaRespectingParens;

/**
 * Locks in the quote- and escape-aware splitter so future "simpler" rewrites
 * don't reintroduce the old bug where commas inside CSS string literals or
 * escape sequences ended up splitting the value.
 */
final class SplitByCommaTest extends TestCase
{
    public function test_splits_simple_list(): void
    {
        $this->assertSame(['a', 'b', 'c'], splitByCommaRespectingParens('a, b, c'));
    }

    public function test_respects_parens(): void
    {
        $this->assertSame(
            ['rgb(255, 0, 0)', 'blue'],
            splitByCommaRespectingParens('rgb(255, 0, 0), blue'),
        );
    }

    public function test_respects_nested_parens(): void
    {
        $this->assertSame(
            ['calc(1 + (2, 3))', 'x'],
            splitByCommaRespectingParens('calc(1 + (2, 3)), x'),
        );
    }

    public function test_respects_double_quoted_strings(): void
    {
        $this->assertSame(
            ['"a,b"', 'c'],
            splitByCommaRespectingParens('"a,b", c'),
        );
    }

    public function test_respects_single_quoted_strings(): void
    {
        $this->assertSame(
            ["'a,b'", 'c'],
            splitByCommaRespectingParens("'a,b', c"),
        );
    }

    public function test_respects_escaped_quote_inside_string(): void
    {
        // "a\",b" is a single string containing an embedded quote, so the
        // comma inside it must not split the input.
        $this->assertSame(
            ['"a\\",b"', 'c'],
            splitByCommaRespectingParens('"a\\",b", c'),
        );
    }

    public function test_empty_string(): void
    {
        $this->assertSame([], splitByCommaRespectingParens(''));
    }

    public function test_no_separator(): void
    {
        $this->assertSame(['solo'], splitByCommaRespectingParens('solo'));
    }
}
