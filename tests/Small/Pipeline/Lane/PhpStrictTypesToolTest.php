<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\PhpStrictTypesTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpStrictTypesTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\IgnoredPaths::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class PhpStrictTypesToolTest extends TestCase
{
    private const string SRC_A_PHP = 'src/A.php';

    private const string PHP_FINAL_CLASS_A = '<?php

final class A {}
';

    private const string FIXED_FINAL_CLASS_A = "<?php declare(strict_types=1);\n\nfinal class A {}\n";

    private const string ALL_DECLARE = "All PHP files declare strict_types\n";

    private const string MISSING_HEADER = "Files missing declare(strict_types=1):\n";

    private const string READ_ONLY_ADVICE = "\n"
        . "This is a READ-ONLY run, so nothing was modified. Add the declaration\n"
        . "(or run 'QA_READONLY=0 vendor/bin/qa -t st' where writes are allowed), then commit.\n";

    private const string UNFIXABLE_HEADER = "\n"
        . "ERROR: could not add declare(strict_types=1) to the following files (no opening <?php tag found) — fix them manually:\n";

    private const string IDENTIFIER_FOOTER = "\n🪪  phpqaci.phpStrictTypes  (vendor/bin/rule-doc phpqaci.phpStrictTypes)\n";

    private const string NO_OPENING_TAG = "no opening tag here\n";

    private const string FIXED = 'fixed: ';

    private const string SRC_NOTES_TXT = 'src/notes.txt';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function everyFileDeclaringStrictTypesPasses(): void
    {
        $this->factory->project->write(self::SRC_A_PHP, "<?php\n\ndeclare(strict_types=1);\n");
        $this->factory->project->write('src/view.phtml', "<?php declare(strict_types=1); ?>\n<p>hi</p>\n");
        $this->factory->project->write(self::SRC_NOTES_TXT, 'not php');

        $result = new PhpStrictTypesTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('', $result->summary);
        self::assertSame(self::ALL_DECLARE, $this->factory->output->fetch());
    }

    #[Test]
    public function aReadOnlyRunListsTheOffendersAndFailsWithoutTouchingThem(): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->write(self::SRC_A_PHP, self::PHP_FINAL_CLASS_A);
        $this->factory->project->write('tests/deep/BTest.php', "<?php\nfinal class BTest {}\n");

        $result  = new PhpStrictTypesTool()->run($this->factory->context($this->factory->builder(readOnly: true)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('2 file(s) missing declare(strict_types=1)', $result->summary);
        self::assertSame(
            self::MISSING_HEADER
            . '  ' . $root . "/tests/deep/BTest.php\n"
            . '  ' . $root . '/' . self::SRC_A_PHP . "\n"
            . self::READ_ONLY_ADVICE
            . self::IDENTIFIER_FOOTER,
            $printed,
        );
        self::assertSame(self::PHP_FINAL_CLASS_A, $this->factory->project->read(self::SRC_A_PHP));
    }

    /**
     * The offenders are listed in sorted order whatever order the directory
     * yields them in. The files are written neither sorted nor reverse sorted,
     * so neither a creation-ordered nor a reverse-creation-ordered directory
     * listing (tmpfs) is already sorted.
     */
    #[Test]
    public function theOffendersInADirectoryAreListedInSortedOrder(): void
    {
        $root  = $this->factory->project->path;
        $names = ['src/d.php', 'src/a.php', 'src/f.php', 'src/c.php', 'src/e.php', 'src/b.php'];
        foreach ($names as $name) {
            $this->factory->project->write($name, "<?php\n");
        }

        $config = $this->factory->builder(readOnly: true, specifiedPath: 'src')->build();
        $result = new PhpStrictTypesTool()->run($this->factory->context($config));

        sort($names);
        $listed = '';
        foreach ($names as $name) {
            $listed .= '  ' . $root . '/' . $name . "\n";
        }

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('6 file(s) missing declare(strict_types=1)', $result->summary);
        self::assertSame(
            self::MISSING_HEADER . $listed . self::READ_ONLY_ADVICE . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aFileUnderAnIgnoredPathIsNeitherReportedNorRewritten(): void
    {
        $root    = $this->factory->project->path;
        $fixture = "<?php\n\nfinal class Fixture {}\n";
        $path    = 'tests/assets/Fixture.php';
        $sibling = 'tests/assetsExtra/B.php';
        $this->factory->project->write($path, $fixture);
        $this->factory->project->write($sibling, self::PHP_FINAL_CLASS_A);

        $config = $this->factory->builder(readOnly: false)->withIgnoredPaths('tests/assets')->build();

        $result = new PhpStrictTypesTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            self::MISSING_HEADER
            . '  ' . $root . '/' . $sibling . "\n"
            . self::FIXED . $root . '/' . $sibling . "\n"
            . "\n"
            . "Added declare(strict_types=1) to 1 file(s) — review and commit the changes.\n",
            $this->factory->output->fetch(),
        );
        self::assertSame($fixture, $this->factory->project->read($path), 'a fixture is data, never fixed');
        self::assertSame(self::FIXED_FINAL_CLASS_A, $this->factory->project->read($sibling), 'a sibling sharing the prefix is not ignored');
    }

    #[Test]
    public function aWritableRunAddsTheDeclarationToTheOpeningTag(): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->write(self::SRC_A_PHP, self::PHP_FINAL_CLASS_A);
        $this->factory->project->write('tests/BTest.php', "#!/usr/bin/env php\n<?php\nfinal class BTest {}\n");

        $result  = new PhpStrictTypesTool()->run($this->factory->context($this->factory->builder(readOnly: false)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('', $result->summary);
        self::assertSame(
            self::MISSING_HEADER
            . '  ' . $root . "/tests/BTest.php\n"
            . '  ' . $root . '/' . self::SRC_A_PHP . "\n"
            . self::FIXED . $root . "/tests/BTest.php\n"
            . self::FIXED . $root . '/' . self::SRC_A_PHP . "\n"
            . "\n"
            . "Added declare(strict_types=1) to 2 file(s) — review and commit the changes.\n",
            $printed,
        );
        self::assertSame(self::FIXED_FINAL_CLASS_A, $this->factory->project->read(self::SRC_A_PHP));
        self::assertSame(
            "#!/usr/bin/env php\n<?php declare(strict_types=1);\nfinal class BTest {}\n",
            $this->factory->project->read('tests/BTest.php'),
            'the declaration goes on the opening tag, wherever it is',
        );
    }

    /**
     * An unfixable file does not stop the loop: the fixable file after it is
     * still fixed, and the unfixable file after that is still listed.
     */
    #[Test]
    public function aFileWithNoOpeningTagCannotBeFixedAndFailsTheWritableRun(): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->write('src/B1broken.php', self::NO_OPENING_TAG);
        $this->factory->project->write('src/B2.php', self::PHP_FINAL_CLASS_A);
        $this->factory->project->write('src/B3broken.phtml', self::NO_OPENING_TAG);

        $result  = new PhpStrictTypesTool()->run($this->factory->context($this->factory->builder(readOnly: false)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('2 file(s) could not be given a strict_types declaration', $result->summary);
        self::assertSame(
            self::MISSING_HEADER
            . '  ' . $root . "/src/B1broken.php\n"
            . '  ' . $root . "/src/B2.php\n"
            . '  ' . $root . "/src/B3broken.phtml\n"
            . self::FIXED . $root . "/src/B2.php\n"
            . self::UNFIXABLE_HEADER
            . '  ' . $root . "/src/B1broken.php\n"
            . '  ' . $root . "/src/B3broken.phtml\n"
            . self::IDENTIFIER_FOOTER,
            $printed,
        );
        self::assertSame(self::FIXED_FINAL_CLASS_A, $this->factory->project->read('src/B2.php'), 'the fixable file is still fixed');
        self::assertSame(self::NO_OPENING_TAG, $this->factory->project->read('src/B1broken.php'));
        self::assertSame(self::NO_OPENING_TAG, $this->factory->project->read('src/B3broken.phtml'));
    }

    #[Test]
    public function aSpecifiedPathThatIsASingleFileIsScanned(): void
    {
        $root = $this->factory->project->path;
        $this->factory->project->write(self::SRC_A_PHP, "<?php\nfinal class A {}\n");
        $this->factory->project->write('src/Other.php', "<?php\nfinal class Other {}\n");

        $config = $this->factory->builder(readOnly: true, specifiedPath: self::SRC_A_PHP)->build();

        $result = new PhpStrictTypesTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame(
            self::MISSING_HEADER
            . '  ' . $root . '/' . self::SRC_A_PHP . "\n"
            . self::READ_ONLY_ADVICE
            . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aSpecifiedPathThatIsANonPhpFileIsNotScanned(): void
    {
        $this->factory->project->write(self::SRC_NOTES_TXT, 'no strict types here');
        $config = $this->factory->builder(readOnly: true, specifiedPath: self::SRC_NOTES_TXT)->build();

        $result = new PhpStrictTypesTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(self::ALL_DECLARE, $this->factory->output->fetch());
    }

    #[Test]
    public function aMissingPathIsSimplyEmpty(): void
    {
        $config = $this->factory->builder(readOnly: true, specifiedPath: 'nope')->build();

        $result = new PhpStrictTypesTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(self::ALL_DECLARE, $this->factory->output->fetch());
    }
}
