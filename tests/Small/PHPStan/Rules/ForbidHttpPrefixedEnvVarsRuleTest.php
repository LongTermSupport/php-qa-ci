<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidHttpPrefixedEnvVarsRule;
use LTS\PHPQA\Tests\Support\RecordingFileNodeRule;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * Analyses a real dummy PHP file so the FileNode/Scope are produced by
 * PHPStan itself (constructing FileNode by hand is outside PHPStan's BC
 * promise — see RequireDeclareStrictTypesRuleTest for the same idiom). The
 * rule's own scan target is a fixture PROJECT ROOT under tests/assets/
 * (offending / clean / nonSymfony) passed to its constructor — proving both
 * that it CATCHES the bug class (every HTTP_-prefixed name, across all
 * three config forms plus a .env declaration) and that it does NOT
 * over-match (the non-offending sibling var on the same lines is never
 * flagged, and a project with no Symfony DI/Dotenv installed is skipped
 * entirely).
 *
 * @internal
 *
 * @extends RuleTestCase<ForbidHttpPrefixedEnvVarsRule|RecordingFileNodeRule>
 */
#[CoversClass(ForbidHttpPrefixedEnvVarsRule::class)]
#[Medium]
final class ForbidHttpPrefixedEnvVarsRuleTest extends RuleTestCase
{
    private const string DUMMY_PHP = '/dummy.php';

    private const string HTTP_API_KEY = 'HTTP_API_KEY';

    private const string CONFIG_SERVICES_YAML = '/offending/config/services.yaml';

    private const string ASSETS = __DIR__ . '/../../../assets/httpPrefixedEnvVars';

    private string $projectRootForTest = self::ASSETS . '/clean';

    private ?RecordingFileNodeRule $spy = null;

    #[Test]
    public function getNodeTypeIsFileNode(): void
    {
        self::assertSame(FileNode::class, $this->getRule()->getNodeType());
    }

    #[Test]
    public function offendingFixtureIsFlaggedWithEveryHttpPrefixedNameInDeterministicOrder(): void
    {
        $this->projectRootForTest = self::ASSETS . '/offending';

        // Sorted by (file, line, name) — see the usort in processNode().
        // PHPStan attributes every FileNode-triggered error to the line of
        // dummy.php's FIRST statement (declare(strict_types=1), line 3) —
        // the message text carries the TRUE offending file:line, since
        // PHPStan's own file/line metadata cannot point outside the file
        // actually being analysed (see the rule's docblock).
        $this->analyse([self::ASSETS . self::DUMMY_PHP], [
            [$this->expectedMessage(self::ASSETS . '/offending/.env', 4, self::HTTP_API_KEY), 3],
            [$this->expectedMessage(self::ASSETS . self::CONFIG_SERVICES_YAML, 2, self::HTTP_API_KEY), 3],
            [$this->expectedMessage(self::ASSETS . self::CONFIG_SERVICES_YAML, 8, self::HTTP_API_KEY), 3],
            [$this->expectedMessage(self::ASSETS . self::CONFIG_SERVICES_YAML, 9, 'HTTP_CACHE_DIR'), 3],
        ]);
    }

    #[Test]
    public function cleanFixtureProducesNoErrors(): void
    {
        $this->projectRootForTest = self::ASSETS . '/clean';

        $this->analyse([self::ASSETS . self::DUMMY_PHP], []);
    }

    #[Test]
    public function nonSymfonyProjectIsSkippedEvenThoughItsConfigContainsAnOffender(): void
    {
        // No vendor/symfony/dependency-injection or vendor/symfony/dotenv marker —
        // the carve-out does not apply, so the rule must not even open config/,
        // despite it containing an HTTP_-prefixed reference.
        $this->projectRootForTest = self::ASSETS . '/nonSymfony';

        $this->analyse([self::ASSETS . self::DUMMY_PHP], []);
    }

    /**
     * PHPStan itself orders the errors an analyse() returns, so the rule's own
     * sort is observable only on what the rule itself returns, which a recording
     * wrapper around it keeps. The tree has two .env.* files and no plain .env,
     * a directory and a dangling link that a .env.* glob also matches, a config
     * file with two names on one line, and a Symfony marker of the dotenv kind alone.
     */
    #[Test]
    public function everyOffenderIsReportedOnceAndSortedByFileLineAndName(): void
    {
        $root = TempDir::create('phpqaci-http-env');

        try {
            $root->write('vendor/symfony/dotenv/.keep', '');
            $root->write('config/a.yaml', "x: '%env(HTTP_ONE)%-%env(int:HTTP_TWO)%'\n");
            $root->write('outside/b.yaml', "x: '%env(HTTP_OUTSIDE_CONFIG)%'\n");
            $root->write('.env.b', "APP_X=1\nHTTP_BETA=1\n");
            $root->write('.env.a', "HTTP_ALPHA=1\n");
            $root->mkdir('.env.dir');
            // A dangling link matches the glob but is no file, and reading it would throw.
            \Safe\symlink($root->path . '/missing-target', $root->path . '/.env.dangling');

            $spy       = new RecordingFileNodeRule(new ForbidHttpPrefixedEnvVarsRule($root->path));
            $this->spy = $spy;

            $this->analyse([self::ASSETS . self::DUMMY_PHP, self::ASSETS . '/dummy2.php'], []);

            self::assertSame(
                [
                    [
                        $this->expectedMessage($root->path . '/.env.a', 1, 'HTTP_ALPHA'),
                        $this->expectedMessage($root->path . '/.env.b', 2, 'HTTP_BETA'),
                        $this->expectedMessage($root->path . '/config/a.yaml', 1, 'HTTP_ONE'),
                        $this->expectedMessage($root->path . '/config/a.yaml', 1, 'HTTP_TWO'),
                    ],
                    [],
                ],
                $spy->calls,
                'the first analysed file gets the sorted report, the second none: a rule instance scans once',
            );
        } finally {
            $root->remove();
        }
    }

    protected function getRule(): Rule
    {
        return $this->spy ?? new ForbidHttpPrefixedEnvVarsRule($this->projectRootForTest);
    }

    private function expectedMessage(string $file, int $line, string $name): string
    {
        return \sprintf(
            "%s:%d: env var '%s' is consumed by Symfony but named with an HTTP_ prefix — "
            . 'Symfony treats HTTP_* as an HTTP request header and will NOT read it from '
            . '$_SERVER, so a CLI process (worker/console/cron) resolves it EMPTY. Rename it '
            . 'off the HTTP_ prefix (e.g. HTTP_API_KEY -> APP_API_KEY).',
            $file,
            $line,
            $name,
        );
    }
}
