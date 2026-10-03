<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use Nette\Neon\Neon;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "an issue that does not say which release line it was found on".
 *
 * A bug is fixed on `php8.4` only when a project running `php8.4` reports it (CLAUDE.md,
 * "Work happens on `php8.5`"), so the line is what decides whether a report triggers a
 * backport. Every issue form therefore asks for it as a required choice, blank issues are
 * off so no issue bypasses the forms, and the choices are exactly the live lines: the one
 * this branch's composer.json requires, then the older lines still taking fixes.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class IssueFormsTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../..';

    private const string FORMS_DIR = self::REPO_ROOT . '/.github/ISSUE_TEMPLATE';

    /** The chooser's own configuration, not a form. */
    private const string CHOOSER = 'config.yml';

    /** The id every form gives its release-line question. */
    private const string RELEASE_LINE_ID = 'release-line';

    /**
     * The older lines still taking fixes, newest first. `php8.3` is dead and is not one of
     * them. Changing this list is changing the support ruling in CLAUDE.md, never only here.
     *
     * @var list<string>
     */
    private const array MAINTAINED_OLDER_LINES = ['php8.4'];

    #[Test]
    public function blankIssuesAreOffSoEveryIssueComesThroughAForm(): void
    {
        self::assertFileExists(self::FORMS_DIR . '/' . self::CHOOSER, 'with no chooser configuration GitHub offers a blank issue');
        $chooser = $this->decode(\Safe\file_get_contents(self::FORMS_DIR . '/' . self::CHOOSER));

        self::assertFalse($chooser['blank_issues_enabled'] ?? null, self::CHOOSER . ' must set blank_issues_enabled: false');
    }

    #[Test]
    public function everyFormRequiresTheReleaseLineFromTheLiveLines(): void
    {
        $forms = $this->forms();
        self::assertNotSame([], $forms, 'no issue form under .github/ISSUE_TEMPLATE, so nothing asks for the release line');

        $problems = [];
        foreach ($forms as $path => $form) {
            foreach ($this->releaseLineProblems($form) as $problem) {
                $problems[] = basename($path) . ': ' . $problem;
            }
        }

        self::assertSame([], $problems);
    }

    #[Test]
    public function theCurrentLineIsTheOneComposerJsonRequires(): void
    {
        self::assertSame('php8.5', $this->liveLines()[0]);
    }

    /** The guard proven to fire, on forms written to fail each check in turn. */
    #[Test]
    public function theGuardReportsEachWayAFormCanDrift(): void
    {
        $dropdown = static fn (string $options, string $required): string => <<<NEON
            name: Bug
            description: x
            body:
              - type: dropdown
                id: release-line
                attributes:
                  label: Release line
                  options: [{$options}]
                validations:
                  required: {$required}
            NEON;

        self::assertSame(['asks no release-line question'], $this->releaseLineProblems($this->decode("name: Bug\ndescription: x\nbody: []\n")));
        self::assertSame(['the release line is optional'], $this->releaseLineProblems($this->decode($dropdown('php8.5, php8.4', 'false'))));
        self::assertSame(
            ['offers php8.5, php8.4, php8.3 where the live lines are php8.5, php8.4'],
            $this->releaseLineProblems($this->decode($dropdown('php8.5, php8.4, php8.3', 'true'))),
        );
        self::assertSame([], $this->releaseLineProblems($this->decode($dropdown('php8.5, php8.4', 'true'))));
    }

    /**
     * @param array<array-key, mixed> $form
     *
     * @return list<string>
     */
    private function releaseLineProblems(array $form): array
    {
        $body     = \is_array($form['body'] ?? null) ? $form['body'] : [];
        $question = null;
        foreach ($body as $element) {
            if (\is_array($element) && self::RELEASE_LINE_ID === ($element['id'] ?? null)) {
                $question = $element;
            }
        }

        if (null === $question || 'dropdown' !== ($question['type'] ?? null)) {
            return ['asks no release-line question'];
        }

        $problems = [];
        if (true !== ($question['validations']['required'] ?? null)) {
            $problems[] = 'the release line is optional';
        }

        $options = $question['attributes']['options'] ?? null;
        if ($options !== $this->liveLines()) {
            $problems[] = \sprintf(
                'offers %s where the live lines are %s',
                \is_array($options) ? implode(', ', array_map(strval(...), $options)) : 'nothing',
                implode(', ', $this->liveLines()),
            );
        }

        return $problems;
    }

    /** @return list<string> the line composer.json requires, then the maintained older lines */
    private function liveLines(): array
    {
        $manifest = \Safe\json_decode(\Safe\file_get_contents(self::REPO_ROOT . '/composer.json'), true);
        self::assertIsArray($manifest);
        $php = $manifest['require']['php'] ?? null;
        self::assertIsString($php);
        self::assertSame(1, \Safe\preg_match('/^\^(\d+)\.(\d+)$/', $php, $version), 'composer.json require.php is not a ^X.Y constraint');

        return ['php' . $version[1] . '.' . $version[2], ...self::MAINTAINED_OLDER_LINES];
    }

    /** @return array<string, array<array-key, mixed>> path => decoded form */
    private function forms(): array
    {
        $forms = [];
        foreach (\Safe\glob(self::FORMS_DIR . '/*.yml') as $path) {
            if (self::CHOOSER !== basename($path)) {
                $forms[$path] = $this->decode(\Safe\file_get_contents($path));
            }
        }

        return $forms;
    }

    /** @return array<array-key, mixed> */
    private function decode(string $yaml): array
    {
        $decoded = Neon::decode($yaml);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
