<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires app:cron:* commands to include a schedule interval in their description.
 *
 * All cron commands must be self-documenting by including the suggested run interval
 * in square brackets at the end of the description, e.g. "[every 1h]", "[every 15m]".
 *
 * This ensures `php bin/console list app:cron` shows operators exactly how often
 * each command should be scheduled via systemd timers.
 *
 * @implements Rule<Class_>
 */
final class RequireCronIntervalInDescriptionRule implements Rule
{
    private const INTERVAL_PATTERN = '/\[every\s+\d+[mhd]\]$/';

    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $className = $node->name?->toString();
        if ($className === null) {
            return [];
        }

        $asCommandAttr = $this->findAsCommandAttribute($node);
        if ($asCommandAttr === null) {
            return [];
        }

        $name = $this->getNamedArgString($asCommandAttr, 'name', 0);
        if ($name === null || !str_starts_with($name, 'app:cron:')) {
            return [];
        }

        $description = $this->getNamedArgString($asCommandAttr, 'description', 1);
        if ($description === null) {
            return [
                RuleErrorBuilder::message(
                    \sprintf(
                        'Cron command "%s" is missing a description. '
                        . 'Add a description ending with a schedule interval, e.g. [every 1h].',
                        $name,
                    ),
                )->identifier('counselbook.cronMissingInterval')->build(),
            ];
        }

        if (preg_match(self::INTERVAL_PATTERN, $description) !== 1) {
            return [
                RuleErrorBuilder::message(
                    \sprintf(
                        'Cron command "%s" description must end with a schedule interval in square brackets. '
                        . 'Example: "Clean up expired tokens. [every 1h]". '
                        . 'Allowed suffixes: [every Nm], [every Nh], [every Nd].',
                        $name,
                    ),
                )->identifier('counselbook.cronMissingInterval')->build(),
            ];
        }

        return [];
    }

    private function findAsCommandAttribute(Class_ $node): ?Node\Attribute
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attrName = $attr->name->toString();
                if ($attrName === 'Symfony\Component\Console\Attribute\AsCommand'
                    || $attrName === 'AsCommand'
                ) {
                    return $attr;
                }
            }
        }

        return null;
    }

    private function getNamedArgString(Node\Attribute $attr, string $name, int $positionalIndex): ?string
    {
        foreach ($attr->args as $i => $arg) {
            $argName = $arg->name?->toString();

            if ($argName === $name || ($argName === null && $i === $positionalIndex)) {
                if ($arg->value instanceof Node\Scalar\String_) {
                    return $arg->value->value;
                }
            }
        }

        return null;
    }
}
