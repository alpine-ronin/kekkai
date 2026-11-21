<?php

declare(strict_types=1);

namespace Kekkai\Tests\Unit;

use Kekkai\Config\Config;
use Kekkai\Gate\Decision;
use Kekkai\Gate\Gate;
use Kekkai\Scope\Scope;
use Kekkai\Verdict;
use PHPUnit\Framework\TestCase;

final class GateTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = Config::fromArray([
            'zones' => ['catalog' => ['src/Catalog/']],
            'protected' => ['composer.lock', 'src/Catalog/Migrations/'],
            'always-allowed' => ['CHANGELOG.md'],
        ]);
    }

    public function testOrderOfTheRules(): void
    {
        $gate = new Gate('/p', $this->config, Scope::declare('Prices', ['catalog'], 'agent', $this->config));

        self::assertSame(Decision::IN_SCOPE, $gate->decide('/p/src/Catalog/Product.php')->rule);
        self::assertSame(Verdict::Allow, $gate->decide('/p/src/Catalog/Product.php')->verdict);
        self::assertSame(Decision::PROTECTED, $gate->decide('/p/src/Catalog/Migrations/V1.php')->rule);
        self::assertSame(Verdict::Ask, $gate->decide('/p/composer.lock')->verdict);
        self::assertSame(Decision::ALWAYS_ALLOWED, $gate->decide('/p/CHANGELOG.md')->rule);
        self::assertSame(Decision::GUARDED, $gate->decide('/p/.kekkai/scope.json')->rule);
        self::assertSame(Verdict::Deny, $gate->decide('/p/kekkai.yaml')->verdict);
        self::assertSame(Decision::OUTSIDE_PROJECT, $gate->decide('/etc/hosts')->rule);
    }

    public function testNamesWhatDecided(): void
    {
        $gate = new Gate('/p', $this->config, Scope::declare('Prices', ['catalog', 'tests/'], 'agent', $this->config));

        self::assertSame('catalog', $gate->decide('/p/src/Catalog/Product.php')->matchedBy);
        self::assertSame('tests/', $gate->decide('/p/tests/ProductTest.php')->matchedBy);
        self::assertSame('src/Catalog/Migrations/', $gate->decide('/p/src/Catalog/Migrations/V1.php')->matchedBy);
        self::assertSame('CHANGELOG.md', $gate->decide('/p/CHANGELOG.md')->matchedBy);
        self::assertNull($gate->decide('/p/src/Billing/Invoice.php')->matchedBy);
    }

    public function testOutsideTheScope(): void
    {
        $gate = new Gate('/p', $this->config, Scope::declare('Prices', ['catalog'], 'agent', $this->config));
        $decision = $gate->decide('/p/src/Billing/Invoice.php');

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame(Decision::OUTSIDE_SCOPE, $decision->rule);
        self::assertStringContainsString('src/Billing/Invoice.php is outside the declared scope', $decision->reason);
        self::assertStringContainsString('catalog = src/Catalog/', $decision->reason);
        self::assertStringContainsString('widen_scope', $decision->reason);
    }

    public function testWithoutScopeNamesTheZones(): void
    {
        $decision = (new Gate('/p', $this->config, null))->decide('src/Catalog/Product.php');

        self::assertSame(Verdict::Ask, $decision->verdict);
        self::assertStringContainsString('declare_scope', $decision->reason);
        self::assertStringContainsString('zones: catalog', $decision->reason);
    }

    public function testWideningReservedToTheUser(): void
    {
        $config = Config::fromArray(['allow-widen' => false, 'outside-scope' => 'ask']);
        $decision = (new Gate('/p', $config, Scope::declare('x', ['src/A/'], 'user', $config)))->decide('src/B/C.php');

        self::assertSame(Verdict::Ask, $decision->verdict);
        self::assertStringContainsString('kekkai scope widen', $decision->reason);
    }
}
