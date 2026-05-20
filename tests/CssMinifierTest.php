<?php

declare(strict_types=1);

namespace ChargedLabs\TailwindPHP\Tests;

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
