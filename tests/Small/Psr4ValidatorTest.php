<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use InvalidArgumentException;
use LTS\PHPQA\Helper;
use LTS\PHPQA\Psr4Validator;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Class Psr4ValidatorTest.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Psr4Validator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(Helper::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Regex\RegexPattern::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Filesystem\UnresolvablePath::class)]
#[\PHPUnit\Framework\Attributes\Small]
final class Psr4ValidatorTest extends TestCase
{
    private const string COMPOSER_JSON = '/composer.json';

    private const string SHOP_ROOT = '/srv/shop';

    private const string SHOP_NAME = 'acme/shop';

    private const string VALID_PROJECT = __DIR__ . '/../assets/psr4/projectAllValid/';

    private const string MISSING_MAGENTO_PATH = 'lib/internal/Magento/Framework/';

    private const string MAGENTO_HINT = "Magento's composer includes this by default, it should be removed from the psr-4 section";

    public function testItFindsNoErrorsOnAValidProject(): void
    {
        $assetsPath  = self::VALID_PROJECT;
        $projectRoot = \Safe\realpath($assetsPath);
        $validator   = new Psr4Validator(
            [],
            $projectRoot,
            Helper::getComposerJsonDecoded($projectRoot . self::COMPOSER_JSON)
        );
        $actual   = $validator->main();
        $expected = [];
        self::assertSame($expected, $actual);
    }

    public function testItCanHandleOddComposerConfigs(): void
    {
        $assetsPath  = __DIR__ . '/../assets/psr4/projectOddComposer/';
        $projectRoot = \Safe\realpath($assetsPath);
        $validator   = new Psr4Validator(
            [],
            $projectRoot,
            Helper::getComposerJsonDecoded($projectRoot . self::COMPOSER_JSON)
        );
        $actual   = $validator->main();
        $expected = [];
        self::assertSame($expected, $actual);
    }

    public function testIgnoredFilesAloneDoNotProduceErrorsAndAreNotReported(): void
    {
        // A project that is entirely valid but whose ignore patterns match some
        // files. The scanner records those as "ignored" internally, but that
        // debug info is DELIBERATELY suppressed when there are no real errors —
        // main() returns early with an empty result the moment the error set is
        // empty, so a clean run never leaks the "Ignored Files:" block.
        $assetsPath  = self::VALID_PROJECT;
        $projectRoot = \Safe\realpath($assetsPath);
        $validator   = new Psr4Validator(
            ['%Nested/Deep%'],
            $projectRoot,
            Helper::getComposerJsonDecoded($projectRoot . self::COMPOSER_JSON)
        );

        self::assertSame([], $validator->main());
    }

    public function testADanglingSymlinkIsSkippedAndListedByItsDirectorysRealPath(): void
    {
        $project = $this->projectWithADanglingSymlink();

        try {
            $validator = new Psr4Validator([], $project->path, Helper::getComposerJsonDecoded($project->path . self::COMPOSER_JSON));

            self::assertSame([], $validator->main());
            self::assertSame([\Safe\realpath($project->path) . '/src/Gone.php'], $validator->unresolvable());
        } finally {
            $project->remove();
        }
    }

    public function testADanglingSymlinkAnIgnorePatternMatchesIsIgnoredNotListed(): void
    {
        $project = $this->projectWithADanglingSymlink();

        try {
            $validator = new Psr4Validator(['#/src/Gone\.php$#'], $project->path, Helper::getComposerJsonDecoded($project->path . self::COMPOSER_JSON));

            self::assertSame([], $validator->main());
            self::assertSame([], $validator->unresolvable());
        } finally {
            $project->remove();
        }
    }

