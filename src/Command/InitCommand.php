<?php

declare(strict_types=1);

namespace Kekkai\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'init', description: 'Write a kekkai.yaml to start from')]
final class InitCommand extends Command
{
    use RootOption;

    public const TEMPLATE = <<<'YAML'
        # Kekkai: https://github.com/alpine-ronin/kekkai

        # Named sets of paths the agent can put in its scope (one per bounded context, for instance).
        zones:
          # catalog: ['src/Catalog/', 'tests/Catalog/']
          # shared: ['src/Shared/']

        # Edits the user must approve, whatever the scope.
        protected:
          - composer.json
          - composer.lock
          - '.env*'
          - 'config/packages/prod/'
          - 'migrations/'
          - '.github/'

        # Edits allowed whatever the scope.
        always-allowed:
          - CHANGELOG.md

        # Edit with no scope declared: allow, ask or deny.
        without-scope: ask

        # Edit outside the declared scope: ask (the user decides) or deny (the agent must widen the scope or stay inside).
        outside-scope: deny

        # May the agent widen its own scope (with a reason shown to the reviewer)?
        allow-widen: true

        # Run on the changed files by `kekkai check` and before the agent stops. A failing check sends the agent back to work.
        # $KEKKAI_CHANGED_FILES holds the changed files, one per line.
        checks:
          # - name: architecture
          #   run: phpgraph check
          # - name: tests
          #   run: vendor/bin/phpunit
          #   timeout: 600
          #   retry: 1         # send the agent back once more if it still fails (0 to 5, default 0)
          #   on: [stop, ci]   # [ci]: only in `kekkai check`, not when the agent stops

        YAML;

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
                Writes a commented kekkai.yaml at the project root: zones, protected files, the policy, the checks.
                Leaves an existing one as it is.

                  <info>kekkai init</info>
                  <info>kekkai init --root ../shop</info>

                Then: <comment>kekkai install <agent> --write</comment> (claude or cursor), and <comment>kekkai doctor</comment> to check the setup.
                HELP);
        $this->addRootOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->project($input);
        $file = $project->root . '/kekkai.yaml';
        if (is_file($file)) {
            $output->writeln('<comment>kekkai.yaml already exists: left as it is.</comment>');

            return Command::SUCCESS;
        }

        file_put_contents($file, self::TEMPLATE);
        $output->writeln('kekkai.yaml written. Next: <info>kekkai install claude</info> or <info>kekkai install cursor</info> to connect your agent.');

        return Command::SUCCESS;
    }
}
