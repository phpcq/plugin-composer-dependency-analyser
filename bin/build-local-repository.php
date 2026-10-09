<?php

/**
 * Builds a PHPCQ repository containing the plugin of this working copy.
 *
 * The repository is referenced in .phpcq.yaml.dist, so PHPCQ installs the plugin from the local sources and the
 * plugin can be used to analyze this project itself.
 */

declare(strict_types=1);

$rootDir       = dirname(__DIR__);
$repositoryDir = $rootDir . '/.phpcq/repository';
$manifest      = json_decode(
    (string) file_get_contents($rootDir . '/phpcq-plugin.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$pluginFile    = $rootDir . '/' . $manifest['url'];

if (!is_dir($repositoryDir) && !mkdir($repositoryDir, 0777, true) && !is_dir($repositoryDir)) {
    fwrite(STDERR, 'Unable to create directory ' . $repositoryDir . PHP_EOL);
    exit(1);
}

$repository = [
    'plugins' => [
        $manifest['name'] => [
            [
                'api-version'  => $manifest['api-version'],
                // Satisfies "^1.0" in .phpcq.yaml.dist while never clashing with a released version.
                'version'      => '1.0.x-dev',
                'type'         => $manifest['type'],
                'url'          => '../../' . $manifest['url'],
                'requirements' => $manifest['requirements'],
                'checksum'     => [
                    'type'  => 'sha-512',
                    'value' => hash_file('sha512', $pluginFile),
                ],
            ],
        ],
    ],
];

file_put_contents(
    $repositoryDir . '/repository.json',
    json_encode($repository, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
);

echo 'Local PHPCQ repository written to ' . $repositoryDir . '/repository.json' . PHP_EOL;
