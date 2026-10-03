<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Dto;

/**
 * One PHPStan rule class active in a project's resolved configuration, with
 * its identifier resolved via RuleDocResolver where the class declares one.
 * A rule class with no IDENTIFIER constant is still listed, with identifier,
 * summary and docPath all null. `$package` names the installed package whose
 * extra.phpstan.includes delivered the rule through phpstan/extension-installer,
 * and is null for a rule the project's own configuration reaches.
 *
 * @internal
 */
final readonly class ActiveRuleEntryDto
{
    public function __construct(
        public string $ruleClass,
        public ?string $identifier,
        public ?string $summary,
        public ?string $docPath,
        public ?string $package = null,
    ) {
    }
}
