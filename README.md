# composer-dependency-analyser plugin for phpcq

This plugin provides [composer-dependency-analyser](https://github.com/shipmonk-rnd/composer-dependency-analyser)
integration for [phpcq](https://github.com/phpcq/phpcq).

composer-dependency-analyser does not ship a PHAR, so the plugin pulls in
`shipmonk/composer-dependency-analyser` (`^1.0`) as a Composer requirement and runs the installed
`vendor/bin/composer-dependency-analyser` through the PHP interpreter.

## Reported findings

Each finding becomes one diagnostic. Every diagnostic refers to your `composer.json` (with the line of the
package entry for unused, dev-in-prod and prod-only-in-dev dependencies) and additionally to every usage
location reported by the tool.

| Finding | Severity |
|---|---|
| Unknown class / unknown function | major |
| Shadow dependency (used, but not listed in `composer.json`) | major |
| Dev dependency used in production code | major |
| Prod dependency used only in dev paths | minor |
| Unused dependency | minor |
| Ignored error that was never applied | minor |

If the tool fails (invalid configuration, missing `vendor/autoload.php`, …) a fatal diagnostic with the
error message is reported and the raw output is attached as `output.log` / `error.log`.

## Configuration

| Option | Type | Default | Description |
|---|---|---|---|
| `config` | string | – | Tool configuration file (PHP returning a `Configuration`). Without it the tool uses `composer-dependency-analyser.php` in the project root if it exists. |
| `composer_json` | string | `composer.json` | Path to the analysed `composer.json`. |
| `ignore_unknown_classes` | bool | `false` | Do not report unknown classes. |
| `ignore_unknown_functions` | bool | `false` | Do not report unknown functions. |
| `ignore_shadow_deps` | bool | `false` | Do not report shadow dependencies. |
| `ignore_unused_deps` | bool | `false` | Do not report unused dependencies. |
| `ignore_dev_in_prod_deps` | bool | `false` | Do not report dev dependencies used in production code. |
| `ignore_prod_only_in_dev_deps` | bool | `false` | Do not report prod dependencies used only in dev paths. |
| `disable_ext_analysis` | bool | `false` | Disable the analysis of `ext-*` usages. |

Paths to scan or exclude and fine grained ignores are configured in the tool's own configuration file,
see the [composer-dependency-analyser documentation](https://github.com/shipmonk-rnd/composer-dependency-analyser#configuration-).

## Example

```yaml
phpcq:
  plugins:
    composer-dependency-analyser:
      version: ^1.0
      signed: false

tasks:
  verify:
    - composer-dependency-analyser

  composer-dependency-analyser:
    config:
      config: composer-dependency-analyser.php
```

## Development

`composer install` and `composer update` run `bin/build-local-repository.php`, which writes a phpcq repository
containing the plugin of the current checkout to `.phpcq/repository/repository.json`. `.phpcq.yaml.dist` lists this
repository first, so `phpcq update` installs the local plugin and the project analyses itself with it. After changing
the plugin, run `composer install` (or the script directly) followed by `phpcq update` to pick up the changes.
