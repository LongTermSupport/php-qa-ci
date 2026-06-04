<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Param;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Records every occurrence of the native #[\SensitiveParameter] attribute across
 * the analysed codebase.
 *
 * Used by RequireSensitiveParameterUsageRule (which runs on the
 * PHPStan\Node\CollectedDataNode) to assert that the attribute is used at least
 * once. A Collector is the correct PHPStan mechanism for whole-codebase
 * assertions: individual rules only ever see one node at a time, whereas
 * collected data is aggregated and handed to a rule on CollectedDataNode after
 * the whole analysis completes.
 *
 * Each matched parameter contributes the file path it was declared in. The
 * concrete collected value is unimportant — RequireSensitiveParameterUsageRule
 * only inspects the total count — but a string keeps the collected payload
 * cheap and serialisable.
 *
 * @implements Collector<Param, string>
 */
final class SensitiveParameterAttributeCollector implements Collector
{
    /**
     * Attribute short name (without leading namespace separator) that, when
     * present on a parameter, marks it as sensitive.
     */
    private const string ATTRIBUTE_SUFFIX = 'SensitiveParameter';

    public function getNodeType(): string
    {
        return Param::class;
    }

    /**
     * @param Param $node
     */
    public function processNode(Node $node, Scope $scope): ?string
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name->toString();

                if ('SensitiveParameter' === $name || str_ends_with($name, '\\' . self::ATTRIBUTE_SUFFIX)) {
                    return $scope->getFile();
                }
            }
        }

        return null;
    }
}
