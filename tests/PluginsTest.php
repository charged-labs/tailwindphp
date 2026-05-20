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

    public function test_per_compile_plugin_di(): void
    {
        $plugin = new TestStubPlugin();

        $css = tw::generate([
            'content' => '<div class="test-stub"></div>',
            'css'     => '@import "tailwindcss"; @plugin "test/stub";',
            'plugins' => [$plugin],
        ]);

        $this->assertStringContainsString('.test-stub', $css);
        $this->assertStringContainsString('color', $css);
    }

    public function test_plugins_do_not_leak_between_compilations(): void
    {
        // First compile registers the plugin via DI.
        tw::generate([
            'content' => '<div class="test-stub"></div>',
            'css'     => '@import "tailwindcss"; @plugin "test/stub";',
            'plugins' => [new TestStubPlugin()],
        ]);

        // Second compile, no plugins passed, references the same plugin
        // name. It should fail because the previous DI registration must
        // not have leaked across calls.
        $this->expectException(\TailwindPHP\Exception\UnknownPluginException::class);
        tw::generate([
            'content' => '<div></div>',
            'css'     => '@import "tailwindcss"; @plugin "test/stub";',
        ]);
    }

    public function test_deprecated_singleton_still_works(): void
    {
        // Back-compat: the deprecated process-wide registerPlugin() path
        // continues to function for consumers that haven't migrated to DI.
        \TailwindPHP\registerPlugin(new TestStubPluginBackcompat());

        try {
            $css = tw::generate([
                'content' => '<div class="test-backcompat"></div>',
                'css'     => '@import "tailwindcss"; @plugin "test/backcompat";',
            ]);
            $this->assertStringContainsString('.test-backcompat', $css);
        } finally {
            // Don't leak into other tests in this run.
            \TailwindPHP\getPluginManager()->reset();
        }
    }
}

/**
 * Minimal plugin for per-compile DI tests.
 */
final class TestStubPlugin implements \TailwindPHP\Plugin\PluginInterface
{
    public function getName(): string
    {
        return 'test/stub';
    }

    public function __invoke(\TailwindPHP\Plugin\PluginAPI $api, array $options = []): void
    {
        $api->addUtilities(['.test-stub' => ['color' => 'red']]);
    }

    public function getThemeExtensions(array $options = []): array
    {
        return [];
    }
}

final class TestStubPluginBackcompat implements \TailwindPHP\Plugin\PluginInterface
{
    public function getName(): string
    {
        return 'test/backcompat';
    }

    public function __invoke(\TailwindPHP\Plugin\PluginAPI $api, array $options = []): void
    {
        $api->addUtilities(['.test-backcompat' => ['color' => 'blue']]);
    }

    public function getThemeExtensions(array $options = []): array
    {
        return [];
    }
}
