<?php

declare(strict_types=1);

namespace Kekkai\Command;

use Kekkai\Config\Config;
use Kekkai\Scope\Scope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'scope', description: 'Show, declare, widen or clear the scope of the current task')]
final class ScopeCommand extends Command
{
    use RootOption;

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
                The scope is what the current task may change: an intent and a list of paths, globs or zones.
                The agent declares it with the MCP tool declare_scope; you can declare it yourself before handing the task over.

                  <info>kekkai scope</info>                                              show it
                  <info>kekkai scope show --format json</info>                           as .kekkai/scope.json: docs/schemas/scope.schema.json
                  <info>kekkai scope declare pricing config/services.yaml -m "Add a price to products"</info>
                  <info>kekkai scope widen src/Shared/Money.php -m "Prices are rounded by Money"</info>
                  <info>kekkai scope clear</info>                                        the task is done

                Declaring replaces the current scope. Widening from here works even with allow-widen: false.
                Patterns: <comment>*</comment> within a directory, <comment>**</comment> across, a path without wildcard is that file or directory.
                HELP);
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'show, declare, widen or clear', 'show')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Paths, globs or zones')
            ->addOption('message', 'm', InputOption::VALUE_REQUIRED, 'The intent (declare) or the reason (widen)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'show: text or json', 'text');
        $this->addRootOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->project($input);
        $config = $project->config;
        $action = $input->getArgument('action');
        $rawPaths = $input->getArgument('paths');
        $paths = \is_array($rawPaths) ? array_values(array_filter($rawPaths, 'is_string')) : [];
        $message = $input->getOption('message');
        $message = \is_string($message) ? $message : '';

        try {
            switch ($action) {
                case 'show':
                    $this->show($project->scope(), $config, $input->getOption('format') === 'json', $output);

                    return Command::SUCCESS;
                case 'declare':
                    $scope = Scope::declare($message, $paths, 'user', $config);
                    $project->store->save($scope);
                    $project->store->log(['event' => 'declare', 'by' => 'user', 'entries' => $scope->entries]);
                    $output->writeln($scope->describe($config));

                    return Command::SUCCESS;
                case 'widen':
                    $current = $project->scope();
                    if ($current === null) {
                        $output->writeln('<error>No scope declared: use `kekkai scope declare`.</error>');

                        return Command::FAILURE;
                    }
                    $scope = $current->widen($paths, $message, 'user', $config);
                    $project->store->save($scope);
                    $project->store->log(['event' => 'widen', 'by' => 'user', 'entries' => $paths]);
                    $output->writeln($scope->describe($config));

                    return Command::SUCCESS;
                case 'clear':
                    $output->writeln($project->store->clear() ? 'Scope cleared: the next task declares its own.' : 'No scope to clear.');
                    $project->store->log(['event' => 'clear', 'by' => 'user']);

                    return Command::SUCCESS;
                default:
                    $output->writeln('<error>Unknown action: use show, declare, widen or clear.</error>');

                    return Command::INVALID;
            }
        } catch (\InvalidArgumentException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }
    }

    private function show(?Scope $scope, Config $config, bool $asJson, OutputInterface $output): void
    {
        if ($asJson) {
            $output->writeln(json_encode($scope?->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return;
        }
        $output->writeln($scope?->describe($config) ?? 'No scope declared.');
    }
}
