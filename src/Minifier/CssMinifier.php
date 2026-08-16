<?php

declare(strict_types=1);

namespace TailwindPHP\Minifier;

/**
 * CSS Minifier - Reduces CSS file size while preserving functionality.
 *
 * Optimizations:
 * - Remove "quiet" comments (preserves /*! ... *\/ loud comments)
 * - Collapse unnecessary whitespace
 * - Shorten hex colors (#ffffff → #fff)
 * - Strip units from zero values (0px → 0)
 * - Shorten font-weight keywords (normal → 400, bold → 700)
 * - Remove empty rules
 *
 * String-aware: transformations never touch the contents of `"..."`, `'...'`,
 * or `url(...)` (unquoted form) — substitutions inside `content: "0px"` or
 * `url(./foo.png)` would corrupt the rule.
 *
 * Structure-aware: transformations that mean different things in a selector
 * and in a declaration value — zero-unit stripping, and squeezing whitespace
 * around `+` — are applied per context, not blindly.
 * See {@see self::applyStructuralTransforms()}.
 *
 * Does NOT:
 * - Merge duplicate selectors (makes debugging harder)
 * - Combine shorthand properties (can affect cascade)
 */
final class CssMinifier
{
    /**
     * Length units whose zero value can be written unitless.
     *
     * Deliberately excludes time units (`0s`, `0ms`) and `%`.
     */
    private const ZERO_UNITS = 'px|rem|em|ex|ch|vw|vh|vmin|vmax|cm|mm|in|pt|pc';

    /**
     * CSS math functions, where a unitless zero is NOT interchangeable with
     * a zero length.
     *
     * Outside these, `0` is a valid `<length>` and dropping the unit is safe.
     * Inside them, calc's type system reads a bare `0` as `<number>`, so
     * `calc(0px * 2)` → `calc(0 * 2)` turns a length into a number and the
     * whole declaration is discarded by the browser as invalid.
     *
     * @var list<string>
     */
    private const MATH_FUNCTIONS = [
        'calc', '-webkit-calc', '-moz-calc',
        'min', 'max', 'clamp', 'round', 'mod', 'rem',
        'abs', 'sign', 'hypot', 'pow', 'sqrt', 'log', 'exp',
        'sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'atan2',
    ];

    /**
     * Minify a CSS string.
     */
    public static function minify(string $css): string
    {
        // 1. Tokenize, dropping quiet comments and preserving every other
        //    protected region (strings, url(), loud comments) verbatim.
        $segments = self::tokenize($css);

        // 2. Apply segment-local transforms to the unprotected ("open")
        //    segments. These are safe to run in isolation: none of them need
        //    to know where the segment sits in the document's structure.
        foreach ($segments as &$segment) {
            if ($segment['protected']) {
                continue;
            }
            $segment['text'] = self::collapseWhitespace($segment['text']);
            $segment['text'] = self::shortenHexColors($segment['text']);
            $segment['text'] = self::shortenFontWeight($segment['text']);
        }
        unset($segment);

        // 3. Zero-unit stripping is NOT segment-local — it must see the whole
        //    document. Whether a given `0px` sits in a selector or in a
        //    declaration value depends on structural delimiters that can live
        //    in a different segment (`.a{background:url(./x.png) 0px 0px}`
        //    splits the declaration in two around the protected url()).
        $segments = self::applyStructuralTransforms($segments);

        // 4. Reassemble.
        $css = '';
        foreach ($segments as $segment) {
            $css .= $segment['text'];
        }

        // 5. Whole-string passes that are structurally safe (operate only on
        //    brace/semicolon/selector syntax, which can't appear in protected
        //    regions): drop the trailing `;` before `}`, drop empty rules.
        $css = str_replace(';}', '}', $css);
        $css = self::removeEmptyRules($css);

        return trim($css);
    }

