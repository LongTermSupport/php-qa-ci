<?php

declare(strict_types=1);

/**
 * This is the default PHP-CS-Fixer configs for PHPQA projects.
 *
 * You can override this file by copying it into your qaConfig folder and editing as you see fit
 *
 * For rules, suggest you have a look at
 *
 * @see https://mlocati.github.io/php-cs-fixer-configurator/
 *
 * PHP 8.5 Compatibility Note:
 * - This config includes PHP 8.5 migration rules via @PHP8x5Migration (cumulative
 *   over the 8.4 set, so the implicit-nullable fixes stay in force)
 * - PHP CS Fixer v3.95+ supports PHP 8.5 natively (no PHP_CS_FIXER_IGNORE_ENV needed)
 */

use Composer\Autoload\ClassLoader;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$rules = [
    '@PhpCsFixer'                         => true,
    '@Symfony'                            => true,
    '@DoctrineAnnotation'                 => true,
    '@PHP8x5Migration'                    => true,
    'align_multiline_comment'             => true,
    'array_indentation'                   => true,
    'array_syntax'                        => ['syntax' => 'short'],
    'blank_line_after_opening_tag'        => true,
    'binary_operator_spaces'              => [
        'default' => 'align',
        'operators' => [
            '=>' => 'align_single_space_by_scope'
        ]
    ],
    'cast_spaces'                         => ['space' => 'none'],
    'concat_space'                        => ['spacing' => 'one'],
    'declare_strict_types'                => true,
    'final_class'                         => true,
    'ordered_class_elements'              => [
        'order' => [
            'use_trait',
            'constant_public',
            'constant_protected',
            'constant_private',
            'property_public',
            'property_protected',
            'property_private',
            'construct',
            'destruct',
            'magic',
            'phpunit',
            'method_public',
            'method_protected',
            'method_private',
        ],
    ],
    // phpcs/phpcbf have been removed from the pipeline in favor of PHP CS Fixer
    'ordered_imports'                     => [
        'sort_algorithm' => 'alpha',
        // this is the PSR12 order, do not change
        'imports_order'  => [
            'class',
            'function',
            'const',
        ],
    ],
    'modernize_types_casting'             => true,
    //    'php_unit_strict'              => [
    //        'assertions' => [
    //            'assertAttribuassertionsteEquals',
    //            'assertAttributeNotEquals',
    //            'assertEquals',
    //            'assertNotEquals',
    //        ],
    //    ],
    // this one is not compatible with attributes
    // 'php_unit_size_class'          => ['group' => 'small'],
    // this one is not compatible with attributes
    'php_unit_test_class_requires_covers' => false,
    'psr_autoloading'                     => true,
    'return_assignment'                   => true,
    'self_accessor'                       => true,
    'static_lambda'                       => true,
    'strict_comparison'                   => true,
    'strict_param'                        => true,
    // Disabled deliberately: this fixer rewrites `isset($x) ? $x : ''` (and similar ternaries defaulting
    // to '') into `$x ?? ''`, which this package's own ForbidNullCoalescingEmptyStringRule (PHPStan,
    // rules-optional.neon) BANS — the two would contradict by construction. See the matching
    // TernaryToNullCoalescingRector skip in rector-php85.php for the full rationale.
    'ternary_to_null_coalescing'          => false,
    'void_return'                         => true,
    'yoda_style'                          => [
        'equal'     => true,
        'identical' => true,
    ],
    'fully_qualified_strict_types'        => true,
    'native_function_invocation'          => true,
    'method_argument_space'               => [
        'after_heredoc'                    => true,
        'keep_multiple_spaces_after_comma' => true,
        'on_multiline'                     => 'ensure_fully_multiline',
    ],
    'single_line_throw'                   => false,
    'global_namespace_import'             => true,
    'phpdoc_to_return_type'               => false,
    'no_superfluous_phpdoc_tags'          => true,
    'phpdoc_to_comment'                   => false,    // otherwise we cant use @var comments to help stan understand things
    // PHP 8.4 specific rules
    'nullable_type_declaration_for_default_null_value' => true, // Critical for PHP 8.4 compatibility
    'nullable_type_declaration'           => ['syntax' => 'question_mark'], // Use ? syntax for nullable types
];

$projectRoot = (static function () {
    $reflection = new ReflectionClass(ClassLoader::class);

    return \dirname($reflection->getFileName(), 3);
})();
if (str_starts_with($projectRoot, 'phar')) {
    // When CS Fixer runs as a PHAR, the ClassLoader reflection gives a phar:// path.
    // Use getcwd() which is always the actual project root (set by the QA pipeline).
    $projectRoot = getcwd();
}
$finderPath   = __DIR__ . '/php_cs_finder.php';
$overridePath = "{$projectRoot}/qaConfig/php_cs_finder.php";
if (file_exists($overridePath)) {
    $finderPath = $overridePath;
}

$finder = require $finderPath;

return (new PhpCsFixer\Config())
    ->setRules($rules)
    ->setFinder($finder)
    ->setParallelConfig(ParallelConfigFactory::detect())
;
