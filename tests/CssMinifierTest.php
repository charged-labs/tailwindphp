<?php

declare(strict_types=1);

namespace Charged\TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;
use TailwindPHP\Minifier\CssMinifier;

/**
 * Regression tests for the CSS minifier — locks in the string-aware
 * behaviour so future "just one more regex" tweaks don't silently
 * corrupt url() values, content strings, or loud comments.
 */
final class CssMinifierTest extends TestCase
{
    public function test_strips_whitespace_between_declarations(): void
    {
        $out = CssMinifier::minify(".a {\n  color:  red;\n  padding: 0;\n}\n");
        $this->assertSame('.a{color:red;padding:0}', $out);
    }

    public function test_strips_quiet_comments(): void
    {
        $out = CssMinifier::minify(".a { /* hidden */ color: red; }");
        $this->assertSame('.a{color:red}', $out);
    }

    public function test_preserves_loud_comments(): void
    {
        // /*! ... */ is the CSS convention for "do not strip" — license
        // banners and structural markers must survive minification.
        $out = CssMinifier::minify("/*! keep me */ .a { color: red; }");
        $this->assertStringContainsString('/*! keep me */', $out);
        $this->assertStringContainsString('.a{color:red}', $out);
    }

    public function test_preserves_loud_comment_inside_var_fallback(): void
    {
        // Tailwind v4 emits `var(--tw-empty,/*!*/ /*!*/)` as a structural
        // marker — the minifier must keep it byte-exact.
        $css = '.a{background:var(--tw-empty,/*!*/ /*!*/) red}';
        $this->assertStringContainsString('/*!*/', CssMinifier::minify($css));
    }

    public function test_does_not_collapse_whitespace_inside_strings(): void
    {
        $out = CssMinifier::minify('.a::before { content: "hello   world"; }');
        $this->assertStringContainsString('content:"hello   world"', $out);
    }

    public function test_does_not_strip_zero_units_inside_content_string(): void
    {
        $out = CssMinifier::minify('.a::before { content: "0px"; }');
        $this->assertStringContainsString('"0px"', $out);
    }

    public function test_does_not_strip_zero_units_inside_url(): void
    {
        $out = CssMinifier::minify('.a { background: url(./0px.png); }');
        $this->assertStringContainsString('url(./0px.png)', $out);
    }

    public function test_does_not_shorten_hex_inside_string(): void
    {
        $out = CssMinifier::minify('.a::before { content: "#ffffff"; }');
        $this->assertStringContainsString('"#ffffff"', $out);
    }

    public function test_shortens_hex_in_declarations(): void
    {
        $out = CssMinifier::minify('.a { color: #ffffff; }');
        $this->assertStringContainsString('color:#fff', $out);
    }

    public function test_strips_zero_units(): void
    {
        $out = CssMinifier::minify('.a { margin: 0px; padding: 0rem; }');
        $this->assertStringContainsString('margin:0', $out);
        $this->assertStringContainsString('padding:0', $out);
    }

    public function test_strips_zero_units_across_a_protected_region(): void
    {
        // The url() splits the declaration into two open segments; the
        // zero-unit pass has to carry its "inside a value" state across it.
        $out = CssMinifier::minify('.a { background: url(./x.png) 0px 0px; }');
        $this->assertStringContainsString('url(./x.png) 0 0', $out);
    }

    public function test_does_not_strip_zero_units_in_selectors(): void
    {
        // Tailwind emits arbitrary values into class names. Rewriting the
        // selector to `.md\:divide-y-\[0\]` would stop it matching the
        // `md:divide-y-[0px]` class in the markup.
        $out = CssMinifier::minify('.md\\:divide-y-\\[0px\\] { color: red; }');
        $this->assertStringContainsString('.md\\:divide-y-\\[0px\\]', $out);
    }

    public function test_does_not_strip_zero_units_in_at_rule_preludes(): void
    {
        $out = CssMinifier::minify('@media (min-width: 0px) { .a { color: red; } }');
        $this->assertStringContainsString('@media (min-width:0px)', $out);
    }

    public function test_does_not_strip_zero_units_inside_calc(): void
    {
        // `calc(0 * 2)` is a <number>, not a <length>, so the browser drops
        // the declaration outright.
        $out = CssMinifier::minify('.a { border-top-width: calc(0px * 2); }');
        $this->assertStringContainsString('calc(0px * 2)', $out);
    }

    public function test_does_not_strip_zero_units_nested_inside_calc(): void
    {
        $out = CssMinifier::minify('.a { width: calc(1px + min(0px, 2px)); }');
        $this->assertStringContainsString('min(0px,2px)', $out);
    }

    public function test_preserves_whitespace_around_calc_plus_operator(): void
    {
        // calc's `+` requires surrounding whitespace — `calc(100%+1px)` is
        // rejected outright by the browser.
        $out = CssMinifier::minify('.a { width: calc(100% + 1px); }');
        $this->assertStringContainsString('calc(100% + 1px)', $out);
    }

    public function test_squeezes_adjacent_sibling_combinator(): void
    {
        // In a selector the same `+` is a combinator, and the whitespace
        // around it is safe to drop.
        $out = CssMinifier::minify('.a + .b { color: red; }');
        $this->assertStringContainsString('.a+.b{', $out);
    }

    public function test_strips_zero_units_outside_a_non_math_function(): void
    {
        // `translate()` is not a math function — a bare 0 is a valid <length>
        // there, so the unit still goes.
        $out = CssMinifier::minify('.a { transform: translate(0px, 0px); }');
        $this->assertStringContainsString('translate(0,0)', $out);
    }

    public function test_preserves_zero_length_tail_of_a_larger_number(): void
    {
        $out = CssMinifier::minify('.a { margin: 10px; }');
        $this->assertStringContainsString('margin:10px', $out);
    }

    public function test_preserves_zero_seconds_for_animations(): void
    {
        // `transition: width 0s` is legal and meaningful — stripping the
        // unit to `0` is also legal CSS-wide, but the regex was designed
        // not to touch time units.
        $out = CssMinifier::minify('.a { transition: width 0s; }');
        $this->assertStringContainsString('0s', $out);
    }

    public function test_shortens_font_weight_keywords(): void
    {
        $this->assertStringContainsString(
            'font-weight:400',
            CssMinifier::minify('.a { font-weight: normal; }'),
        );
        $this->assertStringContainsString(
            'font-weight:700',
            CssMinifier::minify('.a { font-weight: bold; }'),
        );
    }

    public function test_removes_empty_rules(): void
    {
        $out = CssMinifier::minify('.a {} .b { color: red; }');
        $this->assertStringNotContainsString('.a{', $out);
        $this->assertStringContainsString('.b{color:red}', $out);
    }

    public function test_preserves_descendant_pseudo_class_selector(): void
    {
        // Stripping the space inside `.prose :where(p)` would change the
        // selector's meaning (descendant → compound).
        $out = CssMinifier::minify('.prose :where(p) { color: red; }');
        $this->assertStringContainsString('.prose :where(p)', $out);
    }

    public function test_unterminated_comment_is_left_alone(): void
    {
        // Defensive: rather than swallow the rest of the document, an
        // unterminated `/*` is emitted as-is.
        $out = CssMinifier::minify(".a { color: red; } /* unterminated");
        $this->assertStringContainsString('/* unterminated', $out);
    }
}
