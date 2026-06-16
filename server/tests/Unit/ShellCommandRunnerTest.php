<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ShellCommandRunner;
use PHPUnit\Framework\TestCase;

final class ShellCommandRunnerTest extends TestCase
{
    public function test_run_uses_injected_executor(): void
    {
        $runner = new ShellCommandRunner(static fn (string $command): array => [
            'output' => [$command],
            'code' => 0,
        ]);

        $result = $runner->run('echo test');

        $this->assertSame(['echo test'], $result['output']);
        $this->assertSame(0, $result['code']);
    }

    public function test_run_uses_exec_by_default(): void
    {
        $runner = new ShellCommandRunner;
        $result = $runner->run('echo mojito-test');

        $this->assertSame(0, $result['code']);
        $this->assertNotEmpty($result['output']);
    }
}
