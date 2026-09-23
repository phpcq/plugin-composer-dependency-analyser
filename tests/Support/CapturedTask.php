<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest\Support;

use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Task\TaskInterface;

/**
 * Everything createDiagnosticTasks() passed to the task factory and task builder.
 */
final class CapturedTask
{
    /** @var list<TaskInterface> */
    public array $tasks = [];

    public ?string $toolName = null;

    /** @var list<string> */
    public array $command = [];

    public ?string $workingDirectory = null;

    /** @var array<string, string>|null */
    public ?array $env = null;

    public ?OutputTransformerFactoryInterface $transformerFactory = null;
}
