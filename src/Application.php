<?php

declare(strict_types=1);

namespace Kekkai;

use Kekkai\Command\CheckCommand;
use Kekkai\Command\DoctorCommand;
use Kekkai\Command\HookCommand;
use Kekkai\Command\InitCommand;
use Kekkai\Command\InstallCommand;
use Kekkai\Command\ScopeCommand;
use Kekkai\Command\SelfUpdateCommand;
use Kekkai\Command\ServeCommand;
use Symfony\Component\Console\Application as Console;

final class Application extends Console
{
    public function __construct()
    {
        parent::__construct('kekkai', Version::get());

        $this->addCommands([
            new InitCommand(),
            new InstallCommand(),
            new DoctorCommand(),
            new ScopeCommand(),
            new CheckCommand(),
            new HookCommand(),
            new ServeCommand(),
            new SelfUpdateCommand(),
        ]);
    }

    public function getHelp(): string
    {
        return sprintf(<<<'HELP'
            <info>kekkai</info> %s  makes an AI coding agent's diff reviewable

            Start in a project:  <comment>kekkai init</comment>, then <comment>kekkai install claude --write</comment> (or cursor), then <comment>kekkai doctor</comment>
            During a task:       edits outside the declared scope are refused, the diff is reviewed at stop
            Yourself:            <comment>kekkai scope</comment>, <comment>kekkai check</comment>, <comment>kekkai scope clear</comment> when the task is done
            Docs:                https://github.com/alpine-ronin/kekkai
            HELP, $this->getVersion());
    }
}
