<?php

declare(strict_types=1);

namespace Kekkai\Tests\Unit;

use Kekkai\Command\InitCommand;
use Kekkai\Config\CheckTrigger;
use Kekkai\Config\Config;
use Kekkai\Verdict;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::fromArray([]);

        self::assertSame(Verdict::Ask, $config->withoutScope);
        self::assertSame(Verdict::Deny, $config->outsideScope);
        self::assertTrue($config->allowWiden);
        self::assertSame([], $config->checks);
    }

    public function testTemplateIsValid(): void
    {
        $data = Yaml::parse(InitCommand::TEMPLATE);
        self::assertIsArray($data);

        $config = Config::fromArray($data);

        self::assertNotSame([], $config->protected);
        self::assertSame(Verdict::Deny, $config->outsideScope);
    }

    public function testFull(): void
    {
        $config = Config::fromArray([
            'zones' => ['catalog' => ['src/Catalog/', 'tests/Catalog/'], 'shared' => 'src/Shared'],
            'protected' => ['composer.lock'],
            'without-scope' => 'deny',
            'outside-scope' => 'ask',
            'allow-widen' => false,
            'checks' => [['name' => 'arch', 'run' => 'phpgraph check', 'timeout' => 30]],
        ]);

        self::assertCount(2, $config->zones['catalog']);
        self::assertCount(1, $config->zones['shared']);
        self::assertSame(Verdict::Deny, $config->withoutScope);
        self::assertSame(Verdict::Ask, $config->outsideScope);
        self::assertFalse($config->allowWiden);
        self::assertSame('phpgraph check', $config->checks[0]->run);
        self::assertSame(30, $config->checks[0]->timeout);
    }

    public function testUnknownKeyIsRefused(): void
    {
        $this->expectExceptionMessage('Unknown key(s) in the configuration: protect');

        Config::fromArray(['protect' => ['a']]);
    }

    public function testOutsideScopeCannotBeAllow(): void
    {
        $this->expectExceptionMessage('outside-scope must be one of: ask, deny.');

        Config::fromArray(['outside-scope' => 'allow']);
    }

    public function testCheckNeedsACommand(): void
    {
        $this->expectExceptionMessage('checks[0] needs a "run" command.');

        Config::fromArray(['checks' => [['name' => 'x']]]);
    }

    public function testCheckRetry(): void
    {
        $config = Config::fromArray(['checks' => [['run' => 'a'], ['run' => 'b', 'retry' => 5]]]);

        self::assertSame(0, $config->checks[0]->retry);
        self::assertSame(5, $config->checks[1]->retry);
    }

    public function testCheckRetryIsCapped(): void
    {
        $this->expectExceptionMessage('checks[0]: "retry" must be a whole number from 0 to 5.');

        Config::fromArray(['checks' => [['run' => 'a', 'retry' => 6]]]);
    }

    public function testCheckOn(): void
    {
        $config = Config::fromArray(['checks' => [['run' => 'a'], ['run' => 'b', 'on' => ['ci']], ['run' => 'c', 'on' => 'stop']]]);

        self::assertSame([CheckTrigger::Stop, CheckTrigger::Ci], $config->checks[0]->on);
        self::assertTrue($config->checks[1]->runsOn(CheckTrigger::Ci));
        self::assertFalse($config->checks[1]->runsOn(CheckTrigger::Stop));
        self::assertSame([CheckTrigger::Stop], $config->checks[2]->on);
    }

    public function testCheckOnRefusesAnUnknownTrigger(): void
    {
        $this->expectExceptionMessage('checks[0]: "on" must be a non-empty list of: stop, ci.');

        Config::fromArray(['checks' => [['run' => 'a', 'on' => ['push']]]]);
    }

    public function testGuardedPaths(): void
    {
        $config = new Config();

        self::assertTrue($config->isGuarded('.kekkai/scope.json'));
        self::assertTrue($config->isGuarded('kekkai.yaml'));
        self::assertTrue($config->isGuarded('.git/config'));
        self::assertFalse($config->isGuarded('src/kekkai.yaml'));
    }
}
