<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest\Support;

use Phpcq\PluginApi\Version10\Report\DiagnosticBuilderInterface;
use Phpcq\PluginApi\Version10\Report\FileDiagnosticBuilderInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;

final class RecordingDiagnosticBuilder implements DiagnosticBuilderInterface
{
    public function __construct(
        private readonly TaskReportInterface $report,
        private readonly RecordedDiagnostic $diagnostic
    ) {
    }

    public function forFile(string $file): FileDiagnosticBuilderInterface
    {
        $this->diagnostic->files[] = ['file' => $file, 'line' => null];

        return new RecordingFileDiagnosticBuilder($this, $this->diagnostic, count($this->diagnostic->files) - 1);
    }

    public function fromSource(string $source): self
    {
        $this->diagnostic->source = $source;

        return $this;
    }

    public function withExternalInfoUrl(string $url): self
    {
        $this->diagnostic->externalInfoUrl = $url;

        return $this;
    }

    public function forClass(string $className): self
    {
        $this->diagnostic->className = $className;

        return $this;
    }

    public function withCategory(string $category): self
    {
        $this->diagnostic->category = $category;

        return $this;
    }

    public function end(): TaskReportInterface
    {
        $this->diagnostic->ended = true;

        return $this->report;
    }
}
