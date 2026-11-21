<?php

declare(strict_types=1);

namespace Kekkai\Gate;

use Kekkai\Verdict;

final readonly class Decision
{
    public const GUARDED = 'guarded';
    public const OUTSIDE_PROJECT = 'outside-project';
    public const PROTECTED = 'protected';
    public const ALWAYS_ALLOWED = 'always-allowed';
    public const NO_SCOPE = 'no-scope';
    public const IN_SCOPE = 'in-scope';
    public const OUTSIDE_SCOPE = 'outside-scope';
    public const UNDECIDED = 'undecided';

    /**
     * @param Verdict $verdict
     * @param string $rule
     * @param string $path
     * @param string $reason
     * @param string|null $matchedBy the protected or always-allowed pattern, or the scope entry, that decided
     */
    public function __construct(
        public Verdict $verdict,
        public string $rule,
        public string $path,
        public string $reason,
        public ?string $matchedBy = null,
    ) {
    }
}
