<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest\Support;

use Phpcq\PluginApi\Version10\Report\DiagnosticBuilderInterface;
use Phpcq\PluginApi\Version10\Report\FileDiagnosticBuilderInterface;

final class RecordingFileDiagnosticBuilder implements FileDiagnosticBuilderInterface
{
    public function __construct(
        private readonly DiagnosticBuilderInterface $parent,
        private readonly RecordedDiagnostic $diagnostic,
        private readonly int $index
    ) {
    }

    public function forRange(int $line, ?int $column = null, ?int $endline = null, ?int $endcolumn = null): self
    {
        $this->diagnostic->files[$this->index]['line'] = $line;
        $this->diagnostic->ranges[$this->index]        = [
            'column'    => $column,
            'endLine'   => $endline,
            'endColumn' => $endcolumn,
        ];

        return $this;
    }

    public function end(): DiagnosticBuilderInterface
    {
        return $this->parent;
    }
}