    public function testItFindsErrorsAndThrowsAnExceptionOnAnInvalidProject(): void
    {
        $assetsPath  = __DIR__ . '/../assets/psr4/projectInValid/';
        $projectRoot = \Safe\realpath($assetsPath);
        $validator   = new Psr4Validator(
            ['%IgnoredStuff%'],
            $projectRoot,
            Helper::getComposerJsonDecoded($projectRoot . self::COMPOSER_JSON)
        );
        $actual   = $validator->main();
        $expected = [
            'PSR-4 Errors:'  => [
                'In\Valid\\' => [
                    0 => [
                        'fileInfo'          => $projectRoot . '/src/Nested/Deep/Bad.php',
                        'expectedNamespace' => 'In\Valid\Nested\Deep',
                        'actualNamespace'   => 'So',
                    ],
                    1 => [
                        'fileInfo'          => $projectRoot . '/src/Wrong.php',
                        'expectedNamespace' => 'In\Valid',
                        'actualNamespace'   => 'Totally',
                    ],
                ],
            ],
            'Parse Errors:'  => [
                0 => $projectRoot . '/tests/ParseError.php: Syntax error, unexpected T_STRING on line 5',
            ],
            'Missing Paths:' => [
                'missing/path'         => 'Namespace root \'In\Valid\\\'
contains a path \'missing/path\'
which doesn\'t exist
',
                'missing/magento/path' => 'Namespace root \'In\Valid\\\'
contains a path \'missing/magento/path\'
which doesn\'t exist
',
            ],
            'Ignored Files:' => [
                0 => $projectRoot . '/src/IgnoredStuff/Ignored.php',
                1 => $projectRoot . '/src/IgnoredStuff/InvalidIgnored.php',
            ],
        ];

        self::assertSame($expected, $actual);
    }

    /**
     * A file under a PSR-4 root can be autoloaded only as the one class-like its path names. A
     * class-like with any other name, the file's only one or one beside it, can never be found by
     * the autoloader, whether it is a class, an enum, an interface or a trait (#281). Anonymous
     * classes and a file of functions declare nothing to load by name, so they are not reported.
     */
    public function testEveryClassLikeAFileDeclaresMustBeTheOneItsPathNames(): void
    {
        $projectRoot = \Safe\realpath(__DIR__ . '/../assets/psr4/projectClassNames/');
        $validator   = new Psr4Validator([], $projectRoot, Helper::getComposerJsonDecoded($projectRoot . self::COMPOSER_JSON));

        $rename  = 'rename the class-like or the file so that the two agree';
        $ownFile = 'move it to a file of its own, named for it';
        self::assertSame(
            [
                'Class-Like Errors:' => [
                    'Named\Classes\\' => [
                        $this->classLikeError($projectRoot . '/src/Renamed.php', 'class', 'NotRenamed', 'Renamed', $rename),
                        $this->classLikeError($projectRoot . '/src/WithClass.php', 'class', 'WithClassHelper', 'WithClass', $ownFile),
                        $this->classLikeError($projectRoot . '/src/WithEnum.php', 'enum', 'Colour', 'WithEnum', $ownFile),
                        $this->classLikeError($projectRoot . '/src/WithInterface.php', 'interface', 'Shape', 'WithInterface', $ownFile),
                        $this->classLikeError($projectRoot . '/src/WithTrait.php', 'trait', 'Greets', 'WithTrait', $ownFile),
                    ],
                ],
            ],
            $validator->main(),
        );
    }

    /**
     * The namespace is the one the file declares, read from its parse: a comment or docblock
     * that mentions a namespace before the declaration is not one, a braced namespace block is
     * one, and a file whose only mention of a namespace is inside a string declares none, which
     * is a parse error. In a file of several namespace blocks the first is the file's; a
     * class-like in a later block is reported as one its path does not name.
     */
    public function testTheNamespaceIsTheOneTheFileDeclaresNotTheFirstMentionOfTheWord(): void
    {
        $projectRoot = \Safe\realpath(__DIR__ . '/../assets/psr4/projectNamespaceReading/');
        $validator   = new Psr4Validator([], $projectRoot, Helper::getComposerJsonDecoded($projectRoot . self::COMPOSER_JSON));
        // Built from parts: the fixture's classes are not autoloadable, so no tool should read these as references.
        $multiple = implode('\\', ['Named', 'Reading', 'Multiple']);
        $stray    = implode('\\', ['Elsewhere', 'Stray']);

        self::assertSame(
            [
                'Class-Like Errors:' => [
                    'Named\Reading\\' => [
                        [
                            'fileInfo'          => $projectRoot . '/src/Multiple.php',
                            'expectedClassLike' => $multiple,
                            'declaredClassLike' => $stray,
                            'problem'           => "class {$stray} is declared in a file whose path autoloads only {$multiple}, so no autoloader can load it: move it to a file of its own, named for it",
                        ],
                    ],
                ],
                'Parse Errors:'      => [$projectRoot . '/src/NoNamespace.php'],
            ],
            $validator->main(),
        );
    }

