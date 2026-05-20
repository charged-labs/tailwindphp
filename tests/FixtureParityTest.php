<?php

declare(strict_types=1);

namespace ChargedLabs\TailwindPHP\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TailwindPHP\tw;

/**
 * Runs every fixture under tests/fixtures/ through the compiler and
 * compares the output to the committed expected.css.
 *
 * Each fixture's `expected.css` carries a `source=` header marking it as
 * either `php-seeded` (locked-in current PHP behaviour) or `upstream`
 * (matched against the JS Tailwind compiler via bin/regen-fixtures.sh).
 * See tests/fixtures/README.md.
 */
final class FixtureParityTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function fixtureProvider(): iterable
    {
        $root = __DIR__ . '/fixtures';
        if (!is_dir($root)) {
            return;
        }

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $root . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            if (!is_file($dir . '/input.html') || !is_file($dir . '/input.css')) {
                continue;
            }
            yield $entry => [$dir];
        }
    }

    #[DataProvider('fixtureProvider')]
    public function test_fixture_output_matches_expected(string $dir): void
    {
        $html = file_get_contents($dir . '/input.html');
        $css = file_get_contents($dir . '/input.css');

        $actual = tw::generate(['content' => $html, 'css' => $css]);

        $expectedPath = $dir . '/expected.css';
        if (!is_file($expectedPath)) {
            // First run for this fixture — emit the current PHP output as
            // a php-seeded baseline. The user reviews and commits it.
            $seeded = self::seededHeader() . trim($actual) . "\n";
            file_put_contents($expectedPath, $seeded);
            $this->markTestIncomplete(
                "expected.css was missing for fixture " . basename($dir)
                . "; wrote a php-seeded baseline. Review and commit, then re-run."
            );
        }

        $expected = $this->stripHeader(file_get_contents($expectedPath));
        $this->assertSame(
            trim($expected),
            trim($actual),
            "Output diverged from expected for fixture: " . basename($dir),
        );
    }

    public function test_every_fixture_declares_its_source(): void
    {
        $root = __DIR__ . '/fixtures';
        if (!is_dir($root)) {
            $this->markTestSkipped('no fixtures yet');
        }

        foreach (scandir($root) ?: [] as $entry) {
            $expectedPath = $root . '/' . $entry . '/expected.css';
            if (!is_file($expectedPath)) {
                continue;
            }

            $contents = file_get_contents($expectedPath);
            $this->assertMatchesRegularExpression(
                '/source=(php-seeded|upstream)/',
                $contents,
                "Fixture " . $entry . " is missing the `source=` header. See tests/fixtures/README.md.",
            );
        }
    }

    private function stripHeader(string $css): string
    {
        // Drop the `/* fixture: ... */` provenance comment if present.
        return preg_replace('#^/\*\s*fixture:.*?\*/\s*#s', '', $css, 1) ?? $css;
    }

    private static function seededHeader(): string
    {
        return "/* fixture: source=php-seeded — locks in current PHP behaviour. Run\n"
            . "   bin/regen-fixtures.sh against the upstream JS compiler to promote\n"
            . "   this to source=upstream. */\n";
    }
}
