<?php

declare(strict_types=1);

namespace ChargedLabs\TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;
use TailwindPHP\tw;

/**
 * Smoke coverage for the bundled @plugin directives — confirms the
 * forms and typography plugins resolve, register, and emit recognisable
 * rules into the compiled CSS. Catches regressions in plugin wiring
 * without trying to replicate the upstream plugin test suites.
 */
final class PluginsTest extends TestCase
{
    public function test_forms_plugin_emits_base_input_styles(): void
    {
        $css = tw::generate([
            'content' => '<input type="text"><textarea></textarea>',
            'css'     => '@import "tailwindcss"; @plugin "@tailwindcss/forms";',
        ]);

        // The @tailwindcss/forms preflight resets text-style inputs.
        $this->assertMatchesRegularExpression(
            '/input(\[[^\]]+\])?|textarea/',
            $css,
            'forms plugin should target form controls in its base layer',
        );
        $this->assertStringContainsString('appearance', $css);
    }

    public function test_typography_plugin_emits_prose_class(): void
    {
        $css = tw::generate([
            'content' => '<article class="prose"><p>hello</p></article>',
            'css'     => '@import "tailwindcss"; @plugin "@tailwindcss/typography";',
        ]);

        $this->assertStringContainsString('.prose', $css);
    }

    public function test_unregistered_plugin_throws_typed_exception(): void
    {
        $this->expectException(\TailwindPHP\Exception\UnknownPluginException::class);
        $this->expectExceptionMessageMatches('/Plugin .* is not registered/i');

        tw::generate([
            'content' => '<div></div>',
            'css'     => '@import "tailwindcss"; @plugin "@nonexistent/plugin";',
        ]);
    }

    public function test_unknown_plugin_is_catchable_as_tailwind_exception(): void
    {
        try {
            tw::generate([
                'content' => '<div></div>',
                'css'     => '@import "tailwindcss"; @plugin "@nonexistent/plugin";',
            ]);
            $this->fail('Expected UnknownPluginException');
        } catch (\TailwindPHP\Exception\TailwindException $e) {
            $this->assertInstanceOf(\TailwindPHP\Exception\InvalidCssException::class, $e);
        }
    }
}
