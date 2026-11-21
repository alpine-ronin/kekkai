<?php

declare(strict_types=1);

namespace Kekkai\Tests\Command;

use Kekkai\Application;
use Kekkai\Command\HookCommand;
use Kekkai\Tests\Support\Json;
use Kekkai\Tests\Support\TemporaryProject;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = (new TemporaryProject())->write('src/Catalog/Product.php')->write('src/Billing/Invoice.php')->commit();
    }

    public function testInitWritesOnceAndTheResultIsValid(): void
    {
        $first = $this->command('init');
        self::assertSame(0, $first->getStatusCode());
        self::assertStringContainsString('kekkai.yaml written', $first->getDisplay());
        self::assertStringContainsString('outside-scope: deny', $this->project->read('kekkai.yaml'));

        $this->project->write('kekkai.yaml', "outside-scope: ask\n");
        $second = $this->command('init');
        self::assertStringContainsString('already exists', $second->getDisplay());
        self::assertSame("outside-scope: ask\n", $this->project->read('kekkai.yaml'));
    }

    public function testScopeLifecycle(): void
    {
        $this->project->write('kekkai.yaml', "zones:\n  catalog: ['src/Catalog/']\n");

        self::assertStringContainsString('No scope declared.', $this->command('scope')->getDisplay());

        $declare = $this->command('scope', ['action' => 'declare', 'paths' => ['catalog'], '-m' => 'Prices']);
        self::assertSame(0, $declare->getStatusCode());
        self::assertStringContainsString('catalog = src/Catalog/', $declare->getDisplay());

        $widen = $this->command('scope', ['action' => 'widen', 'paths' => ['src/Shared/'], '-m' => 'Money']);
        self::assertStringContainsString('src/Shared/ (widened by user: Money)', $widen->getDisplay());

        self::assertStringContainsString('Scope: Prices', $this->command('scope', ['action' => 'show'])->getDisplay());
        self::assertStringContainsString('Scope cleared', $this->command('scope', ['action' => 'clear'])->getDisplay());
        self::assertStringContainsString('No scope to clear', $this->command('scope', ['action' => 'clear'])->getDisplay());
    }

    public function testScopeShowAsJsonIsValidAgainstTheSchema(): void
    {
        $this->project->write('kekkai.yaml', "zones:\n  catalog: ['src/Catalog/']\n");
        self::assertSame('null', trim($this->command('scope', ['--format' => 'json'])->getDisplay()));

        $this->command('scope', ['action' => 'declare', 'paths' => ['catalog'], '-m' => 'Prices']);
        $this->command('scope', ['action' => 'widen', 'paths' => ['src/Shared/'], '-m' => 'Money']);
        $show = $this->command('scope', ['action' => 'show', '--format' => 'json']);

        $schema = json_decode((string) file_get_contents(__DIR__ . '/../../docs/schemas/scope.schema.json'), flags: \JSON_THROW_ON_ERROR);
        self::assertIsObject($schema);
        $error = (new Validator())->validate(json_decode($show->getDisplay()), $schema)->error();
        self::assertNull($error, $error === null ? '' : (string) json_encode((new ErrorFormatter())->format($error)));
        self::assertSame(['catalog'], Json::decode($show->getDisplay(), 'entries'));
        self::assertSame('Money', Json::decode($show->getDisplay(), 'widenings', 0, 'reason'));
    }

    public function testScopeErrors(): void
    {
        $noIntent = $this->command('scope', ['action' => 'declare', 'paths' => ['src/']]);
        self::assertSame(1, $noIntent->getStatusCode());
        self::assertStringContainsString('intent', $noIntent->getDisplay());

        $everything = $this->command('scope', ['action' => 'declare', 'paths' => ['**'], '-m' => 'All']);
        self::assertSame(1, $everything->getStatusCode());

        $widenWithout = $this->command('scope', ['action' => 'widen', 'paths' => ['src/'], '-m' => 'x']);
        self::assertSame(1, $widenWithout->getStatusCode());

        self::assertSame(2, $this->command('scope', ['action' => 'drop'])->getStatusCode());
    }

    public function testCheckExitCodesAndJson(): void
    {
        $this->project->write('kekkai.yaml', "checks:\n  - {name: lint, run: 'test -z \"$(grep -rl TODO src || true)\"'}\n")->commit('config');

        $clean = $this->command('check');
        self::assertSame(0, $clean->getStatusCode());
        self::assertStringContainsString('no change since HEAD', $clean->getDisplay());

        $this->command('scope', ['action' => 'declare', 'paths' => ['src/Catalog/'], '-m' => 'Prices']);
        $this->project->write('src/Catalog/Price.php');
        self::assertSame(0, $this->command('check')->getStatusCode());

        $this->project->write('src/Billing/Invoice.php', "<?php\n// TODO\n");
        $failed = $this->command('check');
        self::assertSame(1, $failed->getStatusCode());
        self::assertStringContainsString('✗ Outside the scope:', $failed->getDisplay());
        self::assertStringContainsString('✗ check lint', $failed->getDisplay());

        $json = $this->command('check', ['--format' => 'json', '--no-checks' => true]);
        $report = json_decode($json->getDisplay(), true);
        self::assertIsArray($report);
        self::assertFalse($report['passed']);
        self::assertSame([], $report['checks']);
        self::assertSame(['src/Billing/Invoice.php', 'src/Catalog/Price.php'], $report['files']);

        self::assertSame(2, $this->command('check', ['--base' => 'nope'])->getStatusCode());
    }

    public function testCheckSinceAnOlderRevision(): void
    {
        $first = trim($this->project->git('rev-parse', 'HEAD'));
        $this->project->write('src/Catalog/Price.php')->commit('second')->write('src/Catalog/Tax.php');

        $sinceHead = json_decode($this->command('check', ['--format' => 'json', '--no-checks' => true])->getDisplay(), true);
        $sinceFirst = json_decode($this->command('check', ['--base' => $first, '--format' => 'json', '--no-checks' => true])->getDisplay(), true);

        self::assertIsArray($sinceHead);
        self::assertIsArray($sinceFirst);
        self::assertSame(['src/Catalog/Tax.php'], $sinceHead['files']);
        self::assertSame(['src/Catalog/Price.php', 'src/Catalog/Tax.php'], $sinceFirst['files']);
        self::assertSame($first, $sinceFirst['base']);
    }

    public function testCheckOnAnInvalidConfiguration(): void
    {
        $this->project->write('kekkai.yaml', "outside-scope: maybe\n");

        $result = $this->command('check');

        self::assertSame(2, $result->getStatusCode());
        self::assertStringContainsString('outside-scope must be one of: ask, deny.', $result->getDisplay());
    }

    public function testHookCommand(): void
    {
        $this->command('scope', ['action' => 'declare', 'paths' => ['src/Catalog/'], '-m' => 'Prices']);
        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        fwrite($stdin, (string) json_encode(['tool_name' => 'Edit', 'tool_input' => ['file_path' => $this->project->root . '/src/Billing/Invoice.php']]));
        rewind($stdin);

        $tester = new CommandTester(new HookCommand($stdin));
        self::assertSame(0, $tester->execute(['event' => 'pre-tool-use', '--root' => $this->project->root]));
        self::assertSame('deny', Json::decode($tester->getDisplay(), 'hookSpecificOutput', 'permissionDecision'));

        $unknown = new CommandTester(new HookCommand(fopen('php://memory', 'r') ?: null));
        self::assertSame(2, $unknown->execute(['event' => 'post-tool-use', '--root' => $this->project->root]));

        $cursorStdin = fopen('php://memory', 'r+');
        self::assertIsResource($cursorStdin);
        fwrite($cursorStdin, (string) json_encode(['tool_name' => 'Write', 'tool_input' => ['file_path' => $this->project->root . '/src/Billing/Invoice.php']]));
        rewind($cursorStdin);
        $cursor = new CommandTester(new HookCommand($cursorStdin));
        self::assertSame(0, $cursor->execute(['event' => 'pre-tool-use', '--agent' => 'cursor', '--root' => $this->project->root]));
        self::assertSame('deny', Json::decode($cursor->getDisplay(), 'permission'));

        $unknownAgent = new CommandTester(new HookCommand(fopen('php://memory', 'r') ?: null));
        self::assertSame(2, $unknownAgent->execute(['event' => 'stop', '--agent' => 'aider', '--root' => $this->project->root]));
    }

    public function testInstallPrintsThenWritesWithoutLosingAnything(): void
    {
        $printed = $this->command('install', ['agent' => 'claude', '--binary' => 'kekkai']);
        self::assertStringContainsString('"command": "kekkai hook pre-tool-use"', $printed->getDisplay());
        self::assertFileDoesNotExist($this->project->root . '/.claude/settings.json');

        $this->project->write('.claude/settings.json', (string) json_encode(['permissions' => ['allow' => ['Bash(ls)']], 'hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'airlock check']]]]]]));
        $this->project->write('.mcp.json', (string) json_encode(['mcpServers' => ['phpgraph' => ['command' => 'phpgraph', 'args' => ['serve']]]]));

        $this->command('install', ['agent' => 'claude', '--write' => true, '--binary' => 'kekkai']);
        $once = $this->project->read('.claude/settings.json') . $this->project->read('.mcp.json');
        $this->command('install', ['agent' => 'claude', '--write' => true, '--binary' => 'kekkai']);
        self::assertSame($once, $this->project->read('.claude/settings.json') . $this->project->read('.mcp.json'));

        $settings = $this->project->read('.claude/settings.json');
        self::assertSame(['Bash(ls)'], Json::decode($settings, 'permissions', 'allow'));
        self::assertSame('airlock check', Json::decode($settings, 'hooks', 'PreToolUse', 0, 'hooks', 0, 'command'));
        self::assertSame('kekkai hook pre-tool-use', Json::decode($settings, 'hooks', 'PreToolUse', 1, 'hooks', 0, 'command'));
        self::assertNull(Json::decode($settings, 'hooks', 'PreToolUse', 2));
        self::assertSame('kekkai hook stop', Json::decode($settings, 'hooks', 'Stop', 0, 'hooks', 0, 'command'));

        $mcp = $this->project->read('.mcp.json');
        self::assertSame('phpgraph', Json::decode($mcp, 'mcpServers', 'phpgraph', 'command'));
        self::assertSame('sh', Json::decode($mcp, 'mcpServers', 'kekkai', 'command'));

        self::assertSame(2, $this->command('install', ['agent' => 'aider'])->getStatusCode());
    }

    public function testInstallCursor(): void
    {
        $this->command('install', ['agent' => 'cursor', '--write' => true, '--binary' => 'kekkai']);
        $this->command('install', ['agent' => 'cursor', '--write' => true, '--binary' => 'kekkai']);

        $hooks = $this->project->read('.cursor/hooks.json');
        self::assertSame(1, Json::decode($hooks, 'version'));
        self::assertSame('kekkai hook pre-tool-use --agent cursor', Json::decode($hooks, 'hooks', 'preToolUse', 0, 'command'));
        self::assertSame('Write', Json::decode($hooks, 'hooks', 'preToolUse', 0, 'matcher'));
        self::assertNull(Json::decode($hooks, 'hooks', 'preToolUse', 1));
        self::assertSame('kekkai hook stop --agent cursor', Json::decode($hooks, 'hooks', 'stop', 0, 'command'));
        self::assertSame('sh', Json::decode($this->project->read('.cursor/mcp.json'), 'mcpServers', 'kekkai', 'command'));

        $doctor = $this->command('doctor');
        self::assertStringContainsString('✓ Cursor edit hook', $doctor->getDisplay());
        self::assertStringContainsString('✓ MCP server kekkai in .cursor/mcp.json', $doctor->getDisplay());
        self::assertStringNotContainsString('Claude Code', $doctor->getDisplay());
    }

    public function testDoctor(): void
    {
        $before = $this->command('doctor');
        self::assertSame(1, $before->getStatusCode());
        self::assertStringContainsString('✗ No agent is connected', $before->getDisplay());
        self::assertStringContainsString('→ kekkai install <claude|cursor> --write', $before->getDisplay());

        $this->command('init');
        $this->project->write('kekkai.yaml', $this->project->read('kekkai.yaml') . "  - {name: graph, run: definitely-not-installed-tool check}\n");
        $this->command('install', ['agent' => 'claude', '--write' => true, '--binary' => 'kekkai']);

        $after = $this->command('doctor');
        self::assertSame(0, $after->getStatusCode(), $after->getDisplay());
        self::assertStringContainsString('✓ Claude Code edit hook', $after->getDisplay());
        self::assertStringContainsString('✓ MCP server kekkai in .mcp.json', $after->getDisplay());
        self::assertStringContainsString('! Check "graph": definitely-not-installed-tool is not on the PATH', $after->getDisplay());
    }

    public function testDoctorOutsideGit(): void
    {
        $project = new TemporaryProject(git: false);
        $tester = new CommandTester((new Application())->find('doctor'));
        $tester->execute(['--root' => $project->root]);

        self::assertStringContainsString('✗ Not a git repository', $tester->getDisplay());
    }

    public function testApplicationListsEveryCommand(): void
    {
        $application = new Application();

        foreach (['init', 'install', 'doctor', 'scope', 'check', 'hook', 'serve', 'self-update', 'update'] as $name) {
            self::assertTrue($application->has($name), $name);
            self::assertNotSame('', $application->find($name)->getHelp(), $name);
        }
        self::assertStringContainsString('kekkai install claude --write', $application->getHelp());
    }

    /**
     * @param string $name
     * @param array<string, mixed> $input
     */
    private function command(string $name, array $input = []): CommandTester
    {
        $command = (new Application())->find($name);
        $tester = new CommandTester($command);
        $definition = $command->getDefinition();
        if ($definition->hasOption('root')) {
            $input['--root'] = $this->project->root;
        }
        $tester->execute($input);

        return $tester;
    }
}
