<?php

declare(strict_types=1);

namespace Kekkai\Command;

use Kekkai\Hook\Agent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'hook', description: 'Agent hooks (JSON on stdin): pre-tool-use, stop')]
final class HookCommand extends Command
{
    /** @var resource|null */
    private $stdin;

    /**
     * @param resource|null $stdin
     */
    public function __construct($stdin = null)
    {
        $this->stdin = $stdin;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
                The agent hooks, written by <comment>kekkai install <agent> --write</comment>. They read the hook's JSON on stdin
                and print the agent's answer, or nothing. --agent says whose format: claude (default) or cursor.

                  <info>pre-tool-use</info>   before a file is edited: refuses an edit outside the scope, asks for a protected file,
                                 never answers allow (the agent's own permissions then apply)
                  <info>stop</info>           reviews the diff and runs the checks; when they fail, sends the agent back once (more with a check's retry)
                HELP);
        $this
            ->addArgument('event', InputArgument::REQUIRED, 'pre-tool-use or stop')
            ->addOption('agent', 'a', InputOption::VALUE_REQUIRED, 'Hook format: ' . implode(', ', Agent::values()), Agent::Claude->value)
            ->addOption('no-checks', null, InputOption::VALUE_NONE, 'stop: only the scope, without the checks')
            ->addOption('root', 'r', InputOption::VALUE_REQUIRED, 'Project root (default: the project the agent reports, else the cwd of the hook)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('agent');
        $agent = \is_string($name) ? Agent::tryFrom($name) : null;
        if ($agent === null) {
            $output->writeln(sprintf('<error>Unknown agent: use %s.</error>', implode(' or ', Agent::values())));

            return Command::INVALID;
        }

        $raw = stream_get_contents($this->stdin ?? \STDIN);
        $data = json_decode(\is_string($raw) ? $raw : '', true);
        $data = \is_array($data) ? $data : [];
        $root = $input->getOption('root');
        $root = \is_string($root) ? $root : null;

        $response = match ($input->getArgument('event')) {
            Agent::PRE_TOOL_USE => $agent->preToolUse($data, $root),
            Agent::STOP => $agent->stop($data, $root, !$input->getOption('no-checks')),
            default => false,
        };

        if ($response === false) {
            $output->writeln('<error>Unknown event: use pre-tool-use or stop.</error>');

            return Command::INVALID;
        }

        if ($response !== null) {
            $output->writeln(json_encode($response, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
}
