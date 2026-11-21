<?php

declare(strict_types=1);

namespace Kekkai\Scope;

final readonly class ScopeStore
{
    public const DIRECTORY = '.kekkai';

    /**
     * @param string $root
     */
    public function __construct(private string $root)
    {
    }

    public function load(): ?Scope
    {
        $file = $this->file();
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return \is_array($data) ? Scope::fromArray($data) : null;
    }

    /**
     * @param Scope $scope
     */
    public function save(Scope $scope): void
    {
        $this->write('scope.json', json_encode($scope->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");
    }

    public function clear(): bool
    {
        $stopFile = $this->directory() . '/stop.json';
        if (is_file($stopFile)) {
            unlink($stopFile);
        }
        $file = $this->file();

        return is_file($file) && unlink($file);
    }

    /**
     * How many times in a row the stop hook has sent the agent of this session back, or null when not recorded.
     */
    public function sentBack(string $session): ?int
    {
        return $this->sentBackBySession()[$session] ?? null;
    }

    public function saveSentBack(string $session, int $count): void
    {
        $counts = $this->sentBackBySession();
        unset($counts[$session]);
        $counts[$session] = $count;
        $this->write('stop.json', json_encode(['sentBack' => $counts], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * @return array<int|string, int> the send-backs of each session since the scope was declared
     */
    private function sentBackBySession(): array
    {
        $file = $this->directory() . '/stop.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        $counts = \is_array($data) && \is_array($data['sentBack'] ?? null) ? $data['sentBack'] : [];

        return array_filter($counts, 'is_int');
    }

    /**
     * @param array<string, mixed> $event
     */
    public function log(array $event): void
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            return;
        }

        $line = json_encode(['at' => (new \DateTimeImmutable())->format(\DATE_ATOM)] + $event, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($line !== false) {
            @file_put_contents($directory . '/log.jsonl', $line . "\n", \FILE_APPEND | \LOCK_EX);
        }
    }

    public function directory(): string
    {
        return rtrim($this->root, '/') . '/' . self::DIRECTORY;
    }

    private function file(): string
    {
        return $this->directory() . '/scope.json';
    }

    /**
     * @param string $name
     * @param string $content
     */
    private function write(string $name, string $content): void
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $directory));
        }
        if (!is_file($directory . '/.gitignore')) {
            file_put_contents($directory . '/.gitignore', "*\n");
        }

        $temporary = $directory . '/' . $name . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temporary, $content, \LOCK_EX);
        rename($temporary, $directory . '/' . $name);
    }
}
