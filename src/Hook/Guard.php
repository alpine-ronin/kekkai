<?php

declare(strict_types=1);

namespace Kekkai\Hook;

use Kekkai\Check\Report;
use Kekkai\Check\Reviewer;
use Kekkai\Config\CheckTrigger;
use Kekkai\Config\Config;
use Kekkai\Gate\Decision;
use Kekkai\Project;
use Kekkai\Scope\ScopeStore;
use Kekkai\Verdict;

/**
 * What every agent's hooks ask Kekkai, whatever the agent: may this file be edited, may the agent stop.
 * The adapters only translate their agent's JSON to and from these answers.
 */
final class Guard
{
    public const SEND_BACK = 'Fix what failed before finishing: revert the files outside the scope (or widen it with a reason if the change needs them), then make the checks pass.';

    /**
     * The decision for an edit, or null when the edit may go ahead without a word.
     */
    public static function edit(string $path, ?string $root, string $tool): ?Decision
    {
        try {
            $project = Project::at($root);
            $decision = $project->gate()->decide($path);
        } catch (\Throwable $exception) {
            return new Decision(Verdict::Ask, Decision::UNDECIDED, $path, sprintf('Kekkai could not decide (%s): approve or refuse this edit yourself.', $exception->getMessage()));
        }

        if ($decision->verdict === Verdict::Allow) {
            return null;
        }

        $project->store->log(['event' => 'pre-tool-use', 'tool' => $tool, 'path' => $decision->path, 'rule' => $decision->rule, 'verdict' => $decision->verdict->value]);

        return $decision;
    }

    /**
     * The report to send the agent back with, or null when it may stop.
     *
     * @param int $sentBack how many times in a row the agent has already been sent back
     * @param string|null $session the agent's session, when Kekkai must count the send-backs itself
     */
    public static function review(?string $root, bool $runChecks, int $sentBack = 0, ?string $session = null): ?string
    {
        try {
            $project = Project::at($root);
            $maxRetry = $runChecks ? self::maxRetry($project->config) : 0;
            if ($sentBack > $maxRetry) {
                return null;
            }
            $report = (new Reviewer($project))->review('HEAD', $runChecks, CheckTrigger::Stop);
        } catch (\Throwable) {
            return null;
        }

        if ($report->passed()) {
            return null;
        }
        if ($sentBack > 0 && !self::hasRetryLeft($report, $sentBack)) {
            return null;
        }

        if ($session !== null && $maxRetry > 0) {
            $isRecorded = self::recordSentBack($project->store, $session, $sentBack + 1);
            // An unrecorded session counts as sent back once: without a record, the retries would never end.
            if (!$isRecorded && $sentBack > 0) {
                return null;
            }
        }
        $project->store->log(['event' => 'stop', 'blocking' => array_map(static fn (Decision $d): string => $d->path, $report->blocking()), 'passed' => false]);

        return $report->toText() . "\n\n" . self::SEND_BACK;
    }

    private static function recordSentBack(ScopeStore $store, string $session, int $count): bool
    {
        try {
            $store->saveSentBack($session, $count);
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * How many times in a row the stop hook has sent this session's agent back, for an agent that only says it was.
     */
    public static function sentBack(?string $root, string $session): int
    {
        try {
            return Project::at($root)->store->sentBack($session) ?? 1;
        } catch (\InvalidArgumentException) {
            return 1;
        }
    }

    private static function maxRetry(Config $config): int
    {
        $max = 0;
        foreach ($config->checks as $check) {
            if ($check->runsOn(CheckTrigger::Stop)) {
                $max = max($max, $check->retry);
            }
        }

        return $max;
    }

    private static function hasRetryLeft(Report $report, int $sentBack): bool
    {
        foreach ($report->checks as $check) {
            if (!$check->passed && $check->retry >= $sentBack) {
                return true;
            }
        }

        return false;
    }
}
