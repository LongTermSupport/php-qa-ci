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
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[Small]
final class PhpStrictTypesToolTest extends TestCase
{
    private const string SRC_A_PHP = 'src/A.php';

    private const string PHP_FINAL_CLASS_A = '<?php

final class A {}
';

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
        $this->factory->project->write('src/notes.txt', 'not php');

        $result = new PhpStrictTypesTool()->run($this->factory->context());

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('All PHP files declare strict_types', $this->factory->output->fetch());
    }

    #[Test]
    public function aReadOnlyRunListsTheOffendersAndFailsWithoutTouchingThem(): void
    {
        $this->factory->project->write(self::SRC_A_PHP, self::PHP_FINAL_CLASS_A);
        $this->factory->project->write('tests/deep/BTest.php', "<?php\nfinal class BTest {}\n");

        $result  = new PhpStrictTypesTool()->run($this->factory->context($this->factory->builder(readOnly: true)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('Files missing declare(strict_types=1):', $printed);
        self::assertStringContainsString(self::SRC_A_PHP, $printed);
        self::assertStringContainsString('tests/deep/BTest.php', $printed);
        self::assertStringContainsString('READ-ONLY run', $printed);
        self::assertStringContainsString(PhpStrictTypesTool::IDENTIFIER, $printed);
        self::assertSame(self::PHP_FINAL_CLASS_A, $this->factory->project->read(self::SRC_A_PHP));
    }

    #[Test]
    public function aWritableRunAddsTheDeclarationToTheOpeningTag(): void
    {
        $this->factory->project->write(self::SRC_A_PHP, self::PHP_FINAL_CLASS_A);

        $result  = new PhpStrictTypesTool()->run($this->factory->context($this->factory->builder(readOnly: false)->build()));
        $printed = $this->factory->output->fetch();

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('fixed: ', $printed);
        self::assertStringContainsString('Added declare(strict_types=1) to 1 file(s)', $printed);
        self::assertSame("<?php declare(strict_types=1);\n\nfinal class A {}\n", $this->factory->project->read(self::SRC_A_PHP));
    }

    #[Test]
    public function aFileWithNoOpeningTagCannotBeFixedAndFailsTheWritableRun(): void
    {
        $this->factory->project->write(self::SRC_A_PHP, self::PHP_FINAL_CLASS_A);
        $this->factory->project->write('src/broken.php', "no opening tag here\n");

        $result  = new PhpStrictTypesTool()->run($this->factory->context($this->factory->builder(readOnly: false)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('fixed: ', $printed, 'the fixable file is still fixed');
        self::assertStringContainsString('no opening <?php tag found', $printed);
        self::assertStringContainsString('src/broken.php', $printed);
        self::assertStringContainsString(PhpStrictTypesTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aSpecifiedPathThatIsASingleFileIsScanned(): void
    {
        $this->factory->project->write(self::SRC_A_PHP, "<?php\nfinal class A {}\n");
        $config = $this->factory->builder(readOnly: true, specifiedPath: self::SRC_A_PHP)->build();

        $result = new PhpStrictTypesTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
    }

    #[Test]
    public function aMissingPathIsSimplyEmpty(): void
    {
        $config = $this->factory->builder(readOnly: true, specifiedPath: 'nope')->build();

        self::assertTrue(new PhpStrictTypesTool()->run($this->factory->context($config))->isSuccess());
    }
}
