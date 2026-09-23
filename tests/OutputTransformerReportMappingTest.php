<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest;

use Phpcq\ComposerDependencyAnalyserPluginTest\Support\OutputTransformerTestTrait;
use Phpcq\ComposerDependencyAnalyserPluginTest\Support\RecordingTaskReport;
use Phpcq\PluginApi\Version10\Output\OutputInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OutputTransformerReportMappingTest extends TestCase
{
    use OutputTransformerTestTrait;

    private const PROJECT_ROOT = __DIR__ . '/Fixtures/project';

    private const PROJECT_WITH_CONFIG_ROOT = __DIR__ . '/Fixtures/project-with-config';

    /** @return iterable<string, array{int, array<string, mixed>}> */
    public static function provideRealReportDiagnostics(): iterable
    {
        yield 'unknown class' => [0, [
            'severity' => 'major',
            'message'  => 'Unknown class "Unknown\Thing" (unable to autoload it, so it cannot be checked).',
            'source'   => 'unknown-class',
            'class'    => 'Unknown\Thing',
            'files'    => [
                ['file' => 'composer.json', 'line' => null],
                ['file' => 'src/Service.php', 'line' => 13],
            ],
        ]];
        yield 'unknown function with two usages' => [1, [
            'severity' => 'major',
            'message'  => 'Unknown function "unknown_function_xyz" (unable to autoload it, so it cannot be checked).',
            'source'   => 'unknown-function',
            'class'    => null,
            'files'    => [
                ['file' => 'composer.json', 'line' => null],
                ['file' => 'src/Other.php', 'line' => 8],
                ['file' => 'src/Other.php', 'line' => 9],
            ],
        ]];
        yield 'shadow extension ext-dom' => [2, [
            'severity' => 'major',
            'message'  => 'Shadow dependency "ext-dom" is used but not listed in composer.json'
                . ' (used symbols: "DOMDocument").',
            'source'   => 'shadow-dependency',
            'class'    => null,
            'files'    => [
                ['file' => 'composer.json', 'line' => null],
                ['file' => 'src/Other.php', 'line' => 11],
            ],
        ]];
        yield 'shadow extension ext-mbstring' => [3, [
            'severity' => 'major',
            'message'  => 'Shadow dependency "ext-mbstring" is used but not listed in composer.json'
                . ' (used symbols: "mb_strlen").',
            'source'   => 'shadow-dependency',
            'class'    => null,
            'files'    => [
                ['file' => 'composer.json', 'line' => null],
                ['file' => 'src/Other.php', 'line' => 10],
            ],
        ]];
        yield 'shadow package' => [4, [
            'severity' => 'major',
            'message'  => 'Shadow dependency "psr/http-message" is used but not listed in composer.json'
                . ' (used symbols: "Psr\Http\Message\RequestInterface", "Psr\Http\Message\UriInterface").',
            'source'   => 'shadow-dependency',
            'class'    => null,
            'files'    => [
                ['file' => 'composer.json', 'line' => null],
                ['file' => 'src/Service.php', 'line' => 11],
                ['file' => 'src/Other.php', 'line' => 13],
            ],
        ]];
        yield 'dev dependency in production' => [5, [
            'severity' => 'major',
            'message'  => 'Dev dependency "psr/container" is used in production code, it should probably be moved'
                . ' to "require" (used symbols: "Psr\Container\ContainerInterface").',
            'source'   => 'dev-dependency-in-prod',
            'class'    => null,
            'files'    => [
                ['file' => 'composer.json', 'line' => 12],
                ['file' => 'src/Other.php', 'line' => 12],
                ['file' => 'src/Service.php', 'line' => 9],
            ],
        ]];
        yield 'prod dependency only in dev' => [6, [
            'severity' => 'minor',
            'message'  => 'Prod dependency "psr/clock" is used only in dev paths, it should probably be moved to'
                . ' "require-dev".',
            'source'   => 'prod-dependency-only-in-dev',
            'class'    => null,
            'files'    => [['file' => 'composer.json', 'line' => 6]],
        ]];
        yield 'unused dependency' => [7, [
            'severity' => 'minor',
            'message'  => 'Unused dependency "psr/http-factory" is listed in composer.json, but no usage was found.',
            'source'   => 'unused-dependency',
            'class'    => null,
            'files'    => [['file' => 'composer.json', 'line' => 7]],
        ]];
        yield 'unused ignore of error type' => [8, [
            'severity' => 'minor',
            'message'  => "'shadow-dependency' was ignored for package 'foo/bar', but it was never applied.",
            'source'   => 'unused-ignore',
            'class'    => null,
            'files'    => [['file' => 'composer.json', 'line' => null]],
        ]];
        yield 'unused ignore of unknown class' => [9, [
            'severity' => 'minor',
            'message'  => "Unknown class 'Nope\\Nope' was ignored, but it was never applied.",
            'source'   => 'unused-ignore',
            'class'    => null,
            'files'    => [['file' => 'composer.json', 'line' => null]],
        ]];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('provideRealReportDiagnostics')]
    public function testMapsRealReport(int $index, array $expected): void
    {
        $report = $this->transform($this->fixture('report-all.xml'), 1);

        self::assertSame($expected, $this->export($report)[$index]);
    }

    public function testRealReportProducesOneDiagnosticPerTestCase(): void
    {
        $report = $this->transform($this->fixture('report-all.xml'), 1);

        self::assertCount(10, $report->diagnostics);
        foreach ($report->diagnostics as $diagnostic) {
            self::assertTrue($diagnostic->ended, 'Diagnostic builder was not ended: ' . $diagnostic->message);
        }
        self::assertSame([TaskReportInterface::STATUS_FAILED], $report->closedWith);
        self::assertSame([], $report->attachments);
    }

    public function testChunkedOutputGivesSameResult(): void
    {
        $xml = $this->fixture('report-all.xml');

        $captured = $this->runCreateDiagnosticTasks($this->createConfig(), self::PROJECT_ROOT);
        self::assertNotNull($captured->transformerFactory);
        $chunked     = new RecordingTaskReport();
        $transformer = $captured->transformerFactory->createFor($chunked);
        foreach (str_split($xml, 7) as $chunk) {
            $transformer->write($chunk, OutputInterface::CHANNEL_STDOUT);
            $transformer->write('x', OutputInterface::CHANNEL_STDERR);
        }
        $transformer->finish(1);

        self::assertSame($this->export($this->transform($xml, 1)), $this->export($chunked));
    }

    public function testUnusedIgnoreRefersToConfiguredConfigFile(): void
    {
        $report = $this->transform($this->fixture('report-all.xml'), 1, '', ['config' => 'config/cda.php']);

        self::assertSame(
            [['file' => 'composer.json', 'line' => null], ['file' => 'config/cda.php', 'line' => null]],
            $report->diagnostics[8]->files
        );
    }

    public function testUnusedIgnoreRefersToAutoDetectedConfigFile(): void
    {
        $report = $this->transform($this->fixture('report-all.xml'), 1, '', [], self::PROJECT_WITH_CONFIG_ROOT);

        self::assertSame(
            [
                ['file' => 'composer.json', 'line' => null],
                ['file' => 'composer-dependency-analyser.php', 'line' => null],
            ],
            $report->diagnostics[9]->files
        );
    }

    public function testUnknownSuiteFallsBackToGenericDiagnostic(): void
    {
        $report = $this->transform($this->suite('future errors', 'acme/pkg', [
            ['Foo', 'src/A.php:3'],
            [null, 'free text'],
        ]), 1);

        self::assertSame(
            [[
                'severity' => 'major',
                'message'  => 'future errors: "acme/pkg" - src/A.php:3; free text',
                'source'   => 'future errors',
                'class'    => null,
                'files'    => [
                    ['file' => 'composer.json', 'line' => null],
                    ['file' => 'src/A.php', 'line' => 3],
                ],
            ]],
            $this->export($report)
        );
    }

    public function testIgnoresUnexpectedNodes(): void
    {
        $xml = '<?xml version="1.0"?><testsuites><!-- c --><other/>'
            . '<testsuite name="unused dependencies"><properties/><testcase name="psr/log"/></testsuite>'
            . '</testsuites>';

        $report = $this->transform($xml, 1);

        self::assertCount(1, $report->diagnostics);
        self::assertSame('unused-dependency', $report->diagnostics[0]->source);
    }
}
