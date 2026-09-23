<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest;

use Phpcq\ComposerDependencyAnalyserPluginTest\Support\PluginTestTrait;
use Phpcq\ComposerDependencyAnalyserPluginTest\Support\RecordingTaskReport;
use Phpcq\PluginApi\Version10\Output\OutputInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OutputTransformerTest extends TestCase
{
    use PluginTestTrait;

    private const PROJECT_ROOT = __DIR__ . '/Fixtures/project';

    private const PROJECT_WITH_CONFIG_ROOT = __DIR__ . '/Fixtures/project-with-config';

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
            "\e[37mUsing config\e[0m /project/composer-dependency-analyser.php\n"
        );

        self::assertSame([], $report->diagnostics);
        self::assertSame([], $report->attachments);
        self::assertSame([TaskReportInterface::STATUS_PASSED], $report->closedWith);
    }

    /**
     * Real stderr output of composer-dependency-analyser 1.8.4 (paths shortened to /project).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideToolErrors(): iterable
    {
        yield 'unknown option' => [
            "\n\e[31mUnknown option --foo, see --help\e[0m\n\n",
            'Unknown option --foo, see --help',
        ];
        yield 'missing config file' => [
            "\n\e[31mInvalid config path given, /project/nope.php is not a file.\e[0m\n\n",
            'Invalid config path given, /project/nope.php is not a file.',
        ];
        yield 'broken config file' => [
            "\e[37mUsing config\e[0m /project/broken.php\n\n\e[31mError while loading configuration from"
            . " '/project/broken.php':\n\nParseError in /project/broken.php:1\n > syntax error, unexpected"
            . " identifier \"error\"\e[0m\n\n",
            "Error while loading configuration from '/project/broken.php':\nParseError in /project/broken.php:1\n"
            . ' > syntax error, unexpected identifier "error"',
        ];
        yield 'missing composer.json' => [
            "\n\e[31mFile composer.json not found, '/project/composer.json' is not a file.\e[0m\n\n",
            "File composer.json not found, '/project/composer.json' is not a file.",
        ];
        yield 'missing autoloader' => [
            "\n\e[31mCannot find composer's autoload file, expected at '/project/vendor/autoload.php'\e[0m\n\n",
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
        self::assertStringNotContainsString("\e", $report->attachments['error.log']['content']);
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

    public function testAbsolutePathsBelowProjectRootBecomeRelative(): void
    {
        $report = $this->transform($this->suite('unknown classes', 'Foo', [
            [null, self::PROJECT_ROOT . '/src/Foo.php:3'],
            [null, '/elsewhere/src/Bar.php:4'],
        ]), 1);

        self::assertSame(
            [
                ['file' => 'composer.json', 'line' => null],
                ['file' => 'src/Foo.php', 'line' => 3],
                ['file' => '/elsewhere/src/Bar.php', 'line' => 4],
            ],
            $report->diagnostics[0]->files
        );
    }

    public function testProjectRootWithTrailingSlashIsHandled(): void
    {
        $report = $this->transform(
            $this->suite('unknown classes', 'Foo', [[null, self::PROJECT_ROOT . '/src/Foo.php:3']]),
            1,
            '',
            [],
            self::PROJECT_ROOT . '/'
        );

        self::assertSame(['file' => 'src/Foo.php', 'line' => 3], $report->diagnostics[0]->files[1]);
    }

    public function testDuplicateLocationsAreReportedOnce(): void
    {
        $report = $this->transform($this->suite('shadow dependencies', 'acme/pkg', [
            ['Acme\A', 'src/Foo.php:3'],
            ['Acme\B', 'src/Foo.php:3'],
            ['Acme\A', 'src/Foo.php:5'],
        ]), 1);

        self::assertSame(
            'Shadow dependency "acme/pkg" is used but not listed in composer.json (used symbols: "Acme\A", "Acme\B").',
            $report->diagnostics[0]->message
        );
        self::assertSame(
            [
                ['file' => 'composer.json', 'line' => null],
                ['file' => 'src/Foo.php', 'line' => 3],
                ['file' => 'src/Foo.php', 'line' => 5],
            ],
            $report->diagnostics[0]->files
        );
    }

    public function testUnparsableLocationsAreSkipped(): void
    {
        $report = $this->transform($this->suite('unknown classes', 'Foo', [[null, 'somewhere']]), 1);

        self::assertSame([['file' => 'composer.json', 'line' => null]], $report->diagnostics[0]->files);
    }

    public function testComposerLineLookupMatchesKeysOnly(): void
    {
        // "psr/log" appears as a value in line 3 of the fixture; the key is in line 8.
        $report = $this->transform($this->suite('unused dependencies', 'psr/log', []), 1);

        self::assertSame([['file' => 'composer.json', 'line' => 8]], $report->diagnostics[0]->files);
    }

    public function testComposerLineLookupEscapesPackageName(): void
    {
        // Unescaped, "acme/dot.pkg" would match the key "acme/dotXpkg" in line 9.
        $report = $this->transform($this->suite('unused dependencies', 'acme/dot.pkg', []), 1);

        self::assertSame([['file' => 'composer.json', 'line' => null]], $report->diagnostics[0]->files);
    }

    public function testMissingComposerJsonReportsWithoutLine(): void
    {
        $report = $this->transform(
            $this->suite('unused dependencies', 'psr/log', []),
            1,
            '',
            ['composer_json' => 'does-not-exist.json']
        );

        self::assertSame([['file' => 'does-not-exist.json', 'line' => null]], $report->diagnostics[0]->files);
    }

    public function testAbsoluteComposerJsonBelowProjectRootIsReportedRelative(): void
    {
        $report = $this->transform(
            $this->suite('unused dependencies', 'psr/log', []),
            1,
            '',
            ['composer_json' => self::PROJECT_ROOT . '/composer.json']
        );

        self::assertSame([['file' => 'composer.json', 'line' => 8]], $report->diagnostics[0]->files);
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

    /**
     * Builds a single-suite JUnit document.
     *
     * @param list<array{0: ?string, 1: string}> $failures [message attribute, text content]
     */
    private function suite(string $suite, string $testCase, array $failures): string
    {
        $document  = new \DOMDocument('1.0', 'UTF-8');
        $root      = $document->createElement('testsuites');
        $suiteNode = $document->createElement('testsuite');
        $caseNode  = $document->createElement('testcase');
        $document->appendChild($root);
        $root->appendChild($suiteNode);
        $suiteNode->appendChild($caseNode);
        $suiteNode->setAttribute('name', $suite);
        $caseNode->setAttribute('name', $testCase);
        foreach ($failures as [$message, $text]) {
            $failure = $document->createElement('failure');
            $failure->appendChild($document->createTextNode($text));
            if (null !== $message) {
                $failure->setAttribute('message', $message);
            }
            $caseNode->appendChild($failure);
        }

        return (string) $document->saveXML();
    }

    /** @return list<array<string, mixed>> */
    private function export(RecordingTaskReport $report): array
    {
        return array_map(
            static fn ($diagnostic): array => [
                'severity' => $diagnostic->severity,
                'message'  => $diagnostic->message,
                'source'   => $diagnostic->source,
                'class'    => $diagnostic->className,
                'files'    => $diagnostic->files,
            ],
            $report->diagnostics
        );
    }

    /**
     * @param array<string, string|bool|null> $config
     */
    private function transform(
        string $stdout,
        int $exitCode,
        string $stderr = '',
        array $config = [],
        string $projectRoot = self::PROJECT_ROOT
    ): RecordingTaskReport {
        $captured = $this->runCreateDiagnosticTasks($this->createConfig($config), $projectRoot);
        self::assertNotNull($captured->transformerFactory);

        $report      = new RecordingTaskReport();
        $transformer = $captured->transformerFactory->createFor($report);
        if ('' !== $stdout) {
            $transformer->write($stdout, OutputInterface::CHANNEL_STDOUT);
        }
        if ('' !== $stderr) {
            $transformer->write($stderr, OutputInterface::CHANNEL_STDERR);
        }
        $transformer->finish($exitCode);

        return $report;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/Fixtures/' . $name);
    }
}
