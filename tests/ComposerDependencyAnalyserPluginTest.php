<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest;

use Phpcq\ComposerDependencyAnalyserPluginTest\Support\PluginTestTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ComposerDependencyAnalyserPluginTest extends TestCase
{
    use PluginTestTrait;

    public function testPluginName(): void
    {
        self::assertSame('composer-dependency-analyser', $this->instantiatePlugin()->getName());
    }
}