    /**
     * Split CSS into segments, marking each as protected (verbatim) or open
     * (subject to whitespace/value transforms). Quiet comments are dropped.
     *
     * Protected segments:
     *   - `"..."` and `'...'` string literals (backslash-escape aware)
     *   - `url(...)` with unquoted argument (a quoted argument is already
     *     protected by the surrounding string token)
     *   - `/*! ... *\/` loud comments
     *
     * @return array<int, array{protected: bool, text: string}>
     */
    private static function tokenize(string $css): array
    {
        $segments = [];
        $open = '';
        $len = strlen($css);
        $i = 0;

        // Inline helper: PHPStan can't model by-reference closure captures
        // well enough to see that $open does in fact get appended to in the
        // outer loop, so we inline the flush as a static closure that
        // returns the new ($segments, $open) state by reference.
        $flushOpen = static function (array &$segments, string &$open): void {
            if (strlen($open) > 0) {
                $segments[] = ['protected' => false, 'text' => $open];
                $open = '';
            }
        };

        while ($i < $len) {
            $ch = $css[$i];

            // Comment
            if ($ch === '/' && $i + 1 < $len && $css[$i + 1] === '*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    // Unterminated — keep as-is rather than mangling.
                    $open .= substr($css, $i);
                    $i = $len;
                    break;
                }
                $comment = substr($css, $i, $end - $i + 2);
                $i = $end + 2;

                // Loud comments (`/*! ... */`) are preserved verbatim.
                if (isset($comment[2]) && $comment[2] === '!') {
                    $flushOpen($segments, $open);
                    $segments[] = ['protected' => true, 'text' => $comment];
                }
                // Quiet comments are dropped.
                continue;
            }

            // Quoted string
            if ($ch === '"' || $ch === "'") {
                $flushOpen($segments, $open);
                $quote = $ch;
                $start = $i;
                $i++;
                while ($i < $len) {
                    $c = $css[$i];
                    if ($c === '\\' && $i + 1 < $len) {
                        $i += 2;
                        continue;
                    }
                    $i++;
                    if ($c === $quote) {
                        break;
                    }
                }
                $segments[] = ['protected' => true, 'text' => substr($css, $start, $i - $start)];
                continue;
            }

            // url(...) with unquoted argument
            if (($ch === 'u' || $ch === 'U') && substr_compare($css, 'url(', $i, 4, true) === 0) {
                $afterParen = $i + 4;
                // Skip any whitespace after the opening paren before deciding
                // whether the argument is quoted.
                $j = $afterParen;
                while ($j < $len && ctype_space($css[$j])) {
                    $j++;
                }
                if ($j < $len && ($css[$j] === '"' || $css[$j] === "'")) {
                    // Quoted url() — the inner string will be handled by the
                    // string branch on the next iteration. Emit the `url(` and
                    // any whitespace as open text so it can be normalized.
                    $open .= substr($css, $i, $j - $i);
                    $i = $j;
                    continue;
                }
                $end = strpos($css, ')', $afterParen);
                if ($end === false) {
                    $open .= substr($css, $i);
                    $i = $len;
                    break;
                }
                $flushOpen($segments, $open);
                $segments[] = ['protected' => true, 'text' => substr($css, $i, $end - $i + 1)];
                $i = $end + 1;
                continue;
            }

            $open .= $ch;
            $i++;
        }

        $flushOpen($segments, $open);

