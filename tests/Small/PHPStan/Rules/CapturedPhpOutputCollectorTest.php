<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\CapturedPhpOutputCollector;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Concat as ConcatAssign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule's own test proves the collector against analysed code; this pins what it records for
 * a write to a variable, which the rule reads when a captured call is given that variable.
 *
 * @internal
 */
#[CoversClass(CapturedPhpOutputCollector::class)]
#[Small]
final class CapturedPhpOutputCollectorTest extends TestCase
{
    use ScopeStubTrait;

    /** The variable every write below is to. */
    private const string COMMAND = 'command';

    /** The line every write below is on. */
    private const int LINE = 7;

    /** The parser's attributes for a node on that line. */
    private const array AT = ['startLine' => self::LINE];

    /** The key of the function every write below is made in. */
    private const string FUNCTION_KEY = '::run';

    #[Test]
    public function aVariableGivenAPhpCommandThatLeavesWarningsOnStdoutIsRecordedAsUnrouted(): void
    {
        $assign = new Assign(new Variable(self::COMMAND), new Concat(new FuncCall(new Name('escapeshellarg'), [new Arg(new ConstFetch(new Name('PHP_BINARY')))]), new String_(' x')), self::AT);

        self::assertSame([[CapturedPhpOutputCollector::WRITE, self::LINE, self::FUNCTION_KEY, self::COMMAND, false]], new CapturedPhpOutputCollector()->processNode($assign, $this->scope()));
    }

    #[Test]
    public function aVariableGivenAPhpArgvThatSendsWarningsToStderrIsRecordedAsRouted(): void
    {
        $argv   = new Array_([new ArrayItem(new PropertyFetch(new Variable('config'), 'phpBinPath')), new ArrayItem(new String_('-d')), new ArrayItem(new String_('display_errors=stderr'))]);
        $assign = new Assign(new Variable(self::COMMAND), $argv, self::AT);

        self::assertSame([[CapturedPhpOutputCollector::WRITE, self::LINE, self::FUNCTION_KEY, self::COMMAND, true]], new CapturedPhpOutputCollector()->processNode($assign, $this->scope()));
    }

    /** A later write of something else replaces what the variable held, so it is recorded too. */
    #[Test]
    public function aVariableGivenAnotherProgramIsRecordedAsNotPhp(): void
    {
        $assign = new Assign(new Variable(self::COMMAND), new String_('git status'), self::AT);

        self::assertSame([[CapturedPhpOutputCollector::WRITE, self::LINE, self::FUNCTION_KEY, self::COMMAND, null]], new CapturedPhpOutputCollector()->processNode($assign, $this->scope()));
    }

    #[Test]
    public function appendingTheSettingRoutesTheCommandAndAppendingAnythingElseRecordsNothing(): void
    {
        $collector = new CapturedPhpOutputCollector();

        self::assertSame(
            [[CapturedPhpOutputCollector::WRITE, self::LINE, self::FUNCTION_KEY, self::COMMAND, true]],
            $collector->processNode(new ConcatAssign(new Variable(self::COMMAND), new String_(' -d display_errors=stderr'), self::AT), $this->scope()),
        );
        self::assertNull($collector->processNode(new ConcatAssign(new Variable(self::COMMAND), new String_(' state'), self::AT), $this->scope()));
    }

    #[Test]
    public function aWriteToSomethingOtherThanANamedVariableIsNotRecorded(): void
    {
        $assign = new Assign(new PropertyFetch(new Variable('this'), self::COMMAND), new String_('php x'));

        self::assertNull(new CapturedPhpOutputCollector()->processNode($assign, $this->scope()));
    }

    /** Literal strings type as themselves; anything else is a string PHPStan knows nothing more about. */
    private function scope(): CollectedDataEmitter&DependencyTracker&NodeCallbackInvoker&Scope
    {
        $scope = self::scopeStub();
        $scope->method('getType')->willReturnCallback(static fn (Expr $expr): Type => $expr instanceof String_ ? new ConstantStringType($expr->value) : new StringType());
        $scope->method('getFunctionName')->willReturn('run');
        $scope->method('getClassReflection')->willReturn(null);

        return $scope;
    }
}
