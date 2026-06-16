<?php

declare(strict_types=1);

namespace Mojito\Label;

use Closure;

final class ShellCommandRunner
{
    /**
     * @param  (Closure(string): array{output: list<string>, code: int})|null  $executor
     */
    public function __construct(
        private readonly ?Closure $executor = null,
    ) {}

    /**
     * @return array{output: list<string>, code: int}
     */
    public function run(string $command): array
    {
        if ($this->executor instanceof Closure) {
            return ($this->executor)($command);
        }

        $output = [];
        $code = 0;
        exec($command.' 2>&1', $output, $code);

        return [
            'output' => $output,
            'code' => $code,
        ];
    }
}
