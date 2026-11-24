<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

use LogicException;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The configuration a project declares in qaConfig/qa.php, for a program that runs outside the
 * pipeline (bin/changelog-release, bin/actions-qa-install). The file is applied by the loader the
 * pipeline uses, to a builder seeded as a read-only CI run is, so a qa.php that loads for
 * `bin/qa` loads here.
 *
 * @internal
 */
final readonly class ProjectQaConfigReader
{
    private const string CONFIG_DIR = 'qaConfig';

    /**
     * The project's configuration, or null when it has no qaConfig/qa.php, in which case nothing
     * about the project is read.
     *
     * @throws LogicException|RuntimeException when the qa.php does not load or does not build
     */
    public function read(string $projectRoot, string $libraryRoot): ?QaConfigDto
    {
        $projectRoot = rtrim($projectRoot, '/');
        $configDir   = $projectRoot . '/' . self::CONFIG_DIR;
        if (!is_file($configDir . '/' . ProjectConfigLoader::FILE)) {
            return null;
        }

        $builder = QaConfigBuilder::defaults(
            paths: $this->paths($projectRoot, rtrim($libraryRoot, '/'), $configDir),
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

        return new ProjectConfigLoader(new NullOutput())->apply($builder, $configDir)->build();
    }

    /** The project's directories by convention; such a program reads none of them, but qa.php may. */
    private function paths(string $projectRoot, string $libraryRoot, string $configDir): ProjectPathsDto
    {
        $varDir = $projectRoot . '/var/qa';

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
