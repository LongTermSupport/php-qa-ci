<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Rector;

use Phar;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Defence for the class "the shipped Rector config skips a rule that no set registers".
 *
 * Rector prints "This skipped rule is never registered. You can remove it from ->withSkip()"
 * on every run of such a config, in every consuming project, naming a file the consumer
 * does not own. A Rector release that moves a rule out of a set makes the skip stale
 * without failing anything, so every skip is checked against the sets in the shipped
 * vendor-phar/rector.phar.
 *
 * A rule this package keeps out deliberately and no set registers any more is listed in
 * NEVER_REGISTERED instead, so a Rector release that puts it back into a set fails here
 * rather than silently rewriting consumers' code.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class RectorSkipListMatchesTheShippedSetsTest extends TestCase
{
    /** The shipped config whose skip list is checked. */
    private const string CONFIG = __DIR__ . '/../../../configDefaults/generic/rector-php85.php';

    /** The Rector the config runs under, whose sets decide what is registered. */
    private const string PHAR = __DIR__ . '/../../../vendor-phar/rector.phar';

    /**
     * Short names of rules kept out of the shipped config that no set registers today.
     * NullToStrictStringFuncCallArgRector adds (string) casts that PHPStan at level max then
     * rejects as casting a string to string; rector-php85.php says more above its skip list.
     *
     * @var list<string>
     */
    private const array NEVER_REGISTERED = ['NullToStrictStringFuncCallArgRector'];

    #[Test]
    public function everySkippedRuleIsRegisteredBySomeShippedSet(): void
    {
        $sets  = $this->setSources();
        $stale = array_values(array_filter(
            $this->skippedRules(),
            static fn (string $rule): bool => !self::registered($rule, ...$sets),
        ));

        self::assertSame([], $stale, 'Rector registers these in no set, so skipping them only prints a warning on every run: remove them from the skip list, and add any still unwanted to NEVER_REGISTERED.');
    }

    #[Test]
    public function aRuleKeptOutIsStillRegisteredByNoShippedSet(): void
    {
        $sets     = $this->setSources();
        $returned = array_filter(
            self::NEVER_REGISTERED,
            static fn (string $rule): bool => self::registered($rule, ...$sets),
        );

        self::assertSame([], $returned, 'A Rector set registers these again: add them back to the skip list in rector-php85.php.');
    }

    private static function registered(string $shortName, string ...$sets): bool
    {
        return array_any($sets, static fn (string $source): bool => 1 === \Safe\preg_match('/\b' . preg_quote($shortName, '/') . '::class/', $source));
    }

    /** @return list<string> the short names of the rules in rector-php85.php's skip list */
    private function skippedRules(): array
    {
        if (1 !== \Safe\preg_match('/->skip\(\[(.*?)\]\);/s', \Safe\file_get_contents(self::CONFIG), $block) || !isset($block[1])) {
            self::fail('rector-php85.php must have one skip([...]) list.');
        }

        \Safe\preg_match_all('/\\\(\w+)::class/', $block[1], $rules);

        $names = [];
        foreach (\is_array($rules[1] ?? null) ? $rules[1] : [] as $name) {
            if (\is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return list<string> the source of every set config inside the shipped rector.phar */
    private function setSources(): array
    {
        $sources = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new Phar(self::PHAR)) as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/config/set/') && str_ends_with($path, '.php')) {
                $sources[] = \Safe\file_get_contents($path);
            }
        }

        self::assertNotSame([], $sources, 'No set configs found in rector.phar: the layout this test reads has changed.');

        return $sources;
    }
}