        return $segments;
    }

    /**
     * Collapse runs of whitespace and trim around structural punctuation.
     * Operates only on unprotected text — string contents are preserved
     * verbatim by the tokenizer.
     */
    private static function collapseWhitespace(string $css): string
    {
        $css = preg_replace('/\s+/', ' ', $css);
        // `+` is deliberately absent: it is a selector combinator but also
        // calc's addition operator, where the surrounding whitespace is
        // required (`calc(100%+1px)` is invalid). Squeezing it is left to
        // the structure-aware pass, which knows a selector from a value.
        $css = preg_replace('/\s*([{};,>~])\s*/', '$1', $css);
        // Only strip space AFTER `:` — stripping before would break
        // `.prose :where(p)` by gluing it to `.prose:where(p)`.
        $css = preg_replace('/:\s+/', ':', $css);
        $css = preg_replace('/\(\s+/', '(', $css);
        $css = preg_replace('/\s+\)/', ')', $css);

        return $css;
    }

    /**
     * #ffffff → #fff, #aabbcc → #abc.
     */
    private static function shortenHexColors(string $css): string
    {
        return preg_replace_callback(
            '/#([0-9a-fA-F])\1([0-9a-fA-F])\2([0-9a-fA-F])\3\b/',
            fn ($m) => '#' . strtolower($m[1] . $m[2] . $m[3]),
            $css,
        );
    }

    /**
     * The transforms that depend on where in the document the text sits.
     *
     * Two jobs, both needing to tell a selector from a declaration value:
     *
     * 1. Squeeze whitespace around `+` in preludes only, where it is the
     *    adjacent-sibling combinator rather than calc's `+` operator.
     * 2. Strip zero units (0px → 0, 0rem → 0) in declaration values only.
     *
     * Zero-unit stripping preserves 0s/0ms (time units required for
     * animation values) and 0% (gradient stops), and skips two contexts
     * where the substitution is not merely cosmetic:
     *
     * - **Selectors and at-rule preludes.** A selector is an identifier, not
     *   a value. Tailwind emits arbitrary values into class names, so
     *   `.md\:divide-y-\[0px\]` would be rewritten to `.md\:divide-y-\[0\]`
     *   and stop matching the `md:divide-y-[0px]` class in the markup.
     * - **Math functions.** `calc(0px * 2)` → `calc(0 * 2)` changes the
     *   expression's type from `<length>` to `<number>`, and the browser
     *   drops the declaration. See {@see self::MATH_FUNCTIONS}.
     *
     * Operates on the segment list rather than a single string so the
     * prelude/declaration state survives across protected regions.
     *
     * @param array<int, array{protected: bool, text: string}> $segments
     *
     * @return array<int, array{protected: bool, text: string}>
     */
    private static function applyStructuralTransforms(array $segments): array
    {
        // Splice the open text into one string, standing each protected
        // segment in for a NUL. NUL cannot appear in valid CSS (the spec
        // requires tokenizers to replace it with U+FFFD), so it round-trips
        // as an unambiguous marker — but bail out rather than corrupt the
        // document if the input contains one anyway.
        $scan = '';
        $protectedTexts = [];
        foreach ($segments as $segment) {
            if ($segment['protected']) {
                $protectedTexts[] = $segment['text'];
                $scan .= "\0";
                continue;
            }
            if (str_contains($segment['text'], "\0")) {
                return $segments;
            }
            $scan .= $segment['text'];
        }

        $parts = explode("\0", self::transformStatements($scan));

        // A transform that lost or gained a marker would desynchronize the
        // interleave below; leave the document untouched if that happens.
        if (count($parts) !== count($protectedTexts) + 1) {
            return $segments;
        }

        $result = [];
        foreach ($parts as $i => $part) {
            if ($part !== '') {
                $result[] = ['protected' => false, 'text' => $part];
            }
            if (isset($protectedTexts[$i])) {
                $result[] = ['protected' => true, 'text' => $protectedTexts[$i]];
            }
        }

        return $result;
    }

    /**
     * Walk statements, rewriting zero lengths in declaration values only.
     *
     * A statement runs to the next `{`, `;` or `}`. Ending at `{` makes it a
     * prelude (selector or at-rule), which is emitted verbatim; otherwise it
     * is a declaration, and everything after its first `:` is a value.
     */
    private static function transformStatements(string $css): string
    {
        $out = '';
        $len = strlen($css);
        $i = 0;

        while ($i < $len) {
            $next = $i + strcspn($css, '{};', $i);

            if ($next >= $len) {
                // Trailing text with no delimiter — an incomplete statement.
                $out .= substr($css, $i);
                break;
            }

            $statement = substr($css, $i, $next - $i);

            if ($css[$next] === '{') {
                // Prelude: `+` here is the adjacent-sibling combinator, so
                // the whitespace around it is noise rather than syntax.
                $out .= preg_replace('/\s*\+\s*/', '+', $statement) . '{';
            } else {
                $colon = strpos($statement, ':');
                $out .= $colon === false
                    ? $statement
                    : substr($statement, 0, $colon + 1)
                        . self::stripZeroUnitsOutsideMath(substr($statement, $colon + 1));
                $out .= $css[$next];
            }

            $i = $next + 1;
        }

        return $out;
    }

    /**
     * Rewrite zero lengths in a declaration value, skipping math functions.
     *
     * Tracks paren depth so that once a math function is entered, everything
     * nested inside it is left alone too — `calc(1px + min(0px, 2px))` must
     * keep both units.
     */
    private static function stripZeroUnitsOutsideMath(string $value): string
    {
        $out = '';
        $len = strlen($value);
        $i = 0;
        $depth = 0;
        $mathDepth = 0;

        while ($i < $len) {
            $ch = $value[$i];

            if ($ch === '(') {
                $depth++;
                if ($mathDepth === 0 && self::endsWithMathFunction($out)) {
                    $mathDepth = $depth;
                }
                $out .= $ch;
                $i++;
                continue;
            }

            if ($ch === ')') {
                if ($mathDepth === $depth) {
                    $mathDepth = 0;
                }
                $depth--;
                $out .= $ch;
                $i++;
                continue;
            }

            if (
                $mathDepth === 0
                && $ch === '0'
                // Only a leading zero starts a length: don't touch the `0px`
                // tail of `10px` or of an identifier like `--x0px`.
                && ($i === 0 || preg_match('/[\w.#-]/', $value[$i - 1]) !== 1)
                && preg_match('/^0(?:' . self::ZERO_UNITS . ')\b/', substr($value, $i), $m) === 1
            ) {
                $out .= '0';
                $i += strlen($m[0]);
                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * Does the emitted text end in a math function name awaiting its `(`?
     */
    private static function endsWithMathFunction(string $emitted): bool
    {
        if (preg_match('/(-?[a-z][a-z0-9-]*)$/i', $emitted, $m) !== 1) {
            return false;
        }

        return in_array(strtolower($m[1]), self::MATH_FUNCTIONS, true);
    }

    /**
     * font-weight:normal → 400, font-weight:bold → 700.
     */
    private static function shortenFontWeight(string $css): string
    {
        $css = preg_replace('/font-weight:normal\b/', 'font-weight:400', $css);
        $css = preg_replace('/font-weight:bold\b/', 'font-weight:700', $css);

        return $css;
    }

    /**
     * Drop rules with empty declaration blocks: `.foo{}` → ``.
     *
     * Safe to run on the full string: braces never appear inside protected
     * regions (strings are tokenized; url() args don't contain `}`).
     */
    private static function removeEmptyRules(string $css): string
    {
        return preg_replace('/[^{}]+\{\s*\}/', '', $css);
    }
}
