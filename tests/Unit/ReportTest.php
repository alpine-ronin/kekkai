<?php

declare(strict_types=1);

namespace Kekkai\Tests\Unit;

use Kekkai\Check\CheckResult;
use Kekkai\Check\Report;
use Kekkai\Config\Config;
use Kekkai\Gate\Decision;
use Kekkai\Scope\Scope;
use Kekkai\Tests\Support\Json;
use Kekkai\Verdict;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    public function testOutsideTheScopeFailsOnlyWhenDenied(): void
    {
        $outside = new Decision(Verdict::Deny, Decision::OUTSIDE_SCOPE, 'src/B.php', '');
        $deny = new Report(['src/B.php'], [$outside], [], null, new Config(), 'HEAD', [$outside], '/app');
        self::assertFalse($deny->passed());
        self::assertStringContainsString('✗ Outside the scope:', $deny->toText());

        $asked = new Decision(Verdict::Ask, Decision::OUTSIDE_SCOPE, 'src/B.php', '');
        $ask = new Report(['src/B.php'], [$asked], [], null, Config::fromArray(['outside-scope' => 'ask']), 'HEAD', [$asked], '/app');
        self::assertTrue($ask->passed());
        self::assertStringContainsString('! Outside the scope:', $ask->toText());
    }

    public function testInformationalFindingsDoNotFail(): void
    {
        $findings = [
            new Decision(Verdict::Ask, Decision::PROTECTED, 'composer.lock', ''),
            new Decision(Verdict::Deny, Decision::GUARDED, 'kekkai.yaml', ''),
            new Decision(Verdict::Ask, Decision::NO_SCOPE, 'src/A.php', ''),
        ];
        $report = new Report(['composer.lock', 'kekkai.yaml', 'src/A.php'], $findings, [], null, new Config(), 'main', $findings, '/app');

        self::assertTrue($report->passed());
        $text = $report->toText();
        self::assertStringContainsString('3 file(s) changed since main. PASSED', $text);
        self::assertStringContainsString('Scope: none declared', $text);
        self::assertStringContainsString('! Protected files touched', $text);
        self::assertStringContainsString('! Kekkai files changed', $text);
        self::assertStringContainsString('! Changed without a declared scope', $text);
    }

    public function testChecksAndTheirTail(): void
    {
        $output = implode("\n", array_map(static fn (int $i): string => 'line ' . $i, range(1, 50)));
        $report = new Report(
            ['src/A.php'],
            [],
            [new CheckResult('ok', 'true', true, ''), new CheckResult('tests', 'phpunit', false, $output), new CheckResult('slow', 'sleep 9', false, '', true)],
            Scope::declare('A', ['src/'], 'agent', new Config())->widen(['tests/'], 'Tests too', 'agent', new Config()),
            new Config(),
            'HEAD',
            [new Decision(Verdict::Allow, Decision::IN_SCOPE, 'src/A.php', '', 'src/')],
            '/app',
        );

        self::assertFalse($report->passed());
        $text = $report->toText();
        self::assertStringContainsString('✓ check ok', $text);
        self::assertStringContainsString('✗ check tests', $text);
        self::assertStringContainsString('[... 20 lines cut]', $text);
        self::assertStringContainsString('    line 50', $text);
        self::assertStringNotContainsString('line 20' . "\n", $text);
        self::assertStringContainsString('✗ check slow (timed out)', $text);
        self::assertStringContainsString('Scope widened 1 time(s)', $text);

        $array = $report->toArray();
        self::assertFalse($array['passed']);
        self::assertIsArray($array['checks']);
        self::assertCount(3, $array['checks']);
    }

    public function testEachFileIsListedBeforeTheSummary(): void
    {
        $outside = new Decision(Verdict::Deny, Decision::OUTSIDE_SCOPE, 'src/B.php', '');
        $protected = new Decision(Verdict::Ask, Decision::PROTECTED, 'composer.lock', '', 'composer.lock');
        $decisions = [
            new Decision(Verdict::Allow, Decision::IN_SCOPE, 'src/Catalog/A.php', '', 'catalog'),
            $outside,
            $protected,
            new Decision(Verdict::Allow, Decision::IN_SCOPE, 'src/Catalog/C.php', '', 'catalog'),
        ];
        $report = new Report(
            ['composer.lock', 'src/B.php', 'src/Catalog/A.php', 'src/Catalog/C.php'],
            [$outside, $protected],
            [new CheckResult('tests', 'phpunit', false, 'FAIL in src/Catalog/C.php')],
            null,
            new Config(),
            'HEAD',
            $decisions,
            '/app',
        );

        $lines = explode("\n", $report->toText());
        self::assertSame([
            '✓ src/Catalog/A.php: in scope (catalog)',
            '✗ src/B.php: out of scope',
            '! composer.lock: protected (composer.lock)',
            '✗ src/Catalog/C.php: in scope (catalog); check tests failed',
            'Kekkai: 4 file(s) changed since HEAD. FAILED',
        ], \array_slice($lines, 0, 5));

        $verdicts = $report->toArray()['verdicts'];
        self::assertIsArray($verdicts);
        self::assertSame(['path' => 'src/Catalog/C.php', 'rule' => 'in-scope', 'verdict' => 'allow', 'matchedBy' => 'catalog', 'failedChecks' => ['tests']], $verdicts[3]);
        self::assertSame(['path' => 'src/B.php', 'rule' => 'outside-scope', 'verdict' => 'deny', 'matchedBy' => null, 'failedChecks' => []], $verdicts[1]);
    }

    public function testAFailingCheckNamesAFileOnlyByItsWholePath(): void
    {
        $files = ['src/A.php', 'src/A.php.bak', 'src/B.php', 'src/C.php', 'tests/src/A.php'];
        $decisions = array_map(static fn (string $file): Decision => new Decision(Verdict::Allow, Decision::IN_SCOPE, $file, '', 'src/'), $files);
        $output = "FAIL tests/src/A.php:10\nFAIL src/A.php.bak:1\nFAIL /app/src/B.php:3\nFAIL tests/src/C.php:4\nFAIL /other/src/C.php:5";
        $report = new Report($files, [], [new CheckResult('tests', 'phpunit', false, $output)], null, new Config(), 'HEAD', $decisions, '/app');

        $verdicts = $report->toArray()['verdicts'];
        self::assertIsArray($verdicts);
        self::assertSame([[], ['tests'], ['tests'], [], ['tests']], array_map(static fn (mixed $verdict): mixed => Json::path($verdict, 'failedChecks'), $verdicts));
        self::assertStringContainsString("\n✓ src/C.php: in scope (src/)\n", $report->toText());
        self::assertStringContainsString("\n✗ src/A.php.bak: in scope (src/); check tests failed\n", $report->toText());
    }

    public function testNoChange(): void
    {
        self::assertSame('Kekkai: no change since HEAD.', (new Report([], [], [], null, new Config(), 'HEAD', [], '/app'))->toText());
    }
}
