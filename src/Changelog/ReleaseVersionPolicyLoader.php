<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use LogicException;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Pipeline\Config\ComposerBinDirReader;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformDetector;
use LTS\PHPQA\Pipeline\Config\ProjectConfigLoader;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The ReleaseVersionPolicy a project declares in qaConfig/qa.php, for
 * bin/changelog-release, which runs outside the pipeline. The file is applied
 * to a builder seeded as the pipeline seeds it, so a qa.php that loads for
 * `bin/qa` loads here; without one the policy is semantic versioning.
 *
 * @internal
 */
final readonly class ReleaseVersionPolicyLoader
{
    private const string CONFIG_DIR = 'qaConfig';

    public function load(string $projectRoot): ReleaseVersionPolicy
    {
        $projectRoot = rtrim($projectRoot, '/');
        $configDir   = $projectRoot . '/' . self::CONFIG_DIR;
        if (!is_file($configDir . '/' . ProjectConfigLoader::FILE)) {
            return new ReleaseVersionPolicy();
        }

        try {
            $builder = QaConfigBuilder::defaults(
                paths: $this->paths($projectRoot, $configDir),
                platform: new PlatformDetector()->detect($projectRoot),
                env: new EnvironmentReader(EnvironmentReader::fromProcess()),
                phpBinPath: 'php',
                xdebugEnabled: false,
                halfCpuThreads: 1,
                ci: true,
                readOnly: true,
                aggregate: false,
                jsonOutput: false,
                agentMode: false,
                singleTool: null,
                specifiedPath: null,
            );

            return new ProjectConfigLoader(new NullOutput())->apply($builder, $configDir)->build()->releaseVersionPolicy;
        } catch (LogicException|RuntimeException $exception) {
            throw new ChangelogReleaseException(\sprintf('cannot read the release policy from %s/%s: %s', self::CONFIG_DIR, ProjectConfigLoader::FILE, $exception->getMessage()), 0, $exception);
        }
    }

    /** The project's directories by convention; the release CLI reads none of them, but qa.php may. */
    private function paths(string $projectRoot, string $configDir): ProjectPathsDto
    {
        $libraryRoot = \dirname(__DIR__, 2);
        $varDir      = $projectRoot . '/var/qa';

        return new ProjectPathsDto(
            projectRoot: $projectRoot,
            libraryRoot: $libraryRoot,
            binDir: $projectRoot . '/' . new ComposerBinDirReader()->read($projectRoot),
            srcDir: $projectRoot . '/src',
            testsDir: $projectRoot . '/tests',
            projectConfigDir: $configDir,
            varDir: $varDir,
            cacheDir: $varDir . '/cache',
            pharDir: $libraryRoot . '/vendor-phar',
            configDefaultsDir: $libraryRoot . '/configDefaults',
        );
    }
}
