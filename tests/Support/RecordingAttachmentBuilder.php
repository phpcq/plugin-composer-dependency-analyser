<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest\Support;

use LogicException;
use Phpcq\PluginApi\Version10\Report\AttachmentBuilderInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;

final class RecordingAttachmentBuilder implements AttachmentBuilderInterface
{
    private string $content = '';

    private ?string $mimeType = null;

    public function __construct(
        private readonly RecordingTaskReport $report,
        private readonly string $name
    ) {
    }

    public function fromFile(string $file): self
    {
        throw new LogicException(sprintf('File attachments are not expected from this plugin (got: "%s").', $file));
    }

    public function fromString(string $buffer): self
    {
        $this->content = $buffer;

        return $this;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function end(): TaskReportInterface
    {
        $this->report->attachments[$this->name] = ['content' => $this->content, 'mimeType' => $this->mimeType];

        return $this->report;
    }
}
