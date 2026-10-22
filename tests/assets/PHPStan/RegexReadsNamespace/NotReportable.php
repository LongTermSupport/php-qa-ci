<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\RegexReadsNamespace;

final class NotReportable
{
    public function theWordWithoutWhitespaceAfterIt(string $contents): int|false
    {
        return preg_match('/\bnamespaces?\b/', $contents);
    }

    public function aLongerWord(string $contents): int|false
    {
        return preg_match('/\wnamespace\s+/', $contents);
    }

    public function thePluralFollowedByWhitespace(string $contents): int|false
    {
        return preg_match('/namespaces\s+/', $contents);
    }

    public function aPatternNoOneCanRead(string $pattern, string $contents): int|false
    {
        return preg_match($pattern, $contents);
    }

    public function otherDeclarationsAreNotThisRule(string $contents): int|false
    {
        return preg_match('/^\s*(?:final\s+)?class\s+(\w+)/m', $contents);
    }

    public function theWordInTheSubjectNotThePattern(): int|false
    {
        return preg_match('/^\d+$/', 'namespace Foo;');
    }

    public function aFunctionOfThatNameElsewhere(string $contents): int
    {
        return Helper\preg_match('/^namespace\s+/', $contents);
    }

    public function notARegexFunction(string $contents): bool
    {
        return str_contains($contents, 'namespace ');
    }

    public function aFirstClassCallable(): \Closure
    {
        return preg_match(...);
    }
}
