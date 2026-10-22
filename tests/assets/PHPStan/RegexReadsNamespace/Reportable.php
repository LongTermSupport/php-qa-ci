<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\RegexReadsNamespace;

final class Reportable
{
    private const string DECLARATION = '/^\s*namespace\s+([^\s;{]+)/m';

    public function inPhp(string $contents): int|false
    {
        return preg_match('/^\s*namespace\s+([^;]+);/m', $contents);
    }

    public function inSafe(string $contents): int
    {
        return \Safe\preg_match('%^\s*namespace\s+[A-Za-z_\\\][A-Za-z0-9_\\\]*\s*;%m', $contents);
    }

    /** @return list<string> */
    public function everyDeclaration(string $contents): array
    {
        preg_match_all('/namespace[[:space:]]+(\w+)/', $contents, $matches);

        return $matches[1];
    }

    public function swappedOut(string $contents): ?string
    {
        return preg_replace(pattern: '/namespace\h+Old;/', replacement: 'namespace New;', subject: $contents);
    }

    public function throughAConstant(string $contents): int|false
    {
        return preg_match(self::DECLARATION, $contents);
    }

    public function writtenWithAPlainSpace(string $contents): int|false
    {
        return preg_match('/^namespace (.+);$/m', $contents);
    }

    public function splitAtIt(string $contents): array|false
    {
        return preg_split('/\bNamespace\s/', $contents);
    }

    public function afterAnOtherPattern(string $contents): int|false
    {
        return preg_match('/(?:<\?php\s+)?namespace\s+(\w+)/', $contents);
    }
}
