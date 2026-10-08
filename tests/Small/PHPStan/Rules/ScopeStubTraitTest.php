<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a test double that does not satisfy the type the analyser passes".
 *
 * The rule tests pass ScopeStubTrait's double where PHPStan passes its scope. PHPStan states that
 * scope's full type in the PHPDoc of Rule::processNode(), and widens it between minor releases:
 * 2.3.0 added DependencyTracker. Each such release would otherwise surface as a hundred-odd
 * argument.type findings with no pointer to the one place that needs the new interface. Here the
 * expected set is read from the shipped phpstan.phar, so a dependency update that widens it fails
 * this test by name.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class ScopeStubTraitTest extends TestCase
{
    use ScopeStubTrait;

    #[Test]
    public function theDoubleSatisfiesEveryInterfaceRuleProcessNodeDeclaresForItsScope(): void
    {
        $stub = self::scopeStub();

        foreach ($this->declaredScopeInterfaces() as $interface) {
            self::assertInstanceOf($interface, $stub, \sprintf('Rule::processNode() declares its scope as including %s; add it to ScopeStubTrait and to each rule test\'s scope() return type', $interface));
        }
    }

    /** @return list<class-string> the intersection in `@param ... $scope` of Rule::processNode() */
    private function declaredScopeInterfaces(): array
    {
        $doc = new \ReflectionMethod(Rule::class, 'processNode')->getDocComment();
        self::assertIsString($doc);
        \Safe\preg_match('/@param\s+(\S+)\s+\$scope\b/', $doc, $matches);
        $declared = $matches[1] ?? null;
        self::assertIsString($declared, 'Rule::processNode() documents no $scope type; re-read how PHPStan types it');

        $interfaces = [];
        foreach (explode('&', $declared) as $type) {
            $class = ltrim($type, '\\');
            self::assertTrue(interface_exists($class), \sprintf('%s, from Rule::processNode()\'s $scope type, is not an interface', $class));
            $interfaces[] = $class;
        }

        return $interfaces;
    }
}
