# Platform Detection

PHPQA can amend its behaviour for specific platforms.

By default there is a generic set of lanes and configuration; a recognised platform adds lanes of its own and may adjust defaults.

## The `PlatformDetector`

[PlatformDetector](../src/Pipeline/Config/PlatformDetector.php) runs during the preflight of every `bin/qa` run, against the project root.

It checks for a single platform-specific marker:
- **Symfony**: presence of `symfony.lock`
- **Generic**: default for all other PHP projects

There is no Laravel (`artisan`) detection; the only cases of [PlatformEnum](../src/Pipeline/Config/PlatformEnum.php) are `Symfony` and `Generic`. The detected platform is part of the built configuration and is announced at the start of the run.

## What the platform changes

**Platform lanes.** `ToolRegistry::platformLanes()` appends a platform's own lanes to a phase, after the generic ones. Symfony adds two to the linting phase:

- [TwigLintTool](../src/Pipeline/Lane/TwigLintTool.php) runs `bin/console lint:twig` over the twig directories (see [tools/twigLint.md](./tools/twigLint.md))
- [YamlLintTool](../src/Pipeline/Lane/YamlLintTool.php) runs `bin/console lint:yaml` over the yaml directories (see [tools/yamlLint.md](./tools/yamlLint.md))

Neither is `-t` selectable; both skip cleanly when the console command or the bundle is absent.

**Directories.** On a Symfony project the twig directories default to `templates/` and the yaml directories to `config/`. Override them in `qaConfig/qa.php`:

```php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withTwigDirectories('templates', 'src/Admin/templates')
    ->withYamlDirectories('config', 'translations');
```

Paths may be absolute or project-relative.

**Config files.** [ConfigPathResolver](../src/Pipeline/Config/ConfigPathResolver.php) looks for a `configDefaults/(platform)/(configFile)` rung between your `qaConfig/` override and the generic default. No `configDefaults/(platform)/` folder ships, so config lookups fall through to `configDefaults/generic/` unless your project supplies its own copy.
