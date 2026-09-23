<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest\Support;

use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;

trait PluginTestTrait
{
    private function instantiatePlugin(): DiagnosticsPluginInterface
    {
        return include dirname(__DIR__, 2) . '/src/composer-dependency-analyser.php';
    }
}
