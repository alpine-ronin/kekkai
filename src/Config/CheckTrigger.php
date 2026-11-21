<?php

declare(strict_types=1);

namespace Kekkai\Config;

/**
 * When a check of kekkai.yaml runs: at the agent's stop (the stop hook, the MCP tool `check_changes`) or in
 * `kekkai check`.
 */
enum CheckTrigger: string
{
    case Stop = 'stop';
    case Ci = 'ci';
}
