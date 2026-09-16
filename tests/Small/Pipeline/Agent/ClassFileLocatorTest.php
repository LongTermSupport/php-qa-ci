<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Agent;

use LTS\PHPQA\Pipeline\Agent\ClassFileLocator;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClassFileLocator::class)]
#[Small]
final class ClassFileLocatorTest extends TestCase
{
    private const string OPEN = "<?php\n\ndeclare(strict_types=1);\n\n";

    private const string THING_BODY = "namespace App;\n\nfinal class Thing\n{\n}\n";

    private const string SRC_THING = 'src/Thing.php';

    private const string APP_THING = 'App\Thing';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-locator');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aClassIsFoundAtThePathItsShortNameImplies(): void
    {
        $file = $this->project->write(self::SRC_THING, self::OPEN . self::THING_BODY);

        self::assertSame($file, $this->locator()->locate(self::APP_THING));
    }

    #[Test]
    public function aNestedClassIsFoundWithoutTheNamespaceMatchingTheDirectoryLayout(): void
    {
        $file = $this->project->write('src/Deep/Nested/Thing.php', self::OPEN . "namespace Totally\\Different;\n\nfinal class Thing\n{\n}\n");

        self::assertSame($file, $this->locator()->locate('Totally\Different\Thing'));
    }

    #[Test]
    public function twoClassesSharingAShortNameAreSeparatedByTheirDeclaredNamespace(): void
    {
        $first  = $this->project->write('src/One/Thing.php', self::OPEN . "namespace App\\One;\n\nfinal class Thing\n{\n}\n");
        $second = $this->project->write('src/Two/Thing.php', self::OPEN . "namespace App\\Two;\n\nfinal class Thing\n{\n}\n");

        $locator = $this->locator();

        self::assertSame($first, $locator->locate('App\One\Thing'));
        self::assertSame($second, $locator->locate('App\Two\Thing'));
    }

    #[Test]
    public function anInterfaceEnumOrTraitIsLocatedTheSameWayAsAClass(): void
    {
        $interface = $this->project->write('src/ThingInterface.php', self::OPEN . "namespace App;\n\ninterface ThingInterface\n{\n}\n");
        $enum      = $this->project->write('src/ThingEnum.php', self::OPEN . "namespace App;\n\nenum ThingEnum: string\n{\n    case A = 'a';\n}\n");
        $trait     = $this->project->write('src/ThingTrait.php', self::OPEN . "namespace App;\n\ntrait ThingTrait\n{\n}\n");

        $locator = $this->locator();

        self::assertSame($interface, $locator->locate('App\ThingInterface'));
        self::assertSame($enum, $locator->locate('App\ThingEnum'));
        self::assertSame($trait, $locator->locate('App\ThingTrait'));
    }

    #[Test]
    public function aClassInTheGlobalNamespaceIsFound(): void
    {
        $file = $this->project->write('src/Bare.php', self::OPEN . "final class Bare\n{\n}\n");

        self::assertSame($file, $this->locator()->locate('Bare'));
    }

    #[Test]
    public function aClassThatIsNotInTheSetResolvesToNullRatherThanToAGuess(): void
    {
        $this->project->write(self::SRC_THING, self::OPEN . self::THING_BODY);

        self::assertNull($this->locator()->locate('App\Absent'));
    }

    #[Test]
    public function aNamespaceMismatchOnTheOnlyCandidateResolvesToNull(): void
    {
        $this->project->write(self::SRC_THING, self::OPEN . "namespace App\\One;\n\nfinal class Thing\n{\n}\n");

        self::assertNull(
            $this->locator()->locate('App\Two\Thing'),
            'the wrong file is worse than no file: it would attribute a violation to innocent code',
        );
    }

    #[Test]
    public function aMissingClassSetDirectoryIsNotAnError(): void
    {
        $locator = new ClassFileLocator($this->project->path . '/does-not-exist');

        self::assertNull($locator->locate(self::APP_THING));
    }

    #[Test]
    public function aNonPhpFileSharingTheNameIsIgnored(): void
    {
        $this->project->write('src/Thing.txt', 'not php');
        $file = $this->project->write('src/Sub/Thing.php', self::OPEN . self::THING_BODY);

        self::assertSame($file, $this->locator()->locate(self::APP_THING));
    }

    private function locator(): ClassFileLocator
    {
        return new ClassFileLocator($this->project->path . '/src');
    }
}
