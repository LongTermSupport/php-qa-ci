<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PackageType;

use LTS\PHPQA\PackageType\ExplicitPackageTypeDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure decision tests for {@see ExplicitPackageTypeDetector}: a project's
 * `composer.json` MUST declare `type` explicitly (Composer's silent default is
 * `library`) so the app-vs-library decision is conscious. Returns null when OK,
 * else an actionable error message.
 *
 * @internal
 */
#[CoversClass(ExplicitPackageTypeDetector::class)]
#[Small]
final class ExplicitPackageTypeDetectorTest extends TestCase
{
    private ExplicitPackageTypeDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new ExplicitPackageTypeDetector();
    }

    #[Test]
    public function anExplicitLibraryTypeIsAccepted(): void
    {
        self::assertNull($this->detector->check(['type' => 'library']));
    }

    #[Test]
    public function anExplicitProjectTypeIsAccepted(): void
    {
        self::assertNull($this->detector->check(['type' => 'project']));
    }

    #[Test]
    public function anyOtherExplicitComposerTypeIsAccepted(): void
    {
        // metapackage / composer-plugin / php-ext etc. are explicit declarations —
        // the rule only forbids RELYING ON THE DEFAULT, not these valid types.
        self::assertNull($this->detector->check(['type' => 'composer-plugin']));
        self::assertNull($this->detector->check(['type' => 'metapackage']));
    }

    #[Test]
    public function anAbsentTypeIsRejectedWithGuidance(): void
    {
        $message = $this->detector->check(['name' => 'acme/widget']);

        self::assertIsString($message);
        self::assertStringContainsString('type', $message);
        self::assertStringContainsString('library', $message);
        self::assertStringContainsString('project', $message);
    }

    #[Test]
    public function aBlankTypeIsRejected(): void
    {
        self::assertIsString($this->detector->check(['type' => '']));
        self::assertIsString($this->detector->check(['type' => '   ']));
    }

    #[Test]
    public function aNonStringTypeIsRejected(): void
    {
        self::assertIsString($this->detector->check(['type' => null]));
        self::assertIsString($this->detector->check(['type' => ['library']]));
    }

    /**
     * The rejection message is the actionable guidance a developer sees when the
     * build fails: it must spell out both valid choices ("library" / "project"),
     * WHY the explicit declaration matters (Composer's silent default), and the
     * downstream consequence (a library's public surface is @api/@internal
     * checked). Pinning the exact wording guards that guidance against
     * accidental truncation or re-ordering of its constituent sentences.
     */
    #[Test]
    public function theRejectionMessageIsTheExactActionableGuidance(): void
    {
        $expected = 'composer.json must declare "type" explicitly: "library" (an installable dependency) '
            . 'or "project" (an application). Composer silently defaults an omitted "type" to "library", '
            . 'which hides the app-vs-library decision — declare it so the choice is conscious. '
            . 'A library additionally has its public surface checked: every public class must carry '
            . '@api or @internal.';

        self::assertSame($expected, $this->detector->check(['name' => 'acme/widget']));
    }
}
