<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest;

use Phpcq\ComposerDependencyAnalyserPluginTest\Support\PluginTestTrait;
use Phpcq\PluginApi\Version10\Configuration\Builder\BoolOptionBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\Builder\StringOptionBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\ProjectConfigInterface;
use Phpcq\PluginApi\Version10\Task\PhpTaskBuilderInterface;
use Phpcq\PluginApi\Version10\Task\TaskFactoryInterface;
use Phpcq\PluginApi\Version10\Task\TaskInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ComposerDependencyAnalyserPluginTest extends TestCase
{
    use PluginTestTrait;

    private const BINARY = '/installed-dir/vendor/bin/composer-dependency-analyser';

    public function testPluginName(): void
    {
        self::assertSame('composer-dependency-analyser', $this->instantiatePlugin()->getName());
    }

    public function testDescribesExactlyTheExpectedOptions(): void
    {
        $options = $this->describeOptions();

        self::assertSame(
            [
                'config'                       => ['string', false, null],
                'composer_json'                => ['string', true, 'composer.json'],
                'ignore_unknown_classes'       => ['bool', true, false],
                'ignore_unknown_functions'     => ['bool', true, false],
                'ignore_shadow_deps'           => ['bool', true, false],
                'ignore_unused_deps'           => ['bool', true, false],
                'ignore_dev_in_prod_deps'      => ['bool', true, false],
                'ignore_prod_only_in_dev_deps' => ['bool', true, false],
                'disable_ext_analysis'         => ['bool', true, false],
            ],
            array_map(
                static fn (array $option): array => [$option['type'], $option['required'], $option['default']],
                $options
            )
        );
    }

    public function testEveryOptionHasADescription(): void
    {
        foreach ($this->describeOptions() as $name => $option) {
            self::assertNotSame('', trim($option['description']), 'Missing description for ' . $name);
        }
    }

    public function testConfigDescriptionMentionsAutoDetectedConfigFile(): void
    {
        self::assertStringContainsString(
            'composer-dependency-analyser.php',
            $this->describeOptions()['config']['description']
        );
    }

    public function testCreatesExactlyOneTask(): void
    {
        $captured = $this->runCreateDiagnosticTasks($this->createConfig());

        self::assertCount(1, $captured->tasks);
        self::assertInstanceOf(TaskInterface::class, $captured->tasks[0]);
    }

    public function testRunsInstalledBinaryAsPhpProcess(): void
    {
        $builder = $this->createStub(PhpTaskBuilderInterface::class);
        $builder->method('withWorkingDirectory')->willReturnSelf();
        $builder->method('withOutputTransformer')->willReturnSelf();
        $builder->method('build')->willReturn($this->createStub(TaskInterface::class));

        $taskFactory = $this->createMock(TaskFactoryInterface::class);
        $taskFactory->expects(self::never())->method('buildRunProcess');
        $taskFactory->expects(self::never())->method('buildRunPhar');
        $taskFactory
            ->expects(self::once())
            ->method('buildPhpProcess')
            ->with('composer-dependency-analyser', self::callback(
                static fn (array $arguments): bool => self::BINARY === $arguments[0]
            ))
            ->willReturn($builder);

        $projectConfig = $this->createStub(ProjectConfigInterface::class);
        $projectConfig->method('getProjectRootPath')->willReturn('/project-root');
        $environment = $this->createStub(EnvironmentInterface::class);
        $environment->method('getProjectConfiguration')->willReturn($projectConfig);
        $environment->method('getTaskFactory')->willReturn($taskFactory);
        $environment->method('getInstalledDir')->willReturn('/installed-dir');

        iterator_to_array($this->instantiatePlugin()->createDiagnosticTasks($this->createConfig(), $environment));
    }

    public function testRunsInProjectRoot(): void
    {
        $captured = $this->runCreateDiagnosticTasks($this->createConfig(), '/some/project');

        self::assertSame('/some/project', $captured->workingDirectory);
    }

    public function testAttachesOutputTransformer(): void
    {
        $captured = $this->runCreateDiagnosticTasks($this->createConfig());

        self::assertInstanceOf(OutputTransformerFactoryInterface::class, $captured->transformerFactory);
    }

    /**
     * @return array<string, array{type: string, description: string, required: bool, default: mixed}>
     */
    private function describeOptions(): array
    {
        $options = [];

        $builder = $this->createMock(PluginConfigurationBuilderInterface::class);
        foreach (
            [
                'describeIntOption',
                'describeFloatOption',
                'describeEnumOption',
                'describeStringListOption',
                'describeOptions',
                'describePrototypeOption',
                'describeOptionsListOption',
                'supportDirectories',
            ] as $unexpected
        ) {
            $builder->expects(self::never())->method($unexpected);
        }

        $builder
            ->method('describeStringOption')
            ->willReturnCallback(function (
                string $name,
                string $description
            ) use (
                &$options
            ): StringOptionBuilderInterface {
                $options[$name] = [
                    'type'        => 'string',
                    'description' => $description,
                    'required'    => false,
                    'default'     => null,
                ];
                $option         = $this->createStub(StringOptionBuilderInterface::class);
                $option->method('isRequired')->willReturnCallback(function () use (&$options, $name, $option) {
                    $options[$name]['required'] = true;

                    return $option;
                });
                $option->method('withDefaultValue')->willReturnCallback(
                    function (string $value) use (&$options, $name, $option) {
                        $options[$name]['default'] = $value;

                        return $option;
                    }
                );

                return $option;
            });

        $builder
            ->method('describeBoolOption')
            ->willReturnCallback(function (
                string $name,
                string $description
            ) use (
                &$options
            ): BoolOptionBuilderInterface {
                $options[$name] = [
                    'type'        => 'bool',
                    'description' => $description,
                    'required'    => false,
                    'default'     => null,
                ];
                $option         = $this->createStub(BoolOptionBuilderInterface::class);
                $option->method('isRequired')->willReturnCallback(function () use (&$options, $name, $option) {
                    $options[$name]['required'] = true;

                    return $option;
                });
                $option->method('withDefaultValue')->willReturnCallback(
                    function (bool $value) use (&$options, $name, $option) {
                        $options[$name]['default'] = $value;

                        return $option;
                    }
                );

                return $option;
            });

        $this->instantiatePlugin()->describeConfiguration($builder);

        return $options;
    }
}
