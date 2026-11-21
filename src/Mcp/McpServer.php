<?php

declare(strict_types=1);

namespace Kekkai\Mcp;

use Kekkai\Check\Reviewer;
use Kekkai\Config\CheckTrigger;
use Kekkai\Project;
use Kekkai\Scope\Scope;

final class McpServer
{
    public const PROTOCOLS = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];

    private const INSTRUCTIONS = 'Kekkai keeps your edits inside the scope of the task. Before the first edit, call declare_scope with what the '
        . 'change is for and the smallest set of paths, globs or zones it needs. Edits outside it are refused. If the task truly needs more, '
        . 'call widen_scope with the paths and the reason: every widening is shown to the reviewer. Call check_changes before you say you are done.';

    /**
     * @param Project $project
     * @param string $version
     */
    public function __construct(
        private readonly Project $project,
        private readonly string $version = 'dev',
    ) {
    }

    /**
     * @param resource $input
     * @param resource $output
     */
    public function run($input = \STDIN, $output = \STDOUT): void
    {
        while (true) {
            $line = fgets($input);
            if ($line === false) {
                if (feof($input)) {
                    return;
                }

                continue;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $request = json_decode($line, true);
            $response = \is_array($request) ? $this->handle($request) : $this->error(null, -32700, 'Parse error');
            if ($response !== null) {
                fwrite($output, json_encode($response, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                fflush($output);
            }
        }
    }

    /**
     * @param array<mixed> $request
     *
     * @return array<string, mixed>|null
     */
    public function handle(array $request): ?array
    {
        if (!\array_key_exists('id', $request)) {
            return null;
        }

        $id = $request['id'];
        $method = $request['method'] ?? null;
        $params = \is_array($request['params'] ?? null) ? $request['params'] : [];
        if (!\is_string($method)) {
            return $this->error($id, -32600, 'Invalid request');
        }

        $result = match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => new \stdClass(),
            'tools/list' => ['tools' => self::tools()],
            'tools/call' => $this->call($params),
            default => null,
        };

        if ($result === null) {
            return $this->error($id, -32601, sprintf('Method not found: %s', $method));
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function tools(): array
    {
        $paths = ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'description' => 'Paths, globs (src/Catalog/**) or zone names of kekkai.yaml, relative to the project root.'];

        return [
            [
                'name' => 'declare_scope',
                'description' => 'Declare, before the first edit, what the task is for and the files it may change. Edits outside are refused.',
                'inputSchema' => ['type' => 'object', 'properties' => ['intent' => ['type' => 'string', 'description' => 'One sentence: what the change is for.'], 'paths' => $paths], 'required' => ['intent', 'paths']],
            ],
            [
                'name' => 'widen_scope',
                'description' => 'Add paths to the scope when the task truly needs them. The reason is shown to the reviewer.',
                'inputSchema' => ['type' => 'object', 'properties' => ['paths' => $paths, 'reason' => ['type' => 'string', 'description' => 'Why the task needs these paths.']], 'required' => ['paths', 'reason']],
            ],
            [
                'name' => 'show_scope',
                'description' => 'The current scope, with its widenings, and the zones of kekkai.yaml.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'check_changes',
                'description' => 'Before saying you are done: the files changed since HEAD outside the scope, and the result of the checks of kekkai.yaml.',
                'inputSchema' => ['type' => 'object', 'properties' => ['base' => ['type' => 'string', 'description' => 'Git revision to compare with (default HEAD).'], 'run_checks' => ['type' => 'boolean', 'description' => 'Run the checks of kekkai.yaml (default true).']]],
            ],
        ];
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => \is_string($asked) && \in_array($asked, self::PROTOCOLS, true) ? $asked : self::PROTOCOLS[\count(self::PROTOCOLS) - 1],
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => ['name' => 'kekkai', 'version' => $this->version],
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $text = match ($name) {
                'declare_scope' => $this->declare($arguments),
                'widen_scope' => $this->widen($arguments),
                'show_scope' => $this->show(),
                'check_changes' => $this->check($arguments),
                default => throw new \InvalidArgumentException(sprintf('Unknown tool: %s', \is_string($name) ? $name : '')),
            };
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return ['content' => [['type' => 'text', 'text' => $exception->getMessage()]], 'isError' => true];
        }

        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @param array<mixed> $arguments
     */
    private function declare(array $arguments): string
    {
        $current = $this->project->scope();
        if ($current !== null) {
            throw new \InvalidArgumentException("A scope is already declared:\n" . $current->describe($this->project->config) . "\nUse widen_scope to add paths, or ask the user to run `kekkai scope clear` for a new task.");
        }

        $scope = Scope::declare(self::text($arguments, 'intent'), self::paths($arguments), 'agent', $this->project->config);
        $this->project->store->save($scope);
        $this->project->store->log(['event' => 'declare', 'by' => 'agent', 'entries' => $scope->entries]);

        return "Scope declared.\n" . $scope->describe($this->project->config);
    }

    /**
     * @param array<mixed> $arguments
     */
    private function widen(array $arguments): string
    {
        if (!$this->project->config->allowWiden) {
            throw new \InvalidArgumentException('Widening is reserved to the user in this project: stop and ask them, they run `kekkai scope widen`.');
        }

        $current = $this->project->scope();
        if ($current === null) {
            throw new \InvalidArgumentException('No scope is declared yet: call declare_scope first.');
        }

        $scope = $current->widen(self::paths($arguments), self::text($arguments, 'reason'), 'agent', $this->project->config);
        $this->project->store->save($scope);
        $this->project->store->log(['event' => 'widen', 'by' => 'agent', 'entries' => self::paths($arguments)]);

        return "Scope widened.\n" . $scope->describe($this->project->config);
    }

    private function show(): string
    {
        $config = $this->project->config;
        $lines = [$this->project->scope()?->describe($config) ?? 'No scope declared.'];
        if ($config->zones !== []) {
            $lines[] = 'Zones: ' . implode(', ', array_keys($config->zones));
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<mixed> $arguments
     */
    private function check(array $arguments): string
    {
        $base = \is_string($arguments['base'] ?? null) && $arguments['base'] !== '' ? $arguments['base'] : 'HEAD';
        $runChecks = ($arguments['run_checks'] ?? true) !== false;

        return (new Reviewer($this->project))->review($base, $runChecks, CheckTrigger::Stop)->toText();
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return list<string>
     */
    private static function paths(array $arguments): array
    {
        $paths = $arguments['paths'] ?? null;
        if (\is_string($paths)) {
            return [$paths];
        }
        if (!\is_array($paths)) {
            throw new \InvalidArgumentException('paths must be a list of paths, globs or zones.');
        }

        return array_values(array_filter($paths, 'is_string'));
    }

    /**
     * @param array<mixed> $arguments
     * @param string $key
     */
    private static function text(array $arguments, string $key): string
    {
        return \is_string($arguments[$key] ?? null) ? $arguments[$key] : '';
    }

    /**
     * @param mixed $id
     * @param int $code
     * @param string $message
     *
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
