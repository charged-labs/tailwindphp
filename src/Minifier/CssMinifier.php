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
 * Does NOT:
 * - Merge duplicate selectors (makes debugging harder)
 * - Combine shorthand properties (can affect cascade)
 */
final class CssMinifier
{
    /**
     * Minify a CSS string.
     */
    public static function minify(string $css): string
    {
        // 1. Tokenize, dropping quiet comments and preserving every other
        //    protected region (strings, url(), loud comments) verbatim.
        $segments = self::tokenize($css);

        // 2. Apply value-level transforms to the unprotected ("open") segments.
        foreach ($segments as &$segment) {
            if ($segment['protected']) {
                continue;
            }
            $segment['text'] = self::collapseWhitespace($segment['text']);
            $segment['text'] = self::shortenHexColors($segment['text']);
            $segment['text'] = self::removeZeroUnits($segment['text']);
            $segment['text'] = self::shortenFontWeight($segment['text']);
        }
        unset($segment);

        // 3. Reassemble.
        $css = '';
        foreach ($segments as $segment) {
            $css .= $segment['text'];
        }

        // 4. Whole-string passes that are structurally safe (operate only on
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
        $css = preg_replace('/\s*([{};,>~+])\s*/', '$1', $css);
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
     * 0px → 0, 0rem → 0, 0em → 0. Preserves 0s/0ms (time units required
     * for animation values) and 0% (gradient stops).
     */
    private static function removeZeroUnits(string $css): string
    {
        return preg_replace(
            '/\b0(px|rem|em|ex|ch|vw|vh|vmin|vmax|cm|mm|in|pt|pc)\b/',
            '0',
            $css,
        );
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
