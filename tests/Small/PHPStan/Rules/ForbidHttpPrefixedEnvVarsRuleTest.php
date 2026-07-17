<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidHttpPrefixedEnvVarsRule;
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
 * @extends RuleTestCase<ForbidHttpPrefixedEnvVarsRule>
 */
#[CoversClass(ForbidHttpPrefixedEnvVarsRule::class)]
#[Medium]
final class ForbidHttpPrefixedEnvVarsRuleTest extends RuleTestCase
{
    private const string ASSETS = __DIR__ . '/../../../assets/httpPrefixedEnvVars';

    private string $projectRootForTest = self::ASSETS . '/clean';

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
        $this->analyse([self::ASSETS . '/dummy.php'], [
            [$this->expectedMessage(self::ASSETS . '/offending/.env', 4, 'HTTP_EGRESS_PROXY'), 3],
            [$this->expectedMessage(self::ASSETS . '/offending/config/services.yaml', 2, 'HTTP_EGRESS_PROXY'), 3],
            [$this->expectedMessage(self::ASSETS . '/offending/config/services.yaml', 8, 'HTTP_EGRESS_PROXY'), 3],
            [$this->expectedMessage(self::ASSETS . '/offending/config/services.yaml', 9, 'HTTP_CACHE_ROOT'), 3],
        ]);
    }

    #[Test]
    public function cleanFixtureProducesNoErrors(): void
    {
        $this->projectRootForTest = self::ASSETS . '/clean';

        $this->analyse([self::ASSETS . '/dummy.php'], []);
    }

    #[Test]
    public function nonSymfonyProjectIsSkippedEvenThoughItsConfigContainsAnOffender(): void
    {
        // No vendor/symfony/dependency-injection or vendor/symfony/dotenv marker —
        // the carve-out does not apply, so the rule must not even open config/,
        // despite it containing an HTTP_-prefixed reference.
        $this->projectRootForTest = self::ASSETS . '/nonSymfony';

        $this->analyse([self::ASSETS . '/dummy.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidHttpPrefixedEnvVarsRule($this->projectRootForTest);
    }

    private function expectedMessage(string $file, int $line, string $name): string
    {
        return \sprintf(
            '%s:%d: env var \'%s\' is consumed by Symfony but named with an HTTP_ prefix — '
            . 'Symfony treats HTTP_* as an HTTP request header and will NOT read it from '
            . '$_SERVER, so a CLI process (worker/console/cron) resolves it EMPTY. Rename it '
            . 'off the HTTP_ prefix (e.g. HTTP_EGRESS_PROXY -> APP_EGRESS_PROXY).',
            $file,
            $line,
            $name,
        );
    }
}
