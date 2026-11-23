<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use LogicException;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Pipeline\Config\ProjectConfigLoader;
use LTS\PHPQA\Pipeline\Config\ProjectQaConfigReader;
use RuntimeException;

/**
 * The ReleaseVersionPolicy a project declares in qaConfig/qa.php, for
 * bin/changelog-release, which runs outside the pipeline. The file is read by
 * ProjectQaConfigReader, so a qa.php that loads for `bin/qa` loads here;
 * without one the policy is semantic versioning.
 *
 * @internal
 */
final readonly class ReleaseVersionPolicyLoader
{
    private const string CONFIG_DIR = 'qaConfig';

    public function load(string $projectRoot): ReleaseVersionPolicy
    {
        try {
            $config = new ProjectQaConfigReader()->read($projectRoot, \dirname(__DIR__, 2));
        } catch (LogicException|RuntimeException $exception) {
            throw new ChangelogReleaseException(\sprintf('cannot read the release policy from %s/%s: %s', self::CONFIG_DIR, ProjectConfigLoader::FILE, $exception->getMessage()), 0, $exception);
        }

        return $config instanceof \LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto ? $config->releaseVersionPolicy : new ReleaseVersionPolicy();
    }
}
