<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest\Support;

use LogicException;
use Phpcq\PluginApi\Version10\Report\AttachmentBuilderInterface;
use Phpcq\PluginApi\Version10\Report\DiagnosticBuilderInterface;
use Phpcq\PluginApi\Version10\Report\DiffBuilderInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;

final class RecordingTaskReport implements TaskReportInterface
{
    /** @var list<RecordedDiagnostic> */
    public array $diagnostics = [];

    /** @var array<string, array{content: string, mimeType: ?string}> */
    public array $attachments = [];

    /** @var list<string> */
    public array $closedWith = [];

    public function addMetadata(string $name, string $value): self
    {
        return $this;
    }

    public function addDiagnostic(string $severity, string $message): DiagnosticBuilderInterface
    {
        $diagnostic          = new RecordedDiagnostic($severity, $message);
        $this->diagnostics[] = $diagnostic;

        return new RecordingDiagnosticBuilder($this, $diagnostic);
    }

    public function addAttachment(string $name): AttachmentBuilderInterface
    {
        return new RecordingAttachmentBuilder($this, $name);
    }

    public function addDiff(string $name): DiffBuilderInterface
    {
        throw new LogicException('Diffs are not expected from this plugin.');
    }

    public function close(string $status): void
    {
        $this->closedWith[] = $status;
    }

    public function getStatus(): string
    {
        return $this->closedWith[count($this->closedWith) - 1] ?? self::STATUS_STARTED;
    }
}
