<?php

declare(strict_types=1);

namespace Kekkai\Tests\Integration;

use Kekkai\Check\Reviewer;
use Kekkai\Hook\ClaudeCode;
use Kekkai\Hook\Cursor;
use Kekkai\Project;
use Kekkai\Scope\ScopeStore;
use Kekkai\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

final class StopRetryTest extends TestCase
{
    public function testWithoutRetryTheAgentIsSentBackOnce(): void
    {
        $project = $this->failingProject("checks:\n  - name: fails\n    run: 'false'\n");

        self::assertIsArray(ClaudeCode::stop(['session_id' => 's'], $project->root));
        self::assertNull(ClaudeCode::stop(['session_id' => 's', 'stop_hook_active' => true], $project->root));
        self::assertFileDoesNotExist($project->root . '/.kekkai/stop.json');

        self::assertIsArray(Cursor::stop(['status' => 'completed', 'loop_count' => 0], $project->root));
        self::assertNull(Cursor::stop(['status' => 'completed', 'loop_count' => 1], $project->root));
    }

    public function testRetrySendsTheAgentBackThatManyMoreTimes(): void
    {
        $project = $this->failingProject("checks:\n  - name: fails\n    run: 'false'\n    retry: 2\n  - name: once\n    run: 'false'\n");
        $continuing = ['session_id' => 's', 'stop_hook_active' => true];

        self::assertIsArray(ClaudeCode::stop(['session_id' => 's'], $project->root));
        self::assertIsArray(ClaudeCode::stop($continuing, $project->root));
        self::assertIsArray(ClaudeCode::stop($continuing, $project->root));
        self::assertNull(ClaudeCode::stop($continuing, $project->root));
        self::assertIsArray(ClaudeCode::stop(['session_id' => 'other', 'stop_hook_active' => true], $project->root), 'an unrecorded session counts as sent back once');
        self::assertIsArray(ClaudeCode::stop(['session_id' => 's'], $project->root), 'a new stop starts the count again');

        self::assertIsArray(Cursor::stop(['status' => 'completed', 'loop_count' => 2], $project->root));
        self::assertNull(Cursor::stop(['status' => 'completed', 'loop_count' => 3], $project->root));
    }

    public function testTwoSessionsKeepTheirOwnCount(): void
    {
        $project = $this->failingProject("checks:\n  - name: fails\n    run: 'false'\n    retry: 1\n");

        foreach (['s', 'other'] as $session) {
            self::assertIsArray(ClaudeCode::stop(['session_id' => $session], $project->root));
        }
        foreach (['s', 'other'] as $session) {
            self::assertIsArray(ClaudeCode::stop(['session_id' => $session, 'stop_hook_active' => true], $project->root));
        }
        foreach (['s', 'other'] as $session) {
            self::assertNull(ClaudeCode::stop(['session_id' => $session, 'stop_hook_active' => true], $project->root));
        }

        (new ScopeStore($project->root))->clear();
        self::assertFileDoesNotExist($project->root . '/.kekkai/stop.json', 'clearing the scope ends the task, and its counts');
    }

    public function testACountThatCannotBeWrittenEndsTheRetries(): void
    {
        $project = $this->failingProject("checks:\n  - name: fails\n    run: 'false'\n    retry: 3\n");
        $project->write('.kekkai', 'not a directory');

        set_error_handler(static fn (): bool => true);
        try {
            self::assertIsArray(ClaudeCode::stop(['session_id' => 's'], $project->root));
            self::assertNull(ClaudeCode::stop(['session_id' => 's', 'stop_hook_active' => true], $project->root));
        } finally {
            restore_error_handler();
        }
    }

    public function testRetryOnlyForTheCheckThatHasIt(): void
    {
        $project = $this->failingProject("checks:\n  - name: passes\n    run: 'true'\n    retry: 3\n  - name: fails\n    run: 'false'\n");

        self::assertIsArray(Cursor::stop(['status' => 'completed', 'loop_count' => 0], $project->root));
        self::assertNull(Cursor::stop(['status' => 'completed', 'loop_count' => 1], $project->root));
    }

    public function testACiCheckDoesNotRunAtStop(): void
    {
        $project = $this->failingProject("checks:\n  - name: slow\n    run: 'false'\n    on: [ci]\n");

        self::assertNull(ClaudeCode::stop(['session_id' => 's'], $project->root));

        $report = (new Reviewer(Project::at($project->root)))->review();
        self::assertFalse($report->passed());
        self::assertStringContainsString('✗ check slow', $report->toText());
    }

    private function failingProject(string $config): TemporaryProject
    {
        $project = (new TemporaryProject())->write('kekkai.yaml', $config)->write('src/A.php')->commit();
        $project->write('src/A.php', "<?php\n// changed\n");

        return $project;
    }
}
