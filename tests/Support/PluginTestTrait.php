<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest\Support;

use OutOfBoundsException;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\ProjectConfigInterface;
use Phpcq\PluginApi\Version10\Task\PhpTaskBuilderInterface;
use Phpcq\PluginApi\Version10\Task\TaskFactoryInterface;
use Phpcq\PluginApi\Version10\Task\TaskInterface;

trait PluginTestTrait
{
    private function instantiatePlugin(): DiagnosticsPluginInterface
    {
        return include dirname(__DIR__, 2) . '/src/composer-dependency-analyser.php';
    }

    /**
     * Creates a configuration as phpcq would pass it (defaults applied).
     *
     * @param array<string, string|bool|null> $overrides A null value removes the option.
     */
    private function createConfig(array $overrides = []): PluginConfigurationInterface
    {
        $values = array_filter(
            array_merge(
                [
                    'composer_json'                => 'composer.json',
                    'ignore_unknown_classes'       => false,
                    'ignore_unknown_functions'     => false,
                    'ignore_shadow_deps'           => false,
                    'ignore_unused_deps'           => false,
                    'ignore_dev_in_prod_deps'      => false,
                    'ignore_prod_only_in_dev_deps' => false,
                    'disable_ext_analysis'         => false,
                ],
                $overrides
            ),
            static fn (string|bool|null $value): bool => null !== $value
        );

        $config = $this->createStub(PluginConfigurationInterface::class);
        $config->method('has')->willReturnCallback(
            static fn (string $name): bool => array_key_exists($name, $values)
        );
        $config->method('getString')->willReturnCallback(
            static fn (string $name): string => is_string($values[$name] ?? null)
                ? $values[$name]
                : throw new OutOfBoundsException('No string option ' . $name)
        );
        $config->method('getBool')->willReturnCallback(
            static fn (string $name): bool => is_bool($values[$name] ?? null)
                ? $values[$name]
                : throw new OutOfBoundsException('No bool option ' . $name)
        );

        return $config;
    }

    private function runCreateDiagnosticTasks(
        PluginConfigurationInterface $config,
        string $projectRoot = '/project-root'
    ): CapturedTask {
        $captured = new CapturedTask();

        $taskBuilder = $this->createStub(PhpTaskBuilderInterface::class);
        $taskBuilder->method('withWorkingDirectory')->willReturnCallback(
            static function (string $cwd) use ($captured, $taskBuilder): PhpTaskBuilderInterface {
                $captured->workingDirectory = $cwd;

                return $taskBuilder;
            }
        );
        $taskBuilder->method('withOutputTransformer')->willReturnCallback(
            static function (OutputTransformerFactoryInterface $factory) use ($captured, $taskBuilder): PhpTaskBuilderInterface {
                $captured->transformerFactory = $factory;

                return $taskBuilder;
            }
        );
        $taskBuilder->method('build')->willReturn($this->createStub(TaskInterface::class));

        $taskFactory = $this->createStub(TaskFactoryInterface::class);
        $taskFactory->method('buildPhpProcess')->willReturnCallback(
            static function (string $toolName, array $arguments) use ($captured, $taskBuilder): PhpTaskBuilderInterface {
                $captured->toolName = $toolName;
                $captured->command  = array_values($arguments);

                return $taskBuilder;
            }
        );

        $projectConfig = $this->createStub(ProjectConfigInterface::class);
        $projectConfig->method('getProjectRootPath')->willReturn($projectRoot);

        $environment = $this->createStub(EnvironmentInterface::class);
        $environment->method('getProjectConfiguration')->willReturn($projectConfig);
        $environment->method('getTaskFactory')->willReturn($taskFactory);
        $environment->method('getInstalledDir')->willReturn('/installed-dir');

        $captured->tasks = iterator_to_array(
            $this->instantiatePlugin()->createDiagnosticTasks($config, $environment),
            false
        );

        return $captured;
    }
}
