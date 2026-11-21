<?php

declare(strict_types=1);

namespace Kekkai\Hook;

use Kekkai\Config\CheckDefinition;

/**
 * The agents Kekkai has hooks for. Any other agent gets the MCP server and `kekkai check`.
 */
enum Agent: string
{
    case Claude = 'claude';
    case Cursor = 'cursor';

    public const PRE_TOOL_USE = 'pre-tool-use';
    public const STOP = 'stop';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $agent): string => $agent->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude Code',
            self::Cursor => 'Cursor',
        };
    }

    public function hooksFile(): string
    {
        return match ($this) {
            self::Claude => '.claude/settings.json',
            self::Cursor => '.cursor/hooks.json',
        };
    }

    public function mcpFile(): string
    {
        return match ($this) {
            self::Claude => '.mcp.json',
            self::Cursor => '.cursor/mcp.json',
        };
    }

    /**
     * How a hook command reaches a kekkai installed inside the project.
     */
    public function projectPrefix(): string
    {
        return match ($this) {
            self::Claude => '"$CLAUDE_PROJECT_DIR"/',
            self::Cursor => '',
        };
    }

    public function restartHint(): string
    {
        return match ($this) {
            self::Claude => 'Restart Claude Code in this project, then approve the kekkai MCP server.',
            self::Cursor => 'Trust the workspace in Cursor, then enable the kekkai MCP server in its settings.',
        };
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>|null
     */
    public function preToolUse(array $input, ?string $root): ?array
    {
        return match ($this) {
            self::Claude => ClaudeCode::preToolUse($input, $root),
            self::Cursor => Cursor::preToolUse($input, $root),
        };
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>|null
     */
    public function stop(array $input, ?string $root, bool $runChecks): ?array
    {
        return match ($this) {
            self::Claude => ClaudeCode::stop($input, $root, $runChecks),
            self::Cursor => Cursor::stop($input, $root, $runChecks),
        };
    }

    /**
     * The hooks to merge into hooksFile().
     *
     * @return array<string, mixed>
     */
    public function hooks(string $binary): array
    {
        $preToolUse = $binary . ' hook ' . self::PRE_TOOL_USE . $this->agentOption();
        $stop = $binary . ' hook ' . self::STOP . $this->agentOption();

        return match ($this) {
            self::Claude => ['hooks' => [
                'PreToolUse' => [['matcher' => implode('|', array_keys(ClaudeCode::EDIT_TOOLS)), 'hooks' => [['type' => 'command', 'command' => $preToolUse]]]],
                'Stop' => [['hooks' => [['type' => 'command', 'command' => $stop, 'timeout' => 900]]]],
            ]],
            self::Cursor => ['version' => 1, 'hooks' => [
                'preToolUse' => [['command' => $preToolUse, 'matcher' => implode('|', array_keys(Cursor::EDIT_TOOLS))]],
                'stop' => [['command' => $stop, 'timeout' => 900, 'loop_limit' => 1 + CheckDefinition::MAX_RETRY]],
            ]],
        };
    }

    /**
     * The server to merge into mcpFile().
     *
     * @return array<string, mixed>
     */
    public function mcpServer(string $binary): array
    {
        return ['mcpServers' => ['kekkai' => ['command' => 'sh', 'args' => ['-c', $binary . ' serve']]]];
    }

    /**
     * Whether the decoded hooksFile() runs `kekkai hook <event>` for this agent.
     *
     * @param array<mixed> $settings
     */
    public function hasHook(array $settings, string $event): bool
    {
        $needle = 'hook ' . $event;
        foreach ($this->hookCommands($settings, $event) as $command) {
            if (str_contains($command, 'kekkai') && str_contains($command, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function agentOption(): string
    {
        return $this === self::Claude ? '' : ' --agent ' . $this->value;
    }

    /**
     * @param array<mixed> $settings
     *
     * @return list<string>
     */
    private function hookCommands(array $settings, string $event): array
    {
        $name = match ($this) {
            self::Claude => $event === self::STOP ? 'Stop' : 'PreToolUse',
            self::Cursor => $event === self::STOP ? 'stop' : 'preToolUse',
        };
        $hooks = \is_array($settings['hooks'] ?? null) ? $settings['hooks'] : [];
        $entries = \is_array($hooks[$name] ?? null) ? $hooks[$name] : [];

        $commands = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $nested = $this === self::Claude && \is_array($entry['hooks'] ?? null) ? $entry['hooks'] : [$entry];
            foreach ($nested as $hook) {
                if (\is_array($hook) && \is_string($hook['command'] ?? null)) {
                    $commands[] = $hook['command'];
                }
            }
        }

        return $commands;
    }
}
