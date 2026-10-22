<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Rector;

use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "a shipped Rector config drops a constant's declared type".
 *
 * Rector's SplitGroupedClassConstantsRector splits `const int B = 2, C = 3;` into one
 * statement per constant, but builds the new statements without the group's type, so
 * `C` loses `int`. In a final class AddTypeToConstRector puts the type back from the
 * value; in a class that is not final it does not, and the declared type is gone, a
 * change of meaning a writable run commits.
 *
 * Every shipped config the rector lane runs over source paths is run, writable, over
 * grouped typed constants in a final and an open class, an interface and a trait, and
 * every constant must still declare its type.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ShippedRectorKeepsConstantTypesTest extends TestCase
{
    /** The php-qa-ci checkout whose shipped configs and phar are under test. */
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /** The Rector configs the rector lane runs over source paths, in its order. */
    private const array RECTOR_CONFIGS = ['rector-safe.php', 'rector-php85.php'];

    /** Each probe file => its source, every constant grouped and typed. */
    private const array PROBES = [
        'OpenGroup.php'      => "class OpenGroup\n{\n    public const int B = 2, C = 3;\n\n    protected const string D = 'd', E = 'e';\n}\n",
        'FinalGroup.php'     => "final class FinalGroup\n{\n    public const int B = 2, C = 3;\n}\n",
        'GroupInterface.php' => "interface GroupInterface\n{\n    public const int B = 2, C = 3;\n}\n",
        'GroupTrait.php'     => "trait GroupTrait\n{\n    public const int B = 2, C = 3;\n}\n",
    ];

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-rector-const-types');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "require": {"php": "^8.5", "thecodingmachine/safe": "^3.0"},"autoload": {"psr-4": {"Probe\\\": "src/"}}}');
        foreach (self::PROBES as $name => $body) {
            $this->project->write('src/' . $name, "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\n" . $body);
        }
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function everyConstantKeepsItsDeclaredType(): void
    {
        foreach (self::RECTOR_CONFIGS as $config) {
            $process = new Process(
                [\PHP_BINARY, '-d', 'display_errors=stderr', \Safe\realpath(self::REPO_ROOT . '/vendor-phar/rector.phar'), 'process', '--config', \Safe\realpath(self::REPO_ROOT . '/configDefaults/generic/' . $config), '--clear-cache', '--no-progress-bar', 'src'],
                $this->project->path,
                ['TMPDIR' => $this->project->path],
                null,
                600,
            );
            $process->run();
            self::assertSame(0, $process->getExitCode(), 'Rector ' . $config . " could not process the probes:\n" . $process->getOutput() . $process->getErrorOutput());
        }

        $untyped = [];
        foreach (array_keys(self::PROBES) as $name) {
            foreach (explode("\n", $this->project->read('src/' . $name)) as $line) {
                if (1 === \Safe\preg_match('/\bconst\s+[A-Z]+\s*=/', $line)) {
                    $untyped[] = $name . ': ' . trim($line);
                }
            }
        }

        self::assertSame([], $untyped, 'A shipped Rector config removed the declared type from these constants.');
    }
}
