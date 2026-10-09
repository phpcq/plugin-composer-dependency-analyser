<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

return (new Configuration())
    // The plugin file is not part of the composer autoloading, but it is the production code of this package.
    ->addPathToScan(__DIR__ . '/src', false)
    // PHPUnit is provided as PHAR by phpcq.
    ->ignoreUnknownClassesRegex('~^PHPUnit\\\\~');
