<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\TwigCsFixerTool;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\RealLaneContext;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "a formatter's cache lets a run pass a file an uncached run fails"
 * (#235) in the Twig CS Fixer lane (#237). twig-cs-fixer caches by default, in
 * `.twig-cs-fixer.cache` in the working directory, and skips any file whose content hash the
 * cache records as clean. A cache that records a pending template as clean therefore lets a
 * cached read-only run pass what CI, which has no cache, fails.
 *
 * twig-cs-fixer 4.1.1 settles a template in one invocation: its fixer repeats its rules until
 * a pass changes nothing, and the cache records a file only when a lint of the final content
 * finds no violation. So no input reproduces #235's two-pass drift, and the first test pins
 * that. The rest pin what holds whatever the fixer does: a read-only run reads and writes no
 * cache, the writable cache lives under var/qa/cache rather than in the project root, and a
 * read-only failure does not leave behind a cache the writable run it asks for would trust.
 *
 * Each test runs the real lane over a probe project and judges the outcome by the phar itself
 * run uncached and read-only, as CI runs it.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class TwigCsFixerToolCacheTest extends TestCase
{
    /** The probe template, relative to the probe project. */
    private const string FILE = 'templates/page.html.twig';

    /** A template the fixer would change: one fix, delimiter spacing. */
    private const string PENDING_TEMPLATE = "{{x}}\n";

    /** What the fixer makes of PENDING_TEMPLATE. */
    private const string CLEAN_TEMPLATE = "{{ x }}\n";

    /** PENDING_TEMPLATE's hash as twig-cs-fixer's cache records it, an MD5 of the content. */
    private const string PENDING_HASH = '61586320a9849b69d671f9ba4464d485';

    /** CLEAN_TEMPLATE's hash as twig-cs-fixer's cache records it. */
    private const string CLEAN_HASH = 'd473c9da32e9e31a75472e125e6d32ca';

    /** twig-cs-fixer's own default cache file, relative to its working directory. */
    private const string ROOT_CACHE = '.twig-cs-fixer.cache';

    /** The lane's cache file, relative to the probe project. */
    private const string LANE_CACHE = 'var/qa/cache/twig-cs-fixer.cache';

    /** The fixer's verdict on a project it would change. */
    private const string PENDING = 'pending fixes';

    /** The fixer's verdict on a project it would leave alone. */
    private const string CLEAN = 'clean';

    private TempDir $project;

    private string $library;

    protected function setUp(): void
    {
        $this->library = \dirname(__DIR__, 4);
        $this->project = TempDir::create('phpqa-twig-cache');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project"}');
        $this->project->mkdir('vendor/twig/twig');
        $this->project->mkdir('var/qa/cache');
        $this->project->write(self::FILE, self::PENDING_TEMPLATE);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * The lane runs one writable pass and no uncached follow-up, because the fixer settles a
     * template in one invocation. If this fails, a later twig-cs-fixer leaves a template a
     * second pass would change, and the lane needs PhpCsFixerTool's follow-up pass.
     */
    #[Test]
    public function theFixerSettlesATemplateInOneInvocation(): void
    {
        $this->project->write(self::FILE, $this->untidyTemplate());
        self::assertSame(self::PENDING, $this->uncachedVerdict());

        $this->assertFixerExit(0, $this->fixer('--no-cache', '--fix'));

        self::assertSame(self::CLEAN, $this->uncachedVerdict(), "twig-cs-fixer left a template a second pass would change, so a writable lane run needs an uncached follow-up pass:\n" . $this->project->read(self::FILE));
    }

    #[Test]
    public function aReadOnlyRunIgnoresACacheThatRecordsAPendingTemplateAsClean(): void
    {
        $this->seedCacheRecordingThePendingTemplateAsClean(self::ROOT_CACHE);
        $this->seedCacheRecordingThePendingTemplateAsClean(self::LANE_CACHE);
        self::assertSame(self::CLEAN, $this->verdict($this->fixer()), 'precondition: the cache records the pending template as clean');
        self::assertSame(self::PENDING, $this->uncachedVerdict(), 'precondition: an uncached run still has a fix to make');

        $output = new BufferedOutput();
        $result = new TwigCsFixerTool()->run($this->context(true, $output));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, "the read-only lane passed a template an uncached run, as in CI, fails:\n" . $output->fetch());
    }

    #[Test]
    public function aReadOnlyRunWritesNoCache(): void
    {
        $this->project->write(self::FILE, self::CLEAN_TEMPLATE);

        $output = new BufferedOutput();
        self::assertSame(ToolOutcomeEnum::Passed, new TwigCsFixerTool()->run($this->context(true, $output))->outcome, $output->fetch());

        self::assertFileDoesNotExist($this->project->path . '/' . self::ROOT_CACHE, 'a read-only run wrote a cache into the project root');
        self::assertFileDoesNotExist($this->project->path . '/' . self::LANE_CACHE, 'a read-only run wrote a cache');
    }

    #[Test]
    public function aWritableRunKeepsItsCacheUnderVarQaNotInTheProjectRoot(): void
    {
        $output = new BufferedOutput();
        $result = new TwigCsFixerTool()->run($this->context(false, $output));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, $output->fetch());
        self::assertSame(self::CLEAN_TEMPLATE, $this->project->read(self::FILE));
        self::assertSame(self::CLEAN, $this->uncachedVerdict());
        self::assertFileDoesNotExist($this->project->path . '/' . self::ROOT_CACHE, "twig-cs-fixer's cache landed in the project root");
        self::assertFileExists($this->project->path . '/' . self::LANE_CACHE, 'a writable run keeps its cache under var/qa/cache');
    }

    #[Test]
    public function aCacheRecordingAPendingTemplateDoesNotOutliveTheReadOnlyRunThatFailsIt(): void
    {
        $this->seedCacheRecordingThePendingTemplateAsClean(self::LANE_CACHE);

        $readOnly = new BufferedOutput();
        self::assertSame(ToolOutcomeEnum::Failed, new TwigCsFixerTool()->run($this->context(true, $readOnly))->outcome, $readOnly->fetch());

        $writable = new BufferedOutput();
        new TwigCsFixerTool()->run($this->context(false, $writable));

        self::assertSame(self::CLEAN, $this->uncachedVerdict(), "the writable run the read-only failure asks for trusted the cache and left the fix unmade, so the read-only run fails for ever:\n" . $writable->fetch());
    }

    /**
     * Writes a cache file, at $cache relative to the probe project, that twig-cs-fixer
     * accepts and that records the pending template as clean. The fixer writes the cache
     * itself over the clean template, so its signature and keys are its own, and then the
     * clean content's hash is swapped for the pending content's.
     */
    private function seedCacheRecordingThePendingTemplateAsClean(string $cache): void
    {
        $path   = $this->project->path . '/' . $cache;
        $config = $this->project->write('var/seed/.twig-cs-fixer.php', \sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nreturn (require %s)->setCacheFile(%s);\n",
            var_export($this->library . '/configDefaults/generic/.twig-cs-fixer.php', true),
            var_export($path, true),
        ));
        $this->project->write(self::FILE, self::CLEAN_TEMPLATE);
        $this->assertFixerExit(0, $this->process('lint', '--config=' . $config, $this->project->path . '/templates'));

        $json = \Safe\file_get_contents($path);
        self::assertStringContainsString(self::CLEAN_HASH, $json, 'twig-cs-fixer did not record the clean template in its cache, or no longer hashes content as this test expects');
        \Safe\file_put_contents($path, str_replace(self::CLEAN_HASH, self::PENDING_HASH, $json));
        $this->project->write(self::FILE, self::PENDING_TEMPLATE);
    }

    /** Several fixes on one template: block and delimiter spacing, punctuation, quotes, operators, indentation, trailing whitespace. */
    private function untidyTemplate(): string
    {
        return implode("\n", [
            '{%block a%}',
            '    {%if x%}',
            '  {%   for i in [1,2 ,3]%}{{i|upper}}{{ i ?  \'a\':"b" }}',
            '        ',
            '   {%endfor%}',
            '{%endif%}',
            '{%endblock%}',
            '{{  {\'a\':1,"b" : [ 1 ,2 ] }  }}',
            '{{- foo(  1,2  )  -}}',
            "\t{{ a|default( 'b' )|raw }}{# comment #}   ",
            "{{ [1,2,3,] }}{{ {a:1,} }}{{ a|filter(v=>v>1) }}\n",
        ]);
    }

    private function context(bool $readOnly, BufferedOutput $output): ToolContext
    {
        return RealLaneContext::over($this->project->path, $readOnly, $output);
    }

    /** What a read-only run with no cache, as in CI, says of the probe project. */
    private function uncachedVerdict(): string
    {
        return $this->verdict($this->fixer('--no-cache'));
    }

    private function verdict(Process $process): string
    {
        $this->assertFixerExit(0 === $process->getExitCode() ? 0 : 1, $process);

        return 0 === $process->getExitCode() ? self::CLEAN : self::PENDING;
    }

    /** The phar over the probe templates with the shipped config, from the project root as the lane runs it. */
    private function fixer(string ...$options): Process
    {
        $config = '--config=' . $this->library . '/configDefaults/generic/.twig-cs-fixer.php';

        return $this->process(...['lint', $config, ...array_values($options), $this->project->path . '/templates']);
    }

    private function process(string ...$arguments): Process
    {
        $process = new Process(
            [\PHP_BINARY, '-d', 'display_errors=stderr', $this->library . '/vendor-phar/twig-cs-fixer.phar', ...$arguments],
            $this->project->path,
            ['TMPDIR' => $this->project->path],
            null,
            600,
        );
        $process->run();

        return $process;
    }

    private function assertFixerExit(int $expected, Process $process): void
    {
        self::assertSame($expected, $process->getExitCode(), "twig-cs-fixer could not process the probe project:\n" . $process->getOutput() . $process->getErrorOutput());
    }
}
