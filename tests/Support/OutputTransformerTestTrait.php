<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest\Support;

use DOMDocument;
use Phpcq\PluginApi\Version10\Output\OutputInterface;

/**
 * Shared helpers for exercising the output transformer produced by createDiagnosticTasks().
 *
 * Classes using this trait must declare `private const PROJECT_ROOT` pointing at a fixture project
 * directory (e.g. `__DIR__ . '/Fixtures/project'`); {@see transform()} relies on it as its default.
 */
trait OutputTransformerTestTrait
{
    use PluginTestTrait;

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
        return (string) file_get_contents(dirname(__DIR__) . '/Fixtures/' . $name);
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
     * Builds a single-suite JUnit document.
     *
     * @param list<array{0: ?string, 1: string}> $failures [message attribute, text content]
     */
    private function suite(string $suite, string $testCase, array $failures): string
    {
        $document  = new DOMDocument('1.0', 'UTF-8');
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
}
