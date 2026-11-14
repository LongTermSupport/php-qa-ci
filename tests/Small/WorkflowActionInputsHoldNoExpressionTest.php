<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Tests\Support\WorkflowFiles;
use LTS\PHPQA\Tests\Support\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "an expression in the input of an action": `${{ }}` is substituted into the
 * workflow before the action reads its input, so an action that runs an input as a command or a
 * script runs whatever a pull request title, branch name or commit message says. A `run:` or a
 * `github-script` `script:` is held by `PipelineTemplateRunsOncePerPushTest`; this holds the rest.
 *
 * The rule is deny by default: an input of an action that holds an expression is reported unless
 * the pair is on the list of inputs the action reads as plain data. A new action, or a new input of
 * a listed one, therefore fails until someone classifies it, and the fix that is always correct is
 * to pass the value through `env:` and read the variable inside the script.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class WorkflowActionInputsHoldNoExpressionTest extends TestCase
{
    /**
     * Inputs read as plain data (a key, a path, a version, a branch name, a title), by action. The
     * evidence for each is the input's description in the action's own `action.yml` at the commit
     * the workflows pin; the local action passes its inputs on through `env:`. Each entry exempts an
     * input from the scan, so adding one is a classification the Owner may overturn.
     */
    private const array PLAIN_DATA = [
        'actions/cache'                     => ['key', 'path', 'restore-keys'],
        'actions/checkout'                  => ['ref'],
        'actions/download-artifact'         => ['path'],
        'actions/upload-artifact'           => ['path'],
        'peter-evans/create-pull-request'   => ['base', 'body-path', 'branch', 'commit-message', 'title'],
        'shivammathur/setup-php'            => ['php-version'],
        './.github/actions/approve-held-ci' => ['head-sha', 'token', 'workflow'],
    ];

    /** The key that names the action a step runs. */
    private const string USES = 'uses';

    /** The key that holds the inputs a step passes to its action. */
    private const string WITH = 'with';

    /** The text that opens an expression the runner substitutes before the action reads its input. */
    private const string EXPRESSION = '${{';

    #[Test]
    public function noInputOfAnActionHoldsAnExpressionUnlessItsActionReadsItAsData(): void
    {
        $found = [];
        foreach ($this->workflowFiles() as $file) {
            foreach ($this->expressionInputsOf(WorkflowNode::ofFile($file)) as $input) {
                $found[] = basename($file) . ': ' . $input;
            }
        }

        self::assertSame(
            [],
            $found,
            "An action input holds an expression, and an action may run an input as a command or a script, so a value written by someone else becomes code. Pass it through `env:` and read the variable inside the action, or, if the action reads that input as plain data, list the pair in PLAIN_DATA with the evidence.\n",
        );
    }

    #[Test]
    public function everyInputOnThePlainDataListIsOneAWorkflowStillPassesAnExpressionIn(): void
    {
        $used = [];
        foreach ($this->workflowFiles() as $file) {
            $used = [...$used, ...$this->inputsWithAnExpressionIn(WorkflowNode::ofFile($file))];
        }

        self::assertSame(
            [],
            $this->staleAllowances(self::PLAIN_DATA, ...$used),
            'These inputs are listed as plain data but no workflow passes an expression in them: remove the entry, so the list holds only what is used.',
        );
    }

    #[Test]
    public function theGuardFindsAnExpressionInTheInputOfAnActionThatIsNotListed(): void
    {
        $workflow = WorkflowNode::ofYaml(<<<'YAML'
            jobs:
              qa:
                steps:
                  - uses: some/action@0123456789012345678901234567890123456789
                    with:
                      args: ${{ github.head_ref }}
                      plain: no expression here
            YAML);

        self::assertSame(['some/action: args'], $this->expressionInputsOf($workflow));
    }

    #[Test]
    public function theGuardFindsAnExpressionInAnInputOfAListedActionThatIsNotListedForIt(): void
    {
        $workflow = WorkflowNode::ofYaml(<<<'YAML'
            jobs:
              qa:
                steps:
                  - uses: actions/checkout@0123456789012345678901234567890123456789
                    with:
                      ref: ${{ github.head_ref }}
                      repository: ${{ github.event.pull_request.title }}
            YAML);

        self::assertSame(['actions/checkout: repository'], $this->expressionInputsOf($workflow));
    }

    #[Test]
    public function theGuardAcceptsAnExpressionInAListedInputAndSeesAStepAtAnyDepth(): void
    {
        $workflow = WorkflowNode::ofYaml(<<<'YAML'
            runs:
              using: composite
              steps:
                - uses: actions/cache@0123456789012345678901234567890123456789
                  with:
                    key: ${{ runner.os }}-composer
                - uses: other/action@0123456789012345678901234567890123456789
                  with:
                    input: ${{ steps.one.outputs.value }}
            YAML);

        self::assertSame(['other/action: input'], $this->expressionInputsOf($workflow));
    }

    #[Test]
    public function theGuardFindsAnExpressionInAListOrAMappingUnderAnInput(): void
    {
        $workflow = WorkflowNode::ofYaml(<<<'YAML'
            jobs:
              qa:
                steps:
                  - uses: some/action@0123456789012345678901234567890123456789
                    with:
                      args:
                        - --safe
                        - ${{ github.head_ref }}
                      options:
                        deep:
                          deeper: ${{ github.event.pull_request.title }}
                      clean:
                        - --safe
                        - no expression here
            YAML);

        self::assertSame(['some/action: args', 'some/action: options'], $this->expressionInputsOf($workflow));
    }

    #[Test]
    public function theGuardFindsAnExpressionThatIsTheWholeWith(): void
    {
        $workflow = WorkflowNode::ofYaml(<<<'YAML'
            jobs:
              qa:
                steps:
                  - uses: some/action@0123456789012345678901234567890123456789
                    with: ${{ fromJSON(github.event.pull_request.title) }}
            YAML);

        self::assertSame(['some/action: with'], $this->expressionInputsOf($workflow));
    }

    #[Test]
    public function theGuardFindsAnExpressionInTheNameOfAnInput(): void
    {
        $workflow = WorkflowNode::ofYaml(<<<'YAML'
            jobs:
              qa:
                steps:
                  - uses: some/action@0123456789012345678901234567890123456789
                    with:
                      '${{ github.head_ref }}': value
                      plain: value
            YAML);

        self::assertSame(['some/action: ${{ github.head_ref }}'], $this->expressionInputsOf($workflow));
    }

    #[Test]
    public function theGuardFindsAnExpressionInAKeyBelowTheInput(): void
    {
        $workflow = WorkflowNode::ofYaml(<<<'YAML'
            jobs:
              qa:
                steps:
                  - uses: some/action@0123456789012345678901234567890123456789
                    with:
                      options:
                        '${{ github.head_ref }}': value
                      clean:
                        plain: value
            YAML);

        self::assertSame(['some/action: options'], $this->expressionInputsOf($workflow));
    }

    #[Test]
    public function theGuardNamesAnAllowanceNoWorkflowUsesAndKeepsOneItDoes(): void
    {
        $allowed = ['actions/cache' => ['key', 'path'], 'unused/action' => ['input']];

        self::assertSame(
            ['actions/cache: path', 'unused/action: input'],
            $this->staleAllowances($allowed, 'actions/cache: key'),
        );
    }

    /**
     * Every `uses:` step with a `with:` mapping, at any depth, as "<action>: <input>" for each
     * input holding an expression that the action is not listed as reading as plain data.
     *
     * @return list<string>
     */
    private function expressionInputsOf(WorkflowNode $workflow): array
    {
        return array_values(array_filter(
            $this->inputsWithAnExpressionIn($workflow),
            static function (string $pair): bool {
                [$action, $input] = explode(': ', $pair, 2);

                return !\in_array($input, self::PLAIN_DATA[$action] ?? [], true);
            },
        ));
    }

    /**
     * The same walk, without the list: every input holding an expression, as "<action>: <input>".
     *
     * @return list<string>
     */
    private function inputsWithAnExpressionIn(WorkflowNode $workflow): array
    {
        $found = [];
        foreach ($this->stepsPassingInputs($workflow) as $step) {
            $action = explode('@', $step->child(self::USES)->text())[0];
            $with   = $step->child(self::WITH);
            if (!$with->isCollection()) {
                if ($this->holdsAnExpression($with)) {
                    $found[] = $action . ': ' . self::WITH;
                }

                continue;
            }

            foreach ($with->entries() as $input => $value) {
                if (str_contains((string)$input, self::EXPRESSION) || $this->holdsAnExpression($value)) {
                    $found[] = $action . ': ' . $input;
                }
            }
        }

        return $found;
    }

    /** Whether a string, a key or a value at any depth under this node holds an expression. */
    private function holdsAnExpression(WorkflowNode $node): bool
    {
        if (str_contains($node->text(), self::EXPRESSION)) {
            return true;
        }

        return array_any($node->entries(), fn (WorkflowNode $child, $key): bool => str_contains((string)$key, self::EXPRESSION) || $this->holdsAnExpression($child));
    }

    /**
     * The entries of the list that none of the inputs used names, as "<action>: <input>".
     *
     * @param array<string, list<string>> $allowed
     *
     * @return list<string>
     */
    private function staleAllowances(array $allowed, string ...$used): array
    {
        $stale = [];
        foreach ($allowed as $action => $inputs) {
            foreach ($inputs as $input) {
                if (!\in_array($action . ': ' . $input, $used, true)) {
                    $stale[] = $action . ': ' . $input;
                }
            }
        }

        return $stale;
    }

    /** @return list<WorkflowNode> every step, at any depth, that names an action and has a `with:` */
    private function stepsPassingInputs(WorkflowNode $node): array
    {
        $found = [];
        if (null !== $node->child(self::USES)->string() && $node->has(self::WITH)) {
            $found[] = $node;
        }

        foreach ($node->entries() as $child) {
            $found = [...$found, ...$this->stepsPassingInputs($child)];
        }

        return $found;
    }

    /** @return list<string> */
    private function workflowFiles(): array
    {
        $files = [
            ...WorkflowFiles::in(__DIR__ . '/../../.github/workflows'),
            ...WorkflowFiles::actionsIn(__DIR__ . '/../../.github/actions'),
            ...WorkflowFiles::in(__DIR__ . '/../../templates/github-actions'),
            ...WorkflowFiles::actionsIn(__DIR__ . '/../../templates/github-actions'),
        ];
        sort($files);
        self::assertNotSame([], $files, 'No workflow files found: the directory paths are wrong.');

        return $files;
    }
}
