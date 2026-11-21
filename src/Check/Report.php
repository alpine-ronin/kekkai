<?php

declare(strict_types=1);

namespace Kekkai\Check;

use Kekkai\Config\Config;
use Kekkai\Gate\Decision;
use Kekkai\Scope\Scope;
use Kekkai\Verdict;
use Symfony\Component\Console\Formatter\OutputFormatter;

final readonly class Report
{
    /**
     * @param list<string> $files
     * @param list<Decision> $findings
     * @param list<CheckResult> $checks
     * @param Scope|null $scope
     * @param Config $config
     * @param string $base
     * @param list<Decision> $decisions the decision for each changed file, allowed ones included
     * @param string $root the project's directory, which a check may print before a path
     */
    public function __construct(
        public array $files,
        public array $findings,
        public array $checks,
        public ?Scope $scope,
        public Config $config,
        public string $base,
        public array $decisions,
        public string $root,
    ) {
    }

    public function passed(): bool
    {
        return $this->blocking() === [] && array_filter($this->checks, static fn (CheckResult $c): bool => !$c->passed) === [];
    }

    /**
     * @return list<Decision>
     */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (Decision $d): bool => $this->isBlocking($d),
        ));
    }

    /**
     * @param bool $styled
     */
    public function toText(bool $styled = false): string
    {
        $text = static fn (string $value): string => $styled ? OutputFormatter::escape($value) : $value;
        $mark = static fn (string $symbol, string $style): string => $styled ? sprintf('<%s>%s</>', $style, $symbol) : $symbol;
        $fail = $mark('✗', 'fg=red;options=bold');
        $warn = $mark('!', 'fg=yellow;options=bold');
        $pass = $mark('✓', 'fg=green;options=bold');

        if ($this->files === []) {
            return sprintf('Kekkai: no change since %s.', $text($this->base));
        }

        $lines = [];
        foreach ($this->decisions as $decision) {
            $failedChecks = $this->failedChecksNaming($decision->path);
            $markOfFile = match (true) {
                $failedChecks !== [], $this->isBlocking($decision) => $fail,
                $decision->verdict !== Verdict::Allow => $warn,
                default => $pass,
            };
            $line = sprintf('%s %s: %s', $markOfFile, $text($decision->path), $text(self::describeVerdict($decision)));
            if ($failedChecks !== []) {
                $line .= $text(sprintf('; check %s failed', implode(', ', $failedChecks)));
            }
            $lines[] = $line;
        }

        $lines[] = sprintf(
            'Kekkai: %d file(s) changed since %s. %s',
            \count($this->files),
            $text($this->base),
            $this->passed() ? $mark('PASSED', 'fg=green;options=bold') : $mark('FAILED', 'fg=red;options=bold'),
        );
        $lines[] = $text($this->scope?->describe($this->config) ?? 'Scope: none declared');

        $groups = [
            Decision::GUARDED => 'Kekkai files changed (the agent may not edit them: check who did)',
            Decision::OUTSIDE_SCOPE => 'Outside the scope',
            Decision::PROTECTED => 'Protected files touched (check the user approved them)',
            Decision::OUTSIDE_PROJECT => 'Outside the project',
            Decision::NO_SCOPE => 'Changed without a declared scope',
        ];
        foreach ($groups as $rule => $title) {
            $paths = array_map(static fn (Decision $d): string => $d->path, array_filter($this->findings, static fn (Decision $d): bool => $d->rule === $rule));
            if ($paths === []) {
                continue;
            }
            $blocking = $rule === Decision::OUTSIDE_SCOPE && $this->config->outsideScope === Verdict::Deny;
            $lines[] = sprintf('%s %s:', $blocking ? $fail : $warn, $title);
            foreach ($paths as $path) {
                $lines[] = '  ' . $text($path);
            }
        }

        if ($this->scope !== null && $this->scope->widenings !== []) {
            $lines[] = sprintf('%s Scope widened %d time(s) during the task: see the scope above.', $warn, \count($this->scope->widenings));
        }

        foreach ($this->checks as $check) {
            $lines[] = sprintf('%s check %s%s', $check->passed ? $pass : $fail, $text($check->name), $check->timedOut ? ' (timed out)' : '');
            if (!$check->passed && trim($check->output) !== '') {
                $lines[] = $text(preg_replace('/^/m', '    ', $check->tail()) ?? $check->tail());
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'base' => $this->base,
            'files' => $this->files,
            'scope' => $this->scope?->toArray(),
            'findings' => array_map(static fn (Decision $d): array => ['path' => $d->path, 'rule' => $d->rule, 'verdict' => $d->verdict->value], $this->findings),
            'checks' => array_map(static fn (CheckResult $c): array => ['name' => $c->name, 'command' => $c->command, 'passed' => $c->passed, 'timedOut' => $c->timedOut, 'output' => $c->tail()], $this->checks),
            'verdicts' => array_map(fn (Decision $d): array => [
                'path' => $d->path,
                'rule' => $d->rule,
                'verdict' => $d->verdict->value,
                'matchedBy' => $d->matchedBy,
                'failedChecks' => $this->failedChecksNaming($d->path),
            ], $this->decisions),
        ];
    }

    /**
     * @param string $path
     *
     * @return list<string> the failing checks whose output names the file
     */
    private function failedChecksNaming(string $path): array
    {
        $names = [];
        foreach ($this->checks as $check) {
            if (!$check->passed && $this->names($this->withoutLongerPathsHolding($check->output, $path), $path)) {
                $names[] = $check->name;
            }
        }

        return $names;
    }

    /**
     * A changed file whose path holds this one (tests/src/A.php for src/A.php) must not count as naming it.
     */
    private function withoutLongerPathsHolding(string $output, string $path): string
    {
        $longer = array_values(array_filter($this->files, static fn (string $file): bool => $file !== $path && str_contains($file, $path)));
        usort($longer, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return str_replace($longer, '', $output);
    }

    /**
     * The path on its own or after the project's directory: not inside a longer name (tests/src/A.php, src/A.php.bak).
     */
    private function names(string $output, string $path): bool
    {
        $root = preg_quote(rtrim($this->root, '/') . '/', '~');
        $pattern = '~(?<![\w./-])(?:' . $root . ')?' . preg_quote($path, '~') . '(?![\w/-]|\.\w)~';

        return preg_match($pattern, $output) === 1;
    }

    private function isBlocking(Decision $decision): bool
    {
        return $decision->rule === Decision::OUTSIDE_SCOPE && $decision->verdict === Verdict::Deny;
    }

    private static function describeVerdict(Decision $decision): string
    {
        $label = match ($decision->rule) {
            Decision::IN_SCOPE => 'in scope',
            Decision::ALWAYS_ALLOWED => 'always allowed',
            Decision::OUTSIDE_SCOPE => 'out of scope',
            Decision::PROTECTED => 'protected',
            Decision::GUARDED => 'Kekkai file',
            Decision::OUTSIDE_PROJECT => 'outside the project',
            Decision::NO_SCOPE => 'no scope declared',
            default => $decision->rule,
        };

        return $decision->matchedBy === null ? $label : sprintf('%s (%s)', $label, $decision->matchedBy);
    }
}
