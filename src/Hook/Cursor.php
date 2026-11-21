<?php

declare(strict_types=1);

namespace Kekkai\Hook;

use Kekkai\Verdict;

final class Cursor
{
    /** Cursor sends every file creation and change as Write, with an absolute file_path. */
    public const EDIT_TOOLS = ['Write' => 'file_path'];

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>|null
     */
    public static function preToolUse(array $input, ?string $root = null): ?array
    {
        $tool = \is_string($input['tool_name'] ?? null) ? $input['tool_name'] : '';
        $key = self::EDIT_TOOLS[$tool] ?? null;
        $toolInput = \is_array($input['tool_input'] ?? null) ? $input['tool_input'] : [];
        $path = $key === null ? null : ($toolInput[$key] ?? null);
        if (!\is_string($path) || $path === '') {
            return null;
        }

        $decision = Guard::edit($path, $root ?? self::root($input), $tool);
        if ($decision === null) {
            return null;
        }

        // Cursor does not enforce "ask" on preToolUse: refusing, with the agent told to ask, is the safe reading.
        $reason = 'Kekkai: ' . $decision->reason;
        if ($decision->verdict === Verdict::Ask) {
            $reason .= "\nThis edit needs the user's approval: stop and ask them.";
        }

        return ['permission' => 'deny', 'agent_message' => $reason, 'user_message' => $reason];
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>|null
     */
    public static function stop(array $input, ?string $root = null, bool $runChecks = true): ?array
    {
        $loops = $input['loop_count'] ?? 0;
        $status = $input['status'] ?? 'completed';
        if (!\is_int($loops) || $loops < 0 || $status !== 'completed') {
            return null;
        }

        $report = Guard::review($root ?? self::root($input), $runChecks, $loops);

        return $report === null ? null : ['followup_message' => $report];
    }

    /**
     * @param array<mixed> $input
     */
    private static function root(array $input): ?string
    {
        $env = getenv('CURSOR_PROJECT_DIR');
        if (\is_string($env) && $env !== '') {
            return $env;
        }

        $roots = $input['workspace_roots'] ?? null;
        if (\is_array($roots) && \is_string($roots[0] ?? null) && $roots[0] !== '') {
            return $roots[0];
        }

        return \is_string($input['cwd'] ?? null) ? $input['cwd'] : null;
    }
}
