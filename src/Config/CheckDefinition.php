<?php

declare(strict_types=1);

namespace Kekkai\Config;

final readonly class CheckDefinition
{
    /** A check that can never pass would keep the agent working this many extra rounds at most. */
    public const MAX_RETRY = 5;

    /**
     * @param string $name
     * @param string $run
     * @param int $timeout
     * @param int $retry how many more times a failure sends the agent back after the first
     * @param list<CheckTrigger> $on
     */
    public function __construct(
        public string $name,
        public string $run,
        public int $timeout = 300,
        public int $retry = 0,
        public array $on = [CheckTrigger::Stop, CheckTrigger::Ci],
    ) {
    }

    public function runsOn(CheckTrigger $trigger): bool
    {
        return \in_array($trigger, $this->on, true);
    }
}
