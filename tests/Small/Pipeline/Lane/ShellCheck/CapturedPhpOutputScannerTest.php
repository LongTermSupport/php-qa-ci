<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\ShellCheck;

use Iterator;
use LTS\PHPQA\Pipeline\Lane\ShellCheck\CapturedPhpOutputScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CapturedPhpOutputScanner::class)]
#[Small]
final class CapturedPhpOutputScannerTest extends TestCase
{
    /** A PHP script the project runs by its shebang. */
    private const string PHP_SCRIPT = 'describe-exit';

    #[Test]
    #[DataProvider('capturedPhp')]
    public function aCommandSubstitutionRunningPhpWithItsWarningsOnStdoutIsReported(string $line): void
    {
        self::assertSame([2], $this->scan("#!/usr/bin/env bash\n" . $line . "\n"));
    }

    /** @return Iterator<string, array{string}> */
    public static function capturedPhp(): Iterator
    {
        yield 'php by name' => ['v="$(php "$phar" --version | awk \'NR==1\')"'];
        yield 'php with a version' => ['v=$(php8.5 -r "echo 1;")'];
        yield 'php by path' => ['v=$(/usr/bin/php -v)'];
        yield 'inside a message' => ['echo "failed with $(php "$ROOT/bin/describe-exit" "$rc")" >&2'];
        yield 'a PHP variable with a default' => ['echo "$("${PHP_BINARY:-php}" "$QACI_PATH/bin/describe-exit" 1)"'];
        yield 'a PHP variable' => ['v=$($PHP x.php)'];
        yield 'an assignment before the program' => ['v=$(XDEBUG_MODE=off php x.php)'];
        yield 'a backtick substitution' => ['v=`php x.php`'];
        yield 'a PHP script of the project, by its path' => ['v="$("$binDir/' . self::PHP_SCRIPT . '" 1)"'];
        yield 'a PHP script under a path built by a substitution' => ['v=$("$(composer config bin-dir)/' . self::PHP_SCRIPT . '" next-version)'];
        yield 'a setting that keeps display_errors on stdout' => ['v=$(php -d display_errors=1 x.php)'];
    }

    #[Test]
    #[DataProvider('notCapturedPhp')]
    public function aLineThatDoesNotCapturePhpWithItsWarningsOnStdoutIsNotReported(string $line): void
    {
        self::assertSame([], $this->scan("#!/usr/bin/env bash\n" . $line . "\n"));
    }

    /** @return Iterator<string, array{string}> */
    public static function notCapturedPhp(): Iterator
    {
        yield 'warnings sent to stderr' => ['v="$(php -d display_errors=stderr "$phar" --version)"'];
        yield 'warnings switched off' => ['v=$(php -ddisplay_errors=0 x.php)'];
        yield 'PHP whose output is not captured' => ['php "$ROOT/bin/turbo-install" state >&2'];
        yield 'another program' => ['v=$(git rev-parse HEAD)'];
        yield 'a program whose name starts with php' => ['v=$(phpunit --version)'];
        yield 'php as an argument' => ['v=$(command -v php)'];
        yield 'arithmetic' => ['n=$((1 + 2))'];
        yield 'a comment' => ['# v=$(php x.php)'];
        yield 'composer, whose own handler keeps its warnings off stdout' => ['dir=$(composer config bin-dir)'];
    }

    #[Test]
    public function aContinuedCommandIsReportedOnTheLineItStarts(): void
    {
        self::assertSame([2], $this->scan("#!/usr/bin/env bash\nv=\"\$(php \\\n  x.php)\"\n"));
        self::assertSame([], $this->scan("#!/usr/bin/env bash\nv=\"\$(php \\\n  -d display_errors=stderr x.php)\"\n"));
    }

    /** A quoted delimiter makes the body literal, so a substitution in it never runs; an unquoted one expands it. */
    #[Test]
    public function aHereDocumentIsReadOnlyWhenItsBodyIsExpanded(): void
    {
        self::assertSame([], $this->scan("cat <<'EOF'\nRun `php x.php` or \$(php y.php).\nEOF\nv=\$(git log)\n"));
        self::assertSame([], $this->scan("python3 - << \"PY\"\nprint('\$(php x)')\nPY\n"));
        self::assertSame([2, 4], $this->scan("cat <<EOF\nVersion: \$(php -v)\nEOF\nv=\$(php x.php)\n"));
    }

    #[Test]
    public function aVariableHoldingTheProgramIsReadFromItsAssignmentInTheSameScript(): void
    {
        $script = 'RELEASE="$(composer config bin-dir)/' . self::PHP_SCRIPT . "\"\nversion=\$(\"\${RELEASE}\" next-version)\n";

        self::assertSame([2], $this->scan($script));
    }

    #[Test]
    public function aWorkflowRunScriptIsReadTheSameWay(): void
    {
        $workflow = "jobs:\n  build:\n    steps:\n      - run: |\n          version=\$(php \"\$phar\" --version 2>/dev/null | awk 'NR==1' || true)\n";

        self::assertSame([5], $this->scan($workflow));
    }

    #[Test]
    public function everySubstitutionOnALineIsReadOnItsOwn(): void
    {
        self::assertSame([1], $this->scan("echo \"\$(git rev-parse HEAD) \$(php -v)\"\n"));
        self::assertSame([], $this->scan("echo \"\$(php -d display_errors=stderr -v) \$(git rev-parse HEAD)\"\n"));
    }

    /** @return list<int> */
    private function scan(string $script): array
    {
        return new CapturedPhpOutputScanner(self::PHP_SCRIPT)->scan($script);
    }
}
