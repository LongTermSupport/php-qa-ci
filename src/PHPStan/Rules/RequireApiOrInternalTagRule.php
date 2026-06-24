<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use LTS\PHPQA\PackageType\ProjectComposerTypeReader;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Package-type-aware API-surface classification.
 *
 * When the consuming project's composer `type` is `library` (an installable
 * dependency — and Composer's silent default when `type` is omitted), every
 * public class-like is part of the consumer contract and MUST be classified as
 * exactly one of:
 *   - `@api`      — a supported public contract; changing it later is a breaking
 *                   change for consumers, so apply it conservatively/deliberately.
 *   - `@internal` — not for consumers; the library may change it freely.
 *
 * Neither tag ⇒ "classify it" (Missing); both ⇒ "contradiction" (Both). This is
 * the only enforceable model — no tool infers "non-`@api` ⇒ internal" — so the
 * rule forces the choice to be MADE; choosing correctly is the author's
 * deliberate, two-sided judgement (under-mark `@internal` over-restricts
 * consumers; over-mark `@api` commits the library to a contract). Safe default:
 * `@internal`, promote to `@api` only what consumers genuinely need.
 *
 * For any other package type (`project` application, `metapackage`, …) there is
 * no consumer-facing surface, so the rule no-ops.
 *
 * Generated / managed trees that cannot carry hand-authored tags are exempted via
 * the `ignoredNamespacePrefixes` parameter (e.g. `App\Generated`, `App\PhpQaCi`).
 *
 * @see ApiOrInternalTagDetector for the pure decision core.
 *
 * @implements Rule<InClassNode>
 */
final class RequireApiOrInternalTagRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.requireApiOrInternalTag';

    /**
     * @param list<string> $ignoredNamespacePrefixes fully-qualified namespace prefixes whose
     *                                                class-likes are exempt (generated/managed code)
     */
    public function __construct(
        private readonly ProjectComposerTypeReader $typeReader,
        private readonly ApiOrInternalTagDetector $detector,
        private readonly array $ignoredNamespacePrefixes = [],
    ) {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        // Anonymous classes have no name to classify and form no public contract.
        if ($classReflection->isAnonymous()) {
            return [];
        }

        $className = $classReflection->getName();

        foreach ($this->ignoredNamespacePrefixes as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return [];
            }
        }

        $docText     = $this->docText($node);
        $hasApi      = $this->hasTag($docText, 'api');
        $hasInternal = $this->hasTag($docText, 'internal');

        $verdict = $this->detector->classify($this->typeReader->effectiveType(), $hasApi, $hasInternal);

        $message = match ($verdict) {
            ApiOrInternalTagVerdict::Missing => \sprintf(
                'Class %s is part of a library\'s public surface but is not classified. '
                . 'Add exactly one of @api (a supported public contract — changing it later breaks '
                . 'consumers) or @internal (not for consumers) to its docblock. '
                . 'Safe default: @internal; promote to @api only what consumers genuinely need '
                . 'and the library will support long-term.',
                $className,
            ),
            ApiOrInternalTagVerdict::Both => \sprintf(
                'Class %s declares BOTH @api and @internal, which is contradictory. '
                . 'Choose exactly one: @api (a supported public contract) or @internal (not for consumers).',
                $className,
            ),
            ApiOrInternalTagVerdict::Ok => null,
        };

        if (null === $message) {
            return [];
        }

        return [
            RuleErrorBuilder::message($message)->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function docText(InClassNode $node): string
    {
        $docComment = $node->getOriginalNode()->getDocComment();

        return $docComment instanceof \PhpParser\Comment\Doc ? $docComment->getText() : '';
    }

    /**
     * Matches a standalone `@api` / `@internal` PHPDoc tag, not a longer tag that
     * merely starts with it (e.g. `@apiNote`, `@internalRef`).
     */
    private function hasTag(string $docText, string $tag): bool
    {
        return 1 === \Safe\preg_match('/@' . $tag . '(?![\w-])/', $docText);
    }
}
