<?php

declare(strict_types=1);

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;

return new class implements DiagnosticsPluginInterface {
    /**
     * Boolean options: option name => [CLI flag, description].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const BOOL_OPTIONS = [
        'ignore_unknown_classes'       => [
            '--ignore-unknown-classes',
            'Do not report classes that cannot be autoloaded (unknown classes).',
        ],
        'ignore_unknown_functions'     => [
            '--ignore-unknown-functions',
            'Do not report functions that cannot be found (unknown functions).',
        ],
        'ignore_shadow_deps'           => [
            '--ignore-shadow-deps',
            'Do not report shadow dependencies (packages or extensions that are used but not listed in'
            . ' composer.json).',
        ],
        'ignore_unused_deps'           => [
            '--ignore-unused-deps',
            'Do not report unused dependencies (packages listed in composer.json without any usage in the'
            . ' scanned paths).',
        ],
        'ignore_dev_in_prod_deps'      => [
            '--ignore-dev-in-prod-deps',
            'Do not report dev dependencies used in production code (they should probably be moved to'
            . ' "require").',
        ],
        'ignore_prod_only_in_dev_deps' => [
            '--ignore-prod-only-in-dev-deps',
            'Do not report production dependencies used only in dev paths (they should probably be moved to'
            . ' "require-dev").',
        ],
        'disable_ext_analysis'         => [
            '--disable-ext-analysis',
            'Disable the analysis of PHP extension (ext-*) usages.',
        ],
    ];

    public function getName(): string
    {
        return 'composer-dependency-analyser';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->describeStringOption(
            'config',
            'Path to the composer-dependency-analyser configuration file, relative to the project root. The file'
            . ' must return an instance of ShipMonk\ComposerDependencyAnalyser\Config\Configuration and allows'
            . ' fine grained settings (paths to scan or exclude, ignored errors per package or path, …). If'
            . ' omitted, the tool uses composer-dependency-analyser.php in the project root when it exists.'
        );
        $configOptionsBuilder
            ->describeStringOption(
                'composer_json',
                'Path to the composer.json to analyse, relative to the project root. Package related'
                . ' diagnostics are reported against this file.'
            )
            ->isRequired()
            ->withDefaultValue('composer.json');

        foreach (self::BOOL_OPTIONS as $name => [, $description]) {
            $configOptionsBuilder
                ->describeBoolOption($name, $description)
                ->isRequired()
                ->withDefaultValue(false);
        }
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): iterable {
        $projectRoot  = $environment->getProjectConfiguration()->getProjectRootPath();
        $composerJson = $config->getString('composer_json');
        $configFile   = $config->has('config') ? $config->getString('config') : null;

        $arguments = [
            $environment->getInstalledDir() . '/vendor/bin/composer-dependency-analyser',
            '--format=junit',
            '--show-all-usages',
            '--composer-json=' . $composerJson,
        ];
        if (null !== $configFile) {
            $arguments[] = '--config=' . $configFile;
        }
        foreach (self::BOOL_OPTIONS as $option => [$flag]) {
            if ($config->has($option) && $config->getBool($option)) {
                $arguments[] = $flag;
            }
        }

        yield $environment
            ->getTaskFactory()
            ->buildPhpProcess($this->getName(), $arguments)
            ->withWorkingDirectory($projectRoot)
            ->withOutputTransformer($this->createOutputTransformerFactory($projectRoot, $composerJson, $configFile))
            ->build();
    }

    private function createOutputTransformerFactory(
        string $projectRoot,
        string $composerJson,
        ?string $configFile
    ): OutputTransformerFactoryInterface {
        return new class implements OutputTransformerFactoryInterface {
            public function createFor(TaskReportInterface $report): OutputTransformerInterface
            {
                return new class ($report) implements OutputTransformerInterface {
                    public function __construct(private TaskReportInterface $report)
                    {
                    }

                    public function write(string $data, int $channel): void
                    {
                    }

                    public function finish(int $exitCode): void
                    {
                        $this->report->close(
                            0 === $exitCode ? TaskReportInterface::STATUS_PASSED : TaskReportInterface::STATUS_FAILED
                        );
                    }
                };
            }
        };
    }
};
