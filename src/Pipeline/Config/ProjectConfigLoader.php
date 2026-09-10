<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\Exception\LegacyBashConfigException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Applies the project's qaConfig/qa.php to the builder. The file returns a
 * closure `static fn (QaConfigBuilder $qa): QaConfigBuilder`; anything else
 * is an error, as is the Bash-era qaConfig.inc.bash still being present.
 *
 * @internal
 */
final readonly class ProjectConfigLoader
{
    public const string FILE = 'qa.php';

    public function __construct(private OutputInterface $output)
    {
    }

    public function apply(QaConfigBuilder $builder, string $projectConfigDir): QaConfigBuilder
    {
        if (is_file($projectConfigDir . '/qaConfig.inc.bash')) {
            throw LegacyBashConfigException::qaConfig($projectConfigDir);
        }

        $file = $projectConfigDir . '/' . self::FILE;
        if (!is_file($file)) {
            return $builder;
        }

        $this->output->writeln('');
        $this->output->writeln('Found project config at ' . $file);

        $closure = require $file;
        if (!\is_callable($closure)) {
            throw new RuntimeException(\sprintf('%s must return a closure taking and returning a QaConfigBuilder, got %s', $file, get_debug_type($closure)));
        }

        $adjusted = $closure($builder);
        if (!$adjusted instanceof QaConfigBuilder) {
            throw new RuntimeException(\sprintf('%s must return the adjusted QaConfigBuilder, got %s', $file, get_debug_type($adjusted)));
        }

        $this->output->writeln('Project config applied');

        return $adjusted;
    }
}
