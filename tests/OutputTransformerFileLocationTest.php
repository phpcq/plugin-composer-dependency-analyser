<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest;

use Phpcq\ComposerDependencyAnalyserPluginTest\Support\OutputTransformerTestTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OutputTransformerFileLocationTest extends TestCase
{
    use OutputTransformerTestTrait;

    private const PROJECT_ROOT = __DIR__ . '/Fixtures/project';

    public function testAbsolutePathsBelowProjectRootBecomeRelative(): void
    {
        $report = $this->transform($this->suite('unknown classes', 'Foo', [
            [null, self::PROJECT_ROOT . '/src/Foo.php:3'],
            [null, '/elsewhere/src/Bar.php:4'],
        ]), 1);

        self::assertSame(
            [
                ['file' => 'composer.json', 'line' => null],
                ['file' => 'src/Foo.php', 'line' => 3],
                ['file' => '/elsewhere/src/Bar.php', 'line' => 4],
            ],
            $report->diagnostics[0]->files
        );
    }

    public function testProjectRootWithTrailingSlashIsHandled(): void
    {
        $report = $this->transform(
            $this->suite('unknown classes', 'Foo', [[null, self::PROJECT_ROOT . '/src/Foo.php:3']]),
            1,
            '',
            [],
            self::PROJECT_ROOT . '/'
        );

        self::assertSame(['file' => 'src/Foo.php', 'line' => 3], $report->diagnostics[0]->files[1]);
    }

    public function testDuplicateLocationsAreReportedOnce(): void
    {
        $report = $this->transform($this->suite('shadow dependencies', 'acme/pkg', [
            ['Acme\A', 'src/Foo.php:3'],
            ['Acme\B', 'src/Foo.php:3'],
            ['Acme\A', 'src/Foo.php:5'],
        ]), 1);

        self::assertSame(
            'Shadow dependency "acme/pkg" is used but not listed in composer.json (used symbols: "Acme\A", "Acme\B").',
            $report->diagnostics[0]->message
        );
        self::assertSame(
            [
                ['file' => 'composer.json', 'line' => null],
                ['file' => 'src/Foo.php', 'line' => 3],
                ['file' => 'src/Foo.php', 'line' => 5],
            ],
            $report->diagnostics[0]->files
        );
    }

    public function testUnparsableLocationsAreSkipped(): void
    {
        $report = $this->transform($this->suite('unknown classes', 'Foo', [[null, 'somewhere']]), 1);

        self::assertSame([['file' => 'composer.json', 'line' => null]], $report->diagnostics[0]->files);
    }

    public function testComposerLineLookupMatchesKeysOnly(): void
    {
        // "psr/log" appears as a value in line 3 of the fixture; the key is in line 8.
        $report = $this->transform($this->suite('unused dependencies', 'psr/log', []), 1);

        self::assertSame([['file' => 'composer.json', 'line' => 8]], $report->diagnostics[0]->files);
    }

    public function testComposerLineLookupEscapesPackageName(): void
    {
        // Unescaped, "acme/dot.pkg" would match the key "acme/dotXpkg" in line 9.
        $report = $this->transform($this->suite('unused dependencies', 'acme/dot.pkg', []), 1);

        self::assertSame([['file' => 'composer.json', 'line' => null]], $report->diagnostics[0]->files);
    }

    public function testMissingComposerJsonReportsWithoutLine(): void
    {
        $report = $this->transform(
            $this->suite('unused dependencies', 'psr/log', []),
            1,
            '',
            ['composer_json' => 'does-not-exist.json']
        );

        self::assertSame([['file' => 'does-not-exist.json', 'line' => null]], $report->diagnostics[0]->files);
    }

    public function testAbsoluteComposerJsonBelowProjectRootIsReportedRelative(): void
    {
        $report = $this->transform(
            $this->suite('unused dependencies', 'psr/log', []),
            1,
            '',
            ['composer_json' => self::PROJECT_ROOT . '/composer.json']
        );

        self::assertSame([['file' => 'composer.json', 'line' => 8]], $report->diagnostics[0]->files);
    }
}
