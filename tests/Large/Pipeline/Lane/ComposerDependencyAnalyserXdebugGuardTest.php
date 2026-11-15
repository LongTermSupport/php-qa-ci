<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\ComposerDependencyAnalyserTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\RealLaneContext;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Defence for the class "a project guards its test run on the Xdebug mode" (#422).
 *
 * `ini_get('xdebug.mode')` returns the ini value only, and the pipeline sets the mode through the
 * `XDEBUG_MODE` environment variable, so a guard that reads the ini value rejects a correctly
 * started run on a host whose ini says another mode. `xdebug_info('mode')` reports the mode in
 * force, and it uses an extension the project has not declared: the lane reports `ext-xdebug`
 * as a shadow dependency, which is accurate. The shipped default is not narrowed to hide it.
 *
 * This pins what docs/tools/phpunit.md says: the finding appears without the declaration and
 * clears with `ext-xdebug` in `require-dev`, and a guard that reads the environment first and
 * the ini value second calls no `xdebug_*` function, so it raises no finding at all.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ComposerDependencyAnalyserXdebugGuardTest extends TestCase
{
    private const string LIBRARY = __DIR__ . '/../../../..';

    private const string EXT_XDEBUG = 'ext-xdebug';

    private const string GUARD = 'tests/XdebugGuard.php';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-xdebug-guard');
        $this->project->write('src/Probe.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nfinal class Probe\n{\n    public function json(): string\n    {\n        return json_encode([], \\JSON_THROW_ON_ERROR);\n    }\n}\n");
        $this->project->mkdir('qaConfig');
        $this->project->write('vendor/composer/ClassLoader.php', \Safe\file_get_contents(self::LIBRARY . '/vendor/composer/ClassLoader.php'));
        $this->project->write('vendor/autoload.php', implode("\n", [
            '<?php',
            '',
            "require __DIR__ . '/composer/ClassLoader.php';",
            '$loader = new Composer\Autoload\ClassLoader(__DIR__);',
            "\$loader->addPsr4('Probe\\\\', [dirname(__DIR__) . '/src']);",
            "\$loader->addPsr4('Probe\\\\Tests\\\\', [dirname(__DIR__) . '/tests']);",
            '$loader->register(true);',
            '',
            'return $loader;',
            '',
        ]));
        $this->project->write('vendor/composer/installed.json', '{"packages": [], "dev": true}');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aTestThatCallsXdebugInfoIsReportedAsUsingAnUndeclaredExtension(): void
    {
        $this->writeComposerJson('{}');
        $this->project->write(self::GUARD, $this->guard("return xdebug_info('mode');"));

        $output  = new BufferedOutput();
        $result  = new ComposerDependencyAnalyserTool()->run(RealLaneContext::over($this->project->path, true, $output));
        $printed = $output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, $printed);
        self::assertStringContainsString(self::EXT_XDEBUG, $printed);
        self::assertStringContainsString('shadow', strtolower($printed));
    }

    #[Test]
    public function declaringTheExtensionInRequireDevClearsTheFinding(): void
    {
        $this->writeComposerJson('{"' . self::EXT_XDEBUG . '": "*"}');
        $this->project->write(self::GUARD, $this->guard("return xdebug_info('mode');"));

        $output = new BufferedOutput();
        $result = new ComposerDependencyAnalyserTool()->run(RealLaneContext::over($this->project->path, true, $output));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, $output->fetch());
    }

    #[Test]
    public function aGuardThatReadsTheEnvironmentFirstCallsNoXdebugFunctionAndRaisesNoFinding(): void
    {
        $this->writeComposerJson('{}');
        $this->project->write(self::GUARD, $this->guard(
            "\$mode = getenv('XDEBUG_MODE');\n\n        return explode(',', false === \$mode || '' === \$mode ? (string) ini_get('xdebug.mode') : \$mode);",
        ));

        $output = new BufferedOutput();
        $result = new ComposerDependencyAnalyserTool()->run(RealLaneContext::over($this->project->path, true, $output));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, $output->fetch());
    }

    private function writeComposerJson(string $requireDev): void
    {
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "require": {"php": "^8.5", "ext-json": "*"}, "require-dev": ' . $requireDev . ', "autoload": {"psr-4": {"Probe\\\": "src/"}}, "autoload-dev": {"psr-4": {"Probe\\\Tests\\\": "tests/"}}}');
    }

    private function guard(string $body): string
    {
        return implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace Probe\Tests;',
            '',
            'final class XdebugGuard',
            '{',
            '    /** @return list<string> */',
            '    public static function mode(): array',
            '    {',
            '        ' . $body,
            '    }',
            '}',
            '',
        ]);
    }
}
