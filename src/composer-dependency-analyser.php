<?php

declare(strict_types=1);

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputInterface;
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
        return new class ($projectRoot, $composerJson, $configFile) implements OutputTransformerFactoryInterface {
            public function __construct(
                private string $projectRoot,
                private string $composerJson,
                private ?string $configFile
            ) {
            }

            public function createFor(TaskReportInterface $report): OutputTransformerInterface
            {
                return new class (
                    $report,
                    $this->projectRoot,
                    $this->composerJson,
                    $this->configFile
                ) implements OutputTransformerInterface {
                    private string $stdout = '';

                    private string $stderr = '';

                    public function __construct(
                        private TaskReportInterface $report,
                        private string $projectRoot,
                        private string $composerJson,
                        private ?string $configFile
                    ) {
                        $this->projectRoot = rtrim($projectRoot, '/');
                    }

                    public function write(string $data, int $channel): void
                    {
                        if (OutputInterface::CHANNEL_STDERR === $channel) {
                            $this->stderr .= $data;

                            return;
                        }
                        $this->stdout .= $data;
                    }

                    public function finish(int $exitCode): void
                    {
                        $root = $this->loadReport();
                        if (null === $root) {
                            if (0 === $exitCode && '' === trim($this->stdout)) {
                                $this->report->close(TaskReportInterface::STATUS_PASSED);

                                return;
                            }
                            $this->reportToolFailure();
                            $this->report->close(TaskReportInterface::STATUS_FAILED);

                            return;
                        }

                        $this->processDocument($root);
                        $this->report->close(
                            0 === $exitCode ? TaskReportInterface::STATUS_PASSED : TaskReportInterface::STATUS_FAILED
                        );
                    }

                    /**
                     * Parses stdout as JUnit XML and returns the <testsuites> element, or null if invalid.
                     */
                    private function loadReport(): ?DOMElement
                    {
                        if ('' === trim($this->stdout)) {
                            return null;
                        }

                        $previous = libxml_use_internal_errors(true);
                        try {
                            $document = new DOMDocument('1.0');
                            $loaded   = $document->loadXML($this->stdout);
                        } finally {
                            libxml_clear_errors();
                            libxml_use_internal_errors($previous);
                        }

                        $root = $document->documentElement;
                        if (true !== $loaded || null === $root || 'testsuites' !== $root->nodeName) {
                            return null;
                        }

                        return $root;
                    }

                    private function reportToolFailure(): void
                    {
                        $stderr = $this->stripAnsi($this->stderr);
                        $lines  = array_filter(
                            array_map('rtrim', explode("\n", $stderr)),
                            static fn (string $line): bool => '' !== trim($line)
                                && !str_starts_with($line, 'Using config ')
                        );
                        $message = [] === $lines
                            ? 'composer-dependency-analyser did not produce a valid report.'
                            : implode("\n", $lines);

                        $this->report->addDiagnostic(TaskReportInterface::SEVERITY_FATAL, $message)->end();
                        $this->report
                            ->addAttachment('output.log')
                            ->fromString($this->stdout)
                            ->setMimeType('text/plain')
                            ->end();
                        $this->report
                            ->addAttachment('error.log')
                            ->fromString($stderr)
                            ->setMimeType('text/plain')
                            ->end();
                    }

                    private function processDocument(DOMElement $root): void
                    {
                        // Implemented in the next step of the plan (diagnostic mapping).
                    }

                    private function stripAnsi(string $text): string
                    {
                        return preg_replace('/\e\[[0-9;]*m/', '', $text) ?? $text;
                    }
                };
            }
        };
    }
};
