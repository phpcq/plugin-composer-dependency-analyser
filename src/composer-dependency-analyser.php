<?php

declare(strict_types=1);

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Report\DiagnosticBuilderInterface;
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
                    /**
                     * JUnit test suite name => [error type, severity].
                     *
                     * @var array<string, array{0: string, 1: string}>
                     */
                    private const SUITES = [
                        'unknown classes'                          => [
                            'unknown-class',
                            TaskReportInterface::SEVERITY_MAJOR,
                        ],
                        'unknown functions'                        => [
                            'unknown-function',
                            TaskReportInterface::SEVERITY_MAJOR,
                        ],
                        'shadow dependencies'                      => [
                            'shadow-dependency',
                            TaskReportInterface::SEVERITY_MAJOR,
                        ],
                        'dev dependencies in production code'      => [
                            'dev-dependency-in-prod',
                            TaskReportInterface::SEVERITY_MAJOR,
                        ],
                        'prod dependencies used only in dev paths' => [
                            'prod-dependency-only-in-dev',
                            TaskReportInterface::SEVERITY_MINOR,
                        ],
                        'unused dependencies'                      => [
                            'unused-dependency',
                            TaskReportInterface::SEVERITY_MINOR,
                        ],
                        'unused-ignore'                            => [
                            'unused-ignore',
                            TaskReportInterface::SEVERITY_MINOR,
                        ],
                    ];

                    /** Error types whose package is listed in composer.json. */
                    private const LISTED_PACKAGE_TYPES = [
                        'dev-dependency-in-prod',
                        'prod-dependency-only-in-dev',
                        'unused-dependency',
                    ];

                    /** Lazily loaded composer.json contents; '' when not readable. */
                    private ?string $composerJsonContent = null;

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
                        foreach ($root->childNodes as $suite) {
                            if (!$suite instanceof DOMElement || 'testsuite' !== $suite->nodeName) {
                                continue;
                            }
                            foreach ($suite->childNodes as $testCase) {
                                if (!$testCase instanceof DOMElement || 'testcase' !== $testCase->nodeName) {
                                    continue;
                                }
                                $this->processTestCase($suite->getAttribute('name'), $testCase);
                            }
                        }
                    }

                    private function processTestCase(string $suite, DOMElement $testCase): void
                    {
                        $name    = $testCase->getAttribute('name');
                        $symbols = [];
                        $texts   = [];
                        foreach ($testCase->childNodes as $failure) {
                            if (!$failure instanceof DOMElement || 'failure' !== $failure->nodeName) {
                                continue;
                            }
                            if ($failure->hasAttribute('message')) {
                                $symbols[] = $failure->getAttribute('message');
                            }
                            $texts[] = trim($failure->textContent);
                        }
                        $symbols = array_values(array_unique($symbols));

                        [$type, $severity] = self::SUITES[$suite] ?? [$suite, TaskReportInterface::SEVERITY_MAJOR];

                        $builder = $this->report
                            ->addDiagnostic($severity, $this->buildMessage($type, $suite, $name, $symbols, $texts))
                            ->fromSource($type);
                        if ('unknown-class' === $type) {
                            $builder->forClass($name);
                        }

                        $this->addFile(
                            $builder,
                            $this->relativePath($this->composerJson),
                            in_array($type, self::LISTED_PACKAGE_TYPES, true) ? $this->findPackageLine($name) : null
                        );

                        if ('unused-ignore' === $type) {
                            $configFile = $this->configFileForReport();
                            if (null !== $configFile) {
                                $this->addFile($builder, $configFile, null);
                            }
                        } else {
                            foreach ($this->parseLocations($texts) as [$file, $line]) {
                                $this->addFile($builder, $file, $line);
                            }
                        }

                        $builder->end();
                    }

                    /**
                     * @param list<string> $symbols
                     * @param list<string> $texts
                     */
                    private function buildMessage(
                        string $type,
                        string $suite,
                        string $name,
                        array $symbols,
                        array $texts
                    ): string {
                        $usedSymbols = [] === $symbols
                            ? ''
                            : sprintf(' (used symbols: "%s")', implode('", "', $symbols));

                        return match ($type) {
                            'unknown-class' => sprintf(
                                'Unknown class "%s" (unable to autoload it, so it cannot be checked).',
                                $name
                            ),
                            'unknown-function' => sprintf(
                                'Unknown function "%s" (unable to autoload it, so it cannot be checked).',
                                $name
                            ),
                            'shadow-dependency' => sprintf(
                                'Shadow dependency "%s" is used but not listed in composer.json%s.',
                                $name,
                                $usedSymbols
                            ),
                            'dev-dependency-in-prod' => sprintf(
                                'Dev dependency "%s" is used in production code, it should probably be moved to'
                                . ' "require"%s.',
                                $name,
                                $usedSymbols
                            ),
                            'prod-dependency-only-in-dev' => sprintf(
                                'Prod dependency "%s" is used only in dev paths, it should probably be moved to'
                                . ' "require-dev".',
                                $name
                            ),
                            'unused-dependency' => sprintf(
                                'Unused dependency "%s" is listed in composer.json, but no usage was found.',
                                $name
                            ),
                            'unused-ignore' => [] === $texts
                                ? sprintf('Ignored error "%s" was never applied.', $name)
                                : implode("\n", $texts),
                            default => sprintf('%s: "%s"', $suite, $name)
                                . ([] === $texts ? '' : ' - ' . implode('; ', $texts)),
                        };
                    }

                    private function addFile(DiagnosticBuilderInterface $builder, string $file, ?int $line): void
                    {
                        $fileBuilder = $builder->forFile($file);
                        if (null !== $line) {
                            $fileBuilder->forRange($line);
                        }
                        $fileBuilder->end();
                    }

                    /**
                     * Parses "<path>:<line>" failure texts, unique by location, in order of appearance.
                     *
                     * @param list<string> $texts
                     *
                     * @return list<array{0: string, 1: int}>
                     */
                    private function parseLocations(array $texts): array
                    {
                        $locations = [];
                        foreach ($texts as $text) {
                            if (1 !== preg_match('/^(.+):(\d+)$/', $text, $matches)) {
                                continue;
                            }
                            $file                            = $this->relativePath($matches[1]);
                            $line                            = (int) $matches[2];
                            $locations[$file . ':' . $line] = [$file, $line];
                        }

                        return array_values($locations);
                    }

                    private function relativePath(string $path): string
                    {
                        $prefix = $this->projectRoot . '/';

                        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
                    }

                    private function findPackageLine(string $package): ?int
                    {
                        $content = $this->composerJsonContent();
                        // [ \t]* instead of \s* so that the match never starts on a previous line.
                        $pattern = '/^[ \t]*"' . preg_quote($package, '/') . '"\s*:/m';
                        if ('' === $content || 1 !== preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                            return null;
                        }

                        return substr_count($content, "\n", 0, $matches[0][1]) + 1;
                    }

                    private function composerJsonContent(): string
                    {
                        if (null === $this->composerJsonContent) {
                            $path = str_starts_with($this->composerJson, '/')
                                ? $this->composerJson
                                : $this->projectRoot . '/' . $this->composerJson;
                            $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

                            $this->composerJsonContent = false === $content ? '' : $content;
                        }

                        return $this->composerJsonContent;
                    }

                    private function configFileForReport(): ?string
                    {
                        if (null !== $this->configFile) {
                            return $this->relativePath($this->configFile);
                        }

                        return is_file($this->projectRoot . '/composer-dependency-analyser.php')
                            ? 'composer-dependency-analyser.php'
                            : null;
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
