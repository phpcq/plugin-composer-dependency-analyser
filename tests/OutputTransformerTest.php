<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest;

use Phpcq\ComposerDependencyAnalyserPluginTest\Support\OutputTransformerTestTrait;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OutputTransformerTest extends TestCase
{
    use OutputTransformerTestTrait;

    private const PROJECT_ROOT = __DIR__ . '/Fixtures/project';

    public function testEmptyReportWithExitCodeZeroPasses(): void
    {
        $report = $this->transform($this->fixture('report-empty.xml'), 0);

        self::assertSame([], $report->diagnostics);
        self::assertSame([], $report->attachments);
        self::assertSame([TaskReportInterface::STATUS_PASSED], $report->closedWith);
    }

    public function testEmptyReportWithNonZeroExitCodeFails(): void
    {
        $report = $this->transform($this->fixture('report-empty.xml'), 1);

        self::assertSame([], $report->diagnostics);
        self::assertSame([TaskReportInterface::STATUS_FAILED], $report->closedWith);
    }

    public function testNoOutputWithExitCodeZeroPasses(): void
    {
        $report = $this->transform('', 0);

        self::assertSame([], $report->diagnostics);
        self::assertSame([TaskReportInterface::STATUS_PASSED], $report->closedWith);
    }

    public function testStderrIsIgnoredForValidReport(): void
    {
        $report = $this->transform(
            $this->fixture('report-empty.xml'),
            0,
            "Using config /project/composer-dependency-analyser.php\n"
        );

        self::assertSame([], $report->diagnostics);
        self::assertSame([], $report->attachments);
        self::assertSame([TaskReportInterface::STATUS_PASSED], $report->closedWith);
    }

    /**
     * Real stderr output of composer-dependency-analyser 1.8.4 with NO_COLOR set (paths shortened to /project).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideToolErrors(): iterable
    {
        yield 'unknown option' => [
            "\nUnknown option --foo, see --help\n\n",
            'Unknown option --foo, see --help',
        ];
        yield 'missing config file' => [
            "\nInvalid config path given, /project/nope.php is not a file.\n\n",
            'Invalid config path given, /project/nope.php is not a file.',
        ];
        yield 'broken config file' => [
            "Using config /project/broken.php\n\nError while loading configuration from"
            . " '/project/broken.php':\n\nParseError in /project/broken.php:1\n > syntax error, unexpected"
            . " identifier \"error\"\n\n",
            "Error while loading configuration from '/project/broken.php':\nParseError in /project/broken.php:1\n"
            . ' > syntax error, unexpected identifier "error"',
        ];
        yield 'missing composer.json' => [
            "\nFile composer.json not found, '/project/composer.json' is not a file.\n\n",
            "File composer.json not found, '/project/composer.json' is not a file.",
        ];
        yield 'missing autoloader' => [
            "\nCannot find composer's autoload file, expected at '/project/vendor/autoload.php'\n\n",
            "Cannot find composer's autoload file, expected at '/project/vendor/autoload.php'",
        ];
        yield 'no stderr at all' => [
            '',
            'composer-dependency-analyser did not produce a valid report.',
        ];
    }

    #[DataProvider('provideToolErrors')]
    public function testToolErrorIsReportedAsFatalDiagnostic(string $stderr, string $expectedMessage): void
    {
        $report = $this->transform('', 1, $stderr);

        self::assertCount(1, $report->diagnostics);
        self::assertSame(TaskReportInterface::SEVERITY_FATAL, $report->diagnostics[0]->severity);
        self::assertSame($expectedMessage, $report->diagnostics[0]->message);
        self::assertSame([], $report->diagnostics[0]->files);
        self::assertTrue($report->diagnostics[0]->ended);
        self::assertSame([TaskReportInterface::STATUS_FAILED], $report->closedWith);
        self::assertSame(
            [
                'output.log' => ['content' => '', 'mimeType' => 'text/plain'],
                'error.log'  => ['content' => preg_replace('/\e\[[0-9;]*m/', '', $stderr), 'mimeType' => 'text/plain'],
            ],
            $report->attachments
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function provideInvalidReports(): iterable
    {
        yield 'truncated xml' => ['<?xml version="1.0"?><testsuites><testsuite name="unused dependencies">', 255];
        yield 'not xml at all' => ['PHP Fatal error:  Allowed memory size exhausted', 255];
        yield 'garbage with exit code zero' => ['garbage', 0];
        yield 'wrong root element' => ['<?xml version="1.0"?><checkstyle/>', 1];
    }

    #[DataProvider('provideInvalidReports')]
    public function testInvalidReportIsReportedAsFatalDiagnostic(string $stdout, int $exitCode): void
    {
        $report = $this->transform($stdout, $exitCode, "some error\n");

        self::assertCount(1, $report->diagnostics);
        self::assertSame(TaskReportInterface::SEVERITY_FATAL, $report->diagnostics[0]->severity);
        self::assertSame('some error', $report->diagnostics[0]->message);
        self::assertSame($stdout, $report->attachments['output.log']['content']);
        self::assertSame("some error\n", $report->attachments['error.log']['content']);
        self::assertSame([TaskReportInterface::STATUS_FAILED], $report->closedWith);
    }

    public function testInvalidXmlDoesNotLeakLibxmlState(): void
    {
        libxml_use_internal_errors(false);

        $this->transform('<testsuites><broken', 1);

        self::assertFalse(libxml_use_internal_errors());
        self::assertFalse(libxml_get_last_error());
    }
}
