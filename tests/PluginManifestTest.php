<?php

declare(strict_types=1);

namespace Phpcq\ComposerDependencyAnalyserPluginTest;

use JsonSchema\Validator;
use Phpcq\ComposerDependencyAnalyserPluginTest\Support\PluginTestTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PluginManifestTest extends TestCase
{
    use PluginTestTrait;

    private const ROOT = __DIR__ . '/..';

    public function testManifestIsValidAgainstPhpcqSchema(): void
    {
        $errors = $this->validate($this->loadManifest());

        self::assertSame([], $errors, implode("\n", $errors));
    }

    public function testSchemaValidationRejectsUnknownProperties(): void
    {
        $manifest             = $this->loadManifest();
        $manifest->unexpected = 'value';

        self::assertNotSame([], $this->validate($manifest));
    }

    public function testSchemaValidationRejectsInvalidComposerRequirement(): void
    {
        $manifest = $this->loadManifest();
        $manifest->requirements->composer->{'Not A Package!'} = '^1.0';

        self::assertNotSame([], $this->validate($manifest));
    }

    public function testManifestPointsToPluginFile(): void
    {
        $manifest = $this->loadManifest();

        self::assertSame('src/composer-dependency-analyser.php', $manifest->url);
        self::assertFileExists(self::ROOT . '/' . $manifest->url);
    }

    public function testManifestNameMatchesPluginName(): void
    {
        self::assertSame($this->instantiatePlugin()->getName(), $this->loadManifest()->name);
    }

    public function testManifestRequiresComposerDependencyAnalyser(): void
    {
        $composer = (array) $this->loadManifest()->requirements->composer;

        self::assertSame(['shipmonk/composer-dependency-analyser' => '^1.0'], $composer);
    }

    public function testPlatformRequirementsMatchComposerJson(): void
    {
        $manifest = (array) $this->loadManifest()->requirements->php;
        $composer = array_filter(
            (array) $this->loadJson(self::ROOT . '/composer.json')->require,
            static fn (string $name): bool => 'php' === $name || str_starts_with($name, 'ext-'),
            ARRAY_FILTER_USE_KEY
        );
        ksort($manifest);
        ksort($composer);

        self::assertSame($composer, $manifest);
    }

    private function loadManifest(): object
    {
        return $this->loadJson(self::ROOT . '/phpcq-plugin.json');
    }

    private function loadJson(string $file): object
    {
        $data = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        self::assertIsObject($data);

        return $data;
    }

    /** @return list<string> */
    private function validate(object $manifest): array
    {
        // The decoded schema object is passed directly so that no remote $id resolution happens.
        $schema    = $this->loadJson(self::ROOT . '/vendor/phpcq/schema/v1/phpcq-plugin-schema.json');
        $validator = new Validator();
        $validator->validate($manifest, $schema);

        return array_values(array_map(
            static fn (array $error): string => sprintf('[%s] %s', $error['property'], $error['message']),
            $validator->getErrors()
        ));
    }
}
