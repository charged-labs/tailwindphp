<?php

declare(strict_types=1);

namespace ChargedLabs\TailwindPHP\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Black-box smoke tests for bin/tw. Invokes the script as a subprocess
 * (using PHP_BINARY for cross-platform safety) and asserts on stdout
 * / stderr / exit code.
 *
 * We don't try to test every flag combination — the CLI is a thin
 * wrapper over tw::generate, which is exercised exhaustively elsewhere.
 * What we check here is the integration: stdin/stdout plumbing, error
 * exit codes, that the script is actually executable.
 */
final class CliTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../bin/tw';

    public function test_help_flag_prints_usage_and_exits_zero(): void
    {
        $result = $this->execTw(['--help']);
        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Usage:', $result['stdout']);
        $this->assertStringContainsString('--minify', $result['stdout']);
    }

    public function test_version_flag_exits_zero(): void
    {
        $result = $this->execTw(['--version']);
        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('charged-labs/tailwindphp', $result['stdout']);
    }

    public function test_reads_html_from_stdin_writes_css_to_stdout(): void
    {
        $result = $this->execTw(['--content=-', '--minify'], '<div class="bg-red-500 p-4"></div>');

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('.bg-red-500', $result['stdout']);
        $this->assertStringContainsString('.p-4', $result['stdout']);
    }

    public function test_missing_content_file_errors_with_exit_one(): void
    {
        $result = $this->execTw(['--content=/nonexistent/path.html']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('not found', $result['stderr']);
    }

    public function test_invalid_css_returns_exit_two(): void
    {
        // @plugin pointing at an unregistered plugin -> UnknownPluginException
        // -> exit 2 per the documented exit-code contract.
        $tmp = tempnam(sys_get_temp_dir(), 'twcli');
        file_put_contents($tmp, '@import "tailwindcss"; @plugin "@nope/does-not-exist";');

        try {
            $result = $this->execTw(['--content=-', '--css=' . $tmp], '<div></div>');
            $this->assertSame(2, $result['code']);
            $this->assertStringContainsString('UnknownPluginException', $result['stderr']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_writes_to_out_file_when_requested(): void
    {
        $outFile = sys_get_temp_dir() . '/twcli-out-' . bin2hex(random_bytes(4)) . '.css';

        try {
            $result = $this->execTw(['--content=-', '--out=' . $outFile], '<div class="p-2"></div>');
            $this->assertSame(0, $result['code']);
            $this->assertSame('', $result['stdout']);
            $this->assertFileExists($outFile);
            $this->assertStringContainsString('.p-2', file_get_contents($outFile));
        } finally {
            @unlink($outFile);
        }
    }

    /**
     * @param array<string> $args
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function execTw(array $args, string $stdin = ''): array
    {
        $cmd = array_merge([PHP_BINARY, self::SCRIPT], $args);

        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            $this->fail('Failed to spawn bin/tw subprocess');
        }

        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($proc);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
