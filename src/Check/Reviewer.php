<?php

declare(strict_types=1);

namespace Kekkai\Check;

use Kekkai\Change\ChangedFiles;
use Kekkai\Config\CheckTrigger;
use Kekkai\Gate\Decision;
use Kekkai\Process;
use Kekkai\Project;
use Kekkai\Verdict;

final readonly class Reviewer
{
    /**
     * @param Project $project
     */
    public function __construct(private Project $project)
    {
    }

    /**
     * @param string $base
     * @param bool $runChecks
     * @param CheckTrigger $trigger only the checks that run on it
     */
    public function review(string $base = 'HEAD', bool $runChecks = true, CheckTrigger $trigger = CheckTrigger::Ci): Report
    {
        $files = (new ChangedFiles($this->project->root))->since($base);
        $scope = $this->project->scope();
        $gate = $this->project->gate();

        $findings = [];
        $decisions = [];
        foreach ($files as $file) {
            $decision = $gate->decide($file);
            $decisions[] = $decision;
            if ($decision->rule === Decision::NO_SCOPE && $decision->verdict === Verdict::Allow) {
                continue;
            }
            if ($decision->verdict !== Verdict::Allow) {
                $findings[] = $decision;
            }
        }

        $checks = [];
        if ($runChecks && $files !== []) {
            foreach ($this->project->config->checks as $definition) {
                if (!$definition->runsOn($trigger)) {
                    continue;
                }
                $process = Process::run($definition->run, $this->project->root, $definition->timeout, [
                    'KEKKAI_CHANGED_FILES' => implode("\n", $files),
                    'KEKKAI_BASE' => $base,
                ]);
                $checks[] = new CheckResult($definition->name, $definition->run, $process->exitCode === 0, $process->output, $process->timedOut, $definition->retry);
            }
        }

        return new Report($files, $findings, $checks, $scope, $this->project->config, $base, $decisions, $this->project->root);
    }
}
