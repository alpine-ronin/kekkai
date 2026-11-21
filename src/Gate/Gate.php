<?php

declare(strict_types=1);

namespace Kekkai\Gate;

use Kekkai\Config\Config;
use Kekkai\Path;
use Kekkai\Scope\Scope;
use Kekkai\Verdict;

final readonly class Gate
{
    /**
     * @param string $root
     * @param Config $config
     * @param Scope|null $scope
     */
    public function __construct(
        private string $root,
        private Config $config,
        private ?Scope $scope,
    ) {
    }

    /**
     * @param string $path
     */
    public function decide(string $path): Decision
    {
        $relative = Path::relative($this->root, $path);
        if ($relative === null && Path::isAbsolute($path)) {
            $relative = Path::relative($this->root, Path::resolve($path));
        }
        if ($relative === null || $relative === '' || str_starts_with($relative, '../')) {
            return new Decision(Verdict::Ask, Decision::OUTSIDE_PROJECT, $path, sprintf('%s is outside the project %s.', $path, $this->root));
        }

        if ($this->config->isGuarded($relative)) {
            return new Decision(Verdict::Deny, Decision::GUARDED, $relative, sprintf('%s belongs to Kekkai or git and is never edited by the agent. The user changes the scope with `kekkai scope`.', $relative));
        }

        $protected = Config::firstMatch($this->config->protected, $relative);
        if ($protected !== null) {
            return new Decision(Verdict::Ask, Decision::PROTECTED, $relative, sprintf('%s is protected (%s in %s): the user must approve this edit.', $relative, $protected->pattern, $this->config->file ?? 'kekkai.yaml'), $protected->pattern);
        }

        $alwaysAllowed = Config::firstMatch($this->config->alwaysAllowed, $relative);
        if ($alwaysAllowed !== null) {
            return new Decision(Verdict::Allow, Decision::ALWAYS_ALLOWED, $relative, sprintf('%s is always allowed.', $relative), $alwaysAllowed->pattern);
        }

        if ($this->scope === null) {
            return new Decision($this->config->withoutScope, Decision::NO_SCOPE, $relative, sprintf(
                'No scope is declared. Before editing, call the Kekkai tool declare_scope with what the change is for and the paths or zones it needs%s.',
                $this->config->zones === [] ? '' : sprintf(' (zones: %s)', implode(', ', array_keys($this->config->zones))),
            ));
        }

        $coveringEntry = $this->scope->coveringEntry($relative, $this->config);
        if ($coveringEntry !== null) {
            return new Decision(Verdict::Allow, Decision::IN_SCOPE, $relative, sprintf('%s is in the scope.', $relative), $coveringEntry);
        }

        $how = $this->config->allowWiden
            ? 'If the change truly needs it, call widen_scope with this path and the reason; otherwise keep to the scope.'
            : 'Widening is reserved to the user here: stop and ask them, they run `kekkai scope widen`.';

        return new Decision($this->config->outsideScope, Decision::OUTSIDE_SCOPE, $relative, sprintf("%s is outside the declared scope.\n%s\n%s", $relative, $this->scope->describe($this->config), $how));
    }
}
