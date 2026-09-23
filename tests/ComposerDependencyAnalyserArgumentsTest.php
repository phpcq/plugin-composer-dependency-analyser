<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest;

use Phpcq\ComposerDependencyAnalyserPluginTest\Support\PluginTestTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ComposerDependencyAnalyserArgumentsTest extends TestCase
{
    use PluginTestTrait;

    private const BINARY = '/installed-dir/vendor/bin/composer-dependency-analyser';

    private const ALL_FLAGS = [
        'ignore_unknown_classes'       => '--ignore-unknown-classes',
        'ignore_unknown_functions'     => '--ignore-unknown-functions',
        'ignore_shadow_deps'           => '--ignore-shadow-deps',
        'ignore_unused_deps'           => '--ignore-unused-deps',
        'ignore_dev_in_prod_deps'      => '--ignore-dev-in-prod-deps',
        'ignore_prod_only_in_dev_deps' => '--ignore-prod-only-in-dev-deps',
        'disable_ext_analysis'         => '--disable-ext-analysis',
    ];

    public function testDefaultArguments(): void
    {
        $captured = $this->runCreateDiagnosticTasks($this->createConfig());

        self::assertSame(
            [self::BINARY, '--format=junit', '--show-all-usages', '--composer-json=composer.json'],
            $captured->command
        );
    }

    public function testPassesConfigFile(): void
    {
        $captured = $this->runCreateDiagnosticTasks($this->createConfig(['config' => 'config/cda.php']));

        self::assertSame(
            [
                self::BINARY,
                '--format=junit',
                '--show-all-usages',
                '--composer-json=composer.json',
                '--config=config/cda.php',
            ],
            $captured->command
        );
    }

    public function testPassesCustomComposerJson(): void
    {
        $captured = $this->runCreateDiagnosticTasks($this->createConfig(['composer_json' => 'app/composer.json']));

        self::assertContains('--composer-json=app/composer.json', $captured->command);
        self::assertNotContains('--composer-json=composer.json', $captured->command);
    }

    #[DataProvider('provideBoolOptions')]
    public function testSingleBoolOptionAddsOnlyItsFlag(string $option, string $flag): void
    {
        $captured = $this->runCreateDiagnosticTasks($this->createConfig([$option => true]));

        self::assertSame(
            [self::BINARY, '--format=junit', '--show-all-usages', '--composer-json=composer.json', $flag],
            $captured->command
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideBoolOptions(): iterable
    {
        foreach (self::ALL_FLAGS as $option => $flag) {
            yield $option => [$option, $flag];
        }
    }

    public function testAllBoolOptionsAddFlagsInDefinedOrder(): void
    {
        $captured = $this->runCreateDiagnosticTasks(
            $this->createConfig(array_fill_keys(array_keys(self::ALL_FLAGS), true))
        );

        self::assertSame(
            [
                self::BINARY,
                '--format=junit',
                '--show-all-usages',
                '--composer-json=composer.json',
                ...array_values(self::ALL_FLAGS),
            ],
            $captured->command
        );
    }

    public function testMissingBoolOptionsAreSkipped(): void
    {
        $captured = $this->runCreateDiagnosticTasks(
            $this->createConfig(array_fill_keys(array_keys(self::ALL_FLAGS), null))
        );

        self::assertSame(
            [self::BINARY, '--format=junit', '--show-all-usages', '--composer-json=composer.json'],
            $captured->command
        );
    }

    public function testFormatAndUsageFlagsCannotBeDisabled(): void
    {
        $captured = $this->runCreateDiagnosticTasks(
            $this->createConfig(['config' => 'x.php'] + array_fill_keys(array_keys(self::ALL_FLAGS), true))
        );

        self::assertSame(['--format=junit'], array_values(array_filter(
            $captured->command,
            static fn (string $argument): bool => str_starts_with($argument, '--format')
        )));
        self::assertContains('--show-all-usages', $captured->command);
    }
}
