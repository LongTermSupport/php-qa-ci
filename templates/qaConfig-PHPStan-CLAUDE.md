# Custom PHPStan Rules

Project-specific PHPStan rules that enforce architectural patterns and prevent bug classes.

Deployed by php-qa-ci. See https://ltscommerce.dev/articles/defence-before-fix-static-analysis

## Directory Structure

```
qaConfig/
├── phpstan.neon              <- Register rules here
└── PHPStan/
    ├── CLAUDE.md             <- This file
    └── Rules/
        └── YourRule.php      <- Custom rules go here
```

## Creating a New Rule

### 1. Create the Rule Class

```php
<?php

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rule description: what pattern this catches and why it is dangerous.
 *
 * @implements Rule<Node\SomeNodeType>
 */
final class YourNewRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\SomeNodeType::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Detection logic here
        // Return empty array if no violation
        // Return array with RuleErrorBuilder if violation found
        return [
            RuleErrorBuilder::message('Clear explanation of what is wrong and how to fix it')
                ->identifier('yourRule.violationType')
                ->build(),
        ];
    }
}
```

### 2. Register in phpstan.neon

```neon
rules:
    - QaConfig\PHPStan\Rules\YourNewRule
```

### 3. Test the Rule

Run PHPStan to verify the rule catches the pattern:
```bash
export CI=true && bin/qa -t stan
```

## Defence Before Fix Pattern

When creating rules as part of the "Defence Before Fix" strategy:

1. **Analyse** -- Understand the bug pattern from production incident
2. **Create Rule** -- Write a PHPStan rule that catches ALL instances of the pattern
3. **Run PHPStan** -- Verify the rule detects existing violations
4. **TDD** -- Write failing tests for the specific bugs
5. **Fix** -- Implement fixes, then verify both PHPStan and tests pass

The rule creates permanent defence -- the bug class can never recur in future commits.

## Namespace

- **Namespace:** `QaConfig\PHPStan\Rules`
- **Autoloaded via:** `composer.json` `autoload-dev` PSR-4 entry mapping
  `QaConfig\` to `qaConfig/`
- **Available types:** Use `PhpParser\Node\*` for AST nodes, `PHPStan\Analyser\Scope`
  for type information

## Important

- NEVER put rules in `src/PHPStan/` -- that is production code
- PHPStan and PhpParser are dev-only dependencies
- Rules must return `list<\PHPStan\Rules\IdentifierRuleError>` (use `RuleErrorBuilder`)
- Use `.identifier()` on every error for baseline management
- Error messages should explain both WHAT is wrong and HOW to fix it
