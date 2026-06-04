<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\SensitiveParameterAttributeCollector;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SensitiveParameterAttributeCollector::class)]
#[Small]
final class SensitiveParameterAttributeCollectorTest extends TestCase
{
    private SensitiveParameterAttributeCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new SensitiveParameterAttributeCollector();
    }

    #[Test]
    public function itGetNodeTypeIsParam(): void
    {
        self::assertSame(Param::class, $this->collector->getNodeType());
    }

    #[Test]
    public function itRecordsTheFileWhenAParamCarriesTheSensitiveParameterAttribute(): void
    {
        $param = $this->makeParam('password', withAttribute: true);

        $result = $this->collector->processNode($param, $this->makeScope('/src/Auth.php'));

        self::assertSame('/src/Auth.php', $result);
    }

    #[Test]
    public function itReturnsNullWhenAParamHasNoAttribute(): void
    {
        $param = $this->makeParam('password', withAttribute: false);

        $result = $this->collector->processNode($param, $this->makeScope('/src/Auth.php'));

        self::assertNull($result);
    }

    private function makeParam(string $name, bool $withAttribute): Param
    {
        $attrGroups = [];

        if ($withAttribute) {
            $attrGroups[] = new AttributeGroup([
                new Attribute(new FullyQualified('SensitiveParameter')),
            ]);
        }

        return new Param(
            var: new Variable($name),
            type: new Identifier('string'),
            attrGroups: $attrGroups,
        );
    }

    private function makeScope(string $file): Scope
    {
        $scope = $this->createMockForIntersectionOfInterfaces([NodeCallbackInvoker::class, Scope::class]);
        $scope->method('getFile')->willReturn($file);

        return $scope;
    }
}
