<?php

declare(strict_types=1);

namespace Kekkai\Config;

use Kekkai\Glob;
use Kekkai\Verdict;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class Config
{
    public const FILES = ['kekkai.yaml', 'kekkai.yaml.dist'];

    public const GUARDED = ['.kekkai/', 'kekkai.yaml', 'kekkai.yaml.dist', '.git/'];

    /**
     * @param array<string, list<Glob>> $zones
     * @param list<Glob> $protected
     * @param list<Glob> $alwaysAllowed
     * @param list<CheckDefinition> $checks
     * @param string|null $file
     */
    public function __construct(
        public array $zones = [],
        public array $protected = [],
        public array $alwaysAllowed = [],
        public Verdict $withoutScope = Verdict::Ask,
        public Verdict $outsideScope = Verdict::Deny,
        public bool $allowWiden = true,
        public array $checks = [],
        public ?string $file = null,
    ) {
    }

    /**
     * @param string $root
     */
    public static function load(string $root): self
    {
        foreach (self::FILES as $name) {
            $file = rtrim($root, '/') . '/' . $name;
            if (!is_file($file)) {
                continue;
            }

            try {
                $data = Yaml::parseFile($file);
            } catch (ParseException $exception) {
                throw new \InvalidArgumentException(sprintf('%s: %s', $name, $exception->getMessage()), 0, $exception);
            }

            return self::fromArray(\is_array($data) ? $data : [], $name);
        }

        return new self();
    }

    /**
     * @param array<mixed> $data
     * @param string|null $file
     */
    public static function fromArray(array $data, ?string $file = null): self
    {
        $known = ['zones', 'protected', 'always-allowed', 'without-scope', 'outside-scope', 'allow-widen', 'checks'];
        $unknown = array_diff(array_map('strval', array_keys($data)), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown key(s) in %s: %s. Known keys: %s.', $file ?? 'the configuration', implode(', ', $unknown), implode(', ', $known)));
        }

        $zones = [];
        foreach (self::map($data['zones'] ?? [], 'zones') as $name => $patterns) {
            if (preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
                throw new \InvalidArgumentException(sprintf('Zone name "%s" may only hold letters, digits, ".", "_" and "-".', $name));
            }
            $zones[$name] = self::globs($patterns, sprintf('zones.%s', $name));
        }

        $withoutScope = self::verdict($data['without-scope'] ?? 'ask', 'without-scope', [Verdict::Allow, Verdict::Ask, Verdict::Deny]);
        $outsideScope = self::verdict($data['outside-scope'] ?? 'deny', 'outside-scope', [Verdict::Ask, Verdict::Deny]);

        $allowWiden = $data['allow-widen'] ?? true;
        if (!\is_bool($allowWiden)) {
            throw new \InvalidArgumentException('allow-widen must be true or false.');
        }

        $checks = [];
        foreach (self::list($data['checks'] ?? [], 'checks') as $index => $check) {
            if (!\is_array($check) || !\is_string($check['run'] ?? null) || trim($check['run']) === '') {
                throw new \InvalidArgumentException(sprintf('checks[%d] needs a "run" command.', $index));
            }
            $name = $check['name'] ?? $check['run'];
            $timeout = $check['timeout'] ?? 300;
            if (!\is_string($name) || !\is_int($timeout) || $timeout < 1) {
                throw new \InvalidArgumentException(sprintf('checks[%d]: "name" must be a string and "timeout" a positive number of seconds.', $index));
            }
            $checks[] = new CheckDefinition($name, $check['run'], $timeout, self::retry($check['retry'] ?? 0, $index), self::triggers($check['on'] ?? ['stop', 'ci'], $index));
        }

        return new self(
            $zones,
            self::globs($data['protected'] ?? [], 'protected'),
            self::globs($data['always-allowed'] ?? [], 'always-allowed'),
            $withoutScope,
            $outsideScope,
            $allowWiden,
            $checks,
            $file,
        );
    }

    /**
     * @param string $path
     */
    public function isGuarded(string $path): bool
    {
        foreach (self::GUARDED as $pattern) {
            if ((new Glob($pattern))->matches($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Glob> $globs
     * @param string $path
     */
    public static function firstMatch(array $globs, string $path): ?Glob
    {
        foreach ($globs as $glob) {
            if ($glob->matches($path)) {
                return $glob;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @param string $key
     *
     * @return list<Glob>
     */
    private static function globs(mixed $value, string $key): array
    {
        $globs = [];
        foreach (self::list($value, $key) as $pattern) {
            if (!\is_string($pattern) || trim($pattern) === '') {
                throw new \InvalidArgumentException(sprintf('%s must be a list of path patterns.', $key));
            }
            $globs[] = new Glob($pattern);
        }

        return $globs;
    }

    /**
     * @param mixed $value
     * @param string $key
     *
     * @return list<mixed>
     */
    private static function list(mixed $value, string $key): array
    {
        if (\is_string($value)) {
            return [$value];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a list.', $key));
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @param string $key
     *
     * @return array<string, mixed>
     */
    private static function map(mixed $value, string $key): array
    {
        if ($value === [] || $value === null) {
            return [];
        }
        if (!\is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf('%s must map names to path patterns.', $key));
        }

        $map = [];
        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }

    /**
     * @param mixed $value
     * @param int $index
     */
    private static function retry(mixed $value, int $index): int
    {
        if (!\is_int($value) || $value < 0 || $value > CheckDefinition::MAX_RETRY) {
            throw new \InvalidArgumentException(sprintf('checks[%d]: "retry" must be a whole number from 0 to %d.', $index, CheckDefinition::MAX_RETRY));
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @param int $index
     *
     * @return list<CheckTrigger>
     */
    private static function triggers(mixed $value, int $index): array
    {
        $error = sprintf('checks[%d]: "on" must be a non-empty list of: stop, ci.', $index);
        $triggers = [];
        foreach (self::list($value, sprintf('checks[%d].on', $index)) as $name) {
            $trigger = \is_string($name) ? CheckTrigger::tryFrom($name) : null;
            if ($trigger === null) {
                throw new \InvalidArgumentException($error);
            }
            if (!\in_array($trigger, $triggers, true)) {
                $triggers[] = $trigger;
            }
        }
        if ($triggers === []) {
            throw new \InvalidArgumentException($error);
        }

        return $triggers;
    }

    /**
     * @param mixed $value
     * @param string $key
     * @param list<Verdict> $allowed
     */
    private static function verdict(mixed $value, string $key, array $allowed): Verdict
    {
        $verdict = \is_string($value) ? Verdict::tryFrom($value) : null;
        if ($verdict === null || !\in_array($verdict, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('%s must be one of: %s.', $key, implode(', ', array_map(static fn (Verdict $v): string => $v->value, $allowed))));
        }

        return $verdict;
    }
}
