<?php

declare(strict_types=1);

namespace Kekkai\Scope;

use Kekkai\Config\Config;
use Kekkai\Glob;
use Kekkai\Path;

final readonly class Scope
{
    /**
     * @param string $intent
     * @param list<string> $entries
     * @param string $by
     * @param string $declaredAt
     * @param list<Widening> $widenings
     */
    public function __construct(
        public string $intent,
        public array $entries,
        public string $by,
        public string $declaredAt,
        public array $widenings = [],
    ) {
    }

    /**
     * @param string $intent
     * @param list<string> $entries
     * @param string $by
     * @param Config $config
     */
    public static function declare(string $intent, array $entries, string $by, Config $config): self
    {
        if (trim($intent) === '') {
            throw new \InvalidArgumentException('Say in one sentence what the change is for (intent).');
        }

        return new self(trim($intent), self::validated($entries, $config), $by, self::now());
    }

    /**
     * @param list<string> $entries
     * @param string $reason
     * @param string $by
     * @param Config $config
     */
    public function widen(array $entries, string $reason, string $by, Config $config): self
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Say why the scope must grow (reason).');
        }

        $added = array_values(array_diff(self::validated($entries, $config), $this->allEntries()));
        if ($added === []) {
            throw new \InvalidArgumentException('These paths are already in the scope.');
        }

        return new self($this->intent, $this->entries, $this->by, $this->declaredAt, [...$this->widenings, new Widening($added, trim($reason), $by, self::now())]);
    }

    /**
     * @return list<string>
     */
    public function allEntries(): array
    {
        $entries = $this->entries;
        foreach ($this->widenings as $widening) {
            array_push($entries, ...$widening->entries);
        }

        return array_values(array_unique($entries));
    }

    /**
     * @param string $path
     * @param Config $config
     */
    public function covers(string $path, Config $config): bool
    {
        return Config::firstMatch($this->globs($config), $path) !== null;
    }

    /**
     * @param string $path
     * @param Config $config
     *
     * @return string|null the first entry, path, glob or zone name, that covers the path
     */
    public function coveringEntry(string $path, Config $config): ?string
    {
        foreach ($this->allEntries() as $entry) {
            $globs = $config->zones[$entry] ?? [new Glob($entry)];
            if (Config::firstMatch($globs, $path) !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param Config $config
     *
     * @return list<Glob>
     */
    public function globs(Config $config): array
    {
        $globs = [];
        foreach ($this->allEntries() as $entry) {
            if (isset($config->zones[$entry])) {
                array_push($globs, ...$config->zones[$entry]);
            } else {
                $globs[] = new Glob($entry);
            }
        }

        return $globs;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'entries' => $this->entries,
            'by' => $this->by,
            'declaredAt' => $this->declaredAt,
            'widenings' => array_map(static fn (Widening $w): array => $w->toArray(), $this->widenings),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $strings = static fn (mixed $list): array => \is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
        $text = static fn (mixed $value): string => \is_string($value) ? $value : '';

        $widenings = [];
        foreach (\is_array($data['widenings'] ?? null) ? $data['widenings'] : [] as $widening) {
            if (\is_array($widening)) {
                $widenings[] = new Widening($strings($widening['entries'] ?? []), $text($widening['reason'] ?? ''), $text($widening['by'] ?? ''), $text($widening['at'] ?? ''));
            }
        }

        return new self($text($data['intent'] ?? ''), $strings($data['entries'] ?? []), $text($data['by'] ?? ''), $text($data['declaredAt'] ?? ''), $widenings);
    }

    /**
     * @param Config $config
     */
    public function describe(Config $config): string
    {
        $lines = [sprintf('Scope: %s', $this->intent)];
        foreach ($this->entries as $entry) {
            $lines[] = '  ' . self::describeEntry($entry, $config);
        }
        foreach ($this->widenings as $widening) {
            foreach ($widening->entries as $entry) {
                $lines[] = sprintf('  %s (widened by %s: %s)', self::describeEntry($entry, $config), $widening->by, $widening->reason);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param string $entry
     * @param Config $config
     */
    private static function describeEntry(string $entry, Config $config): string
    {
        if (!isset($config->zones[$entry])) {
            return $entry;
        }

        return sprintf('%s = %s', $entry, implode(', ', array_map(static fn (Glob $g): string => $g->pattern, $config->zones[$entry])));
    }

    /**
     * @param list<string> $entries
     * @param Config $config
     *
     * @return list<string>
     */
    private static function validated(array $entries, Config $config): array
    {
        $clean = [];
        foreach ($entries as $entry) {
            $entry = trim(str_replace('\\', '/', $entry));
            if ($entry === '') {
                continue;
            }
            if (isset($config->zones[$entry])) {
                $clean[] = $entry;

                continue;
            }
            if (Path::isAbsolute($entry) || Path::relative('/root', $entry) === null) {
                throw new \InvalidArgumentException(sprintf('"%s" leads outside the project: give paths relative to its root.', $entry));
            }
            if ((new Glob($entry))->coversEverything()) {
                throw new \InvalidArgumentException(sprintf('"%s" covers the whole project, which is no scope at all: name the directories or files of the change.', $entry));
            }
            $clean[] = $entry;
        }

        if ($clean === []) {
            throw new \InvalidArgumentException('Name at least one path, glob or zone.');
        }

        return array_values(array_unique($clean));
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format(\DATE_ATOM);
    }
}
