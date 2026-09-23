<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest\Support;

final class RecordedDiagnostic
{
    public ?string $source = null;

    public ?string $className = null;

    /** @var list<array{file: string, line: int|null}> */
    public array $files = [];

    /** @var array<int, array{column: int|null, endLine: int|null, endColumn: int|null}> */
    public array $ranges = [];

    public bool $ended = false;

    public function __construct(
        public readonly string $severity,
        public readonly string $message
    ) {
    }
}