    /**
     * A name that cannot be resolved (a second `use` of the same short name) is a parse error
     * naming the file, not an exception that ends the lane without saying which file.
     */
    public function testAFileWhoseNamesCannotBeResolvedIsAParseErrorNamingTheFile(): void
    {
        $project = TempDir::create('phpqa-psr4-unresolvable');

        try {
            $project->write('composer.json', '{"autoload": {"psr-4": {"Probe\\\": "src/"}}}');
            $project->write('src/Dup.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nuse Foo\\Bar;\nuse Baz\\Bar;\n\nfinal class Dup\n{\n}\n");
            $validator = new Psr4Validator([], $project->path, Helper::getComposerJsonDecoded($project->path . self::COMPOSER_JSON));

            self::assertSame(
                ['Parse Errors:' => [\Safe\realpath($project->path) . '/src/Dup.php: Cannot use Baz\Bar as Bar because the name is already in use on line 8']],
                $validator->main(),
            );
        } finally {
            $project->remove();
        }
    }

    /**
     * A file whose namespace can be read but which does not parse declares no class-like the
     * validator can see, so it is a parse error, named with the parser's reason.
     */
    public function testAFileThatDoesNotParseIsAParseErrorNamingTheReason(): void
    {
        $project = TempDir::create('phpqa-psr4-unparseable');

        try {
            $project->write('composer.json', '{"autoload": {"psr-4": {"Probe\\\": "src/"}}}');
            $project->write('src/Broken.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nfinal class Broken\n{\n");
            $validator = new Psr4Validator([], $project->path, Helper::getComposerJsonDecoded($project->path . self::COMPOSER_JSON));

            self::assertSame(
                ['Parse Errors:' => [\Safe\realpath($project->path) . '/src/Broken.php: Syntax error, unexpected EOF on line 9']],
                $validator->main(),
            );
        } finally {
            $project->remove();
        }
    }

    /**
     * Whether a project is a Magento project is decided from its composer.json, never from where
     * it is checked out: a word in the path says nothing about the project (#231).
     *
     * @param array<string, mixed> $composerJson
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('magentoHintCases')]
    public function testTheMagentoHintFollowsTheProjectNotItsPath(string $projectRoot, array $composerJson, bool $hinted): void
    {
        $composerJson['autoload'] = ['psr-4' => ['Magento\Framework\\' => self::MISSING_MAGENTO_PATH]];

        $expected = "Namespace root 'Magento\\Framework\\'\ncontains a path '" . self::MISSING_MAGENTO_PATH . "'\nwhich doesn't exist\n"
            . ($hinted ? self::MAGENTO_HINT : '');

        self::assertSame(
            ['Missing Paths:' => [self::MISSING_MAGENTO_PATH => $expected]],
            new Psr4Validator([], $projectRoot, $composerJson)->main(),
        );
    }

    /** @return iterable<string, array{string, array<string, mixed>, bool}> */
    public static function magentoHintCases(): iterable
    {
        yield 'a non-Magento project under a path naming Magento' => [
            '/home/ci/Magento-tools/my-app',
            ['name' => 'acme/tooling', 'require' => ['php' => '^8.5']],
            false,
        ];
        yield 'a Magento project, by its requirement, under any other path' => [
            self::SHOP_ROOT,
            ['name' => self::SHOP_NAME, 'require' => ['magento/product-community-edition' => '2.4.7']],
            true,
        ];
        yield 'Magento itself, by its own name' => [
            '/srv/checkout',
            ['name' => 'magento/magento2ce'],
            true,
        ];
        yield 'a Magento project, by a requirement written in capitals' => [
            self::SHOP_ROOT,
            ['name' => self::SHOP_NAME, 'require' => ['Magento/Framework' => '*']],
            true,
        ];
        yield 'a project requiring another vendor whose name ends in magento' => [
            self::SHOP_ROOT,
            ['name' => self::SHOP_NAME, 'require' => ['openmagento/framework' => '*']],
            false,
        ];
        yield 'a project that only develops against Magento' => [
            '/srv/module',
            ['name' => 'acme/module', 'require-dev' => ['magento/framework' => '*']],
            false,
        ];
    }

    /**
     * A composer.json key PHP decodes to an int, a namespace root written in digits, is
     * reported as the missing path it maps to, not a TypeError (#256).
     */
    public function testANamespaceRootWrittenInDigitsIsReportedNotFatal(): void
    {
        $composerJson = \Safe\json_decode('{"autoload": {"psr-4": {"2024": "2024"}}}', true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composerJson);

        self::assertSame(
            ['Missing Paths:' => ['2024' => "Namespace root '2024'\ncontains a path '2024'\nwhich doesn't exist\n"]],
            new Psr4Validator([], '/nonexistent-project-root', $composerJson)->main(),
        );
    }

    /**
     * An ignore pattern that does not compile is named when the validator is made, not reported
     * by PCRE as an anonymous warning and PREG_INTERNAL_ERROR at the first file it is tried on.
     */
    public function testAnIgnorePatternThatDoesNotCompileIsRefusedByName(): void
    {
        $projectRoot = \Safe\realpath(self::VALID_PROJECT);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("ignore pattern \"--help\" is not a valid regular expression: preg_match(): Unknown modifier 'h'");

        new Psr4Validator(['%IgnoredStuff%', '--help'], $projectRoot, Helper::getComposerJsonDecoded($projectRoot . self::COMPOSER_JSON));
    }

    /**
     * Regression: php-qa-ci's own convention maps `qaConfig/` as a PSR-4 root
     * (QaConfig\, for custom PHPStan rules) AND ships non-class config files
     * there that projects override — qaConfig/phparkitect.php,
     * qaConfig/php_cs.php and qaConfig/php_cs_finder.php (the documented CS
     * Fixer Finder override, docs/coding-standards.md). Those legitimately
     * have no namespace, so the SHIPPED default ignore list must exclude them;
     * otherwise the re-enabled PSR-4 gate falsely reports them as Parse Errors
     * on every consuming project.
     *
     * The fixture also contains a correctly-namespaced rule class under the
     * same root (qaConfig/PHPStan/Rules/GoodRule.php) to prove the exclusion is
     * scoped to the config files and does not blanket-skip the qaConfig tree.
     */
    public function testShippedDefaultIgnoreListExcludesQaConfigConfigFiles(): void
    {
        $assetsPath  = __DIR__ . '/../assets/psr4/projectQaConfig/';
        $projectRoot = \Safe\realpath($assetsPath);
        $validator   = new Psr4Validator(
            $this->loadShippedDefaultIgnoreList(),
            $projectRoot,
            Helper::getComposerJsonDecoded($projectRoot . self::COMPOSER_JSON)
        );

        self::assertSame([], $validator->main());
    }

    /**
     * The exact ignore patterns bin/qa feeds the validator in production: every
     * non-blank line of the shipped default list (see Psr4ValidateTool).
     *
     * @return list<string>
     */
    private function loadShippedDefaultIgnoreList(): array
    {
        $path     = __DIR__ . '/../../configDefaults/generic/psr4-validate-ignore-list.txt';
        $contents = \Safe\file_get_contents($path);

        $patterns = [];
        foreach (explode("\n", $contents) as $line) {
            if ('' !== trim($line)) {
                $patterns[] = $line;
            }
        }

        return $patterns;
    }

    /**
     * One class-like finding of the projectClassNames fixture, whose namespace root is Named\Classes\.
     *
     * @return array{fileInfo: string, expectedClassLike: string, declaredClassLike: string, problem: string}
     */
    private function classLikeError(string $file, string $kind, string $declared, string $expected, string $remedy): array
    {
        $declaredFqcn = 'Named\Classes\\' . $declared;
        $expectedFqcn = 'Named\Classes\\' . $expected;

        return [
            'fileInfo'          => $file,
            'expectedClassLike' => $expectedFqcn,
            'declaredClassLike' => $declaredFqcn,
            'problem'           => "{$kind} {$declaredFqcn} is declared in a file whose path autoloads only {$expectedFqcn}, so no autoloader can load it: {$remedy}",
        ];
    }

    /** A valid project whose PSR-4 root holds a symlink to a file that does not exist (#241). */
    private function projectWithADanglingSymlink(): TempDir
    {
        $project = TempDir::create('phpqa-psr4-dangling');
        $project->write('composer.json', '{"autoload": {"psr-4": {"Probe\\\": "src/"}}}');
        $project->write('src/Real.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nfinal class Real\n{\n}\n");
        \Safe\symlink('missing-target.php', $project->path . '/src/Gone.php');

        return $project;
    }
}
