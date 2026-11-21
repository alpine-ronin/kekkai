<?php

declare(strict_types=1);

namespace Kekkai\Check;

final readonly class CheckResult
{
    public const TAIL_LINES = 30;

    /**
     * @param string $name
     * @param string $command
     * @param bool $passed
     * @param string $output
     * @param bool $timedOut
     * @param int $retry how many more times this check's failure sends the agent back after the first
     */
    public function __construct(
        public string $name,
        public string $command,
        public bool $passed,
        public string $output,
        public bool $timedOut = false,
        public int $retry = 0,
    ) {
    }

    public function tail(): string
    {
        $lines = preg_split('/\R/', rtrim($this->output)) ?: [];
        if (\count($lines) <= self::TAIL_LINES) {
            return implode("\n", $lines);
        }

        return sprintf("[... %d lines cut]\n%s", \count($lines) - self::TAIL_LINES, implode("\n", \array_slice($lines, -self::TAIL_LINES)));
    }
}
