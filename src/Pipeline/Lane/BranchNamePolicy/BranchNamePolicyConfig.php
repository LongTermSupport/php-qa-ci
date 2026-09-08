<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\BranchNamePolicy;

use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\Dto\BranchPolicyConfigDto;
use Nette\Neon\Neon;

/**
 * qaConfig/branchNamePolicy.yaml: two flat lists, `extra_allowed_prefixes`
 * and `extra_exempt_branches`, both additive. The file is YAML in the NEON
 * subset, so nette/neon reads it without a YAML dependency.
 *
 * @internal
 */
final readonly class BranchNamePolicyConfig
{
    public const string FILE = 'branchNamePolicy.yaml';

    public function load(string $projectConfigDir): BranchPolicyConfigDto
    {
        $file = $projectConfigDir . '/' . self::FILE;
        if (!is_file($file)) {
            return new BranchPolicyConfigDto(null, [], []);
        }

        $decoded = Neon::decode(\Safe\file_get_contents($file));
        if (!\is_array($decoded)) {
            return new BranchPolicyConfigDto($file, [], []);
        }

        return new BranchPolicyConfigDto(
            $file,
            $this->stringList($decoded, 'extra_allowed_prefixes'),
            $this->stringList($decoded, 'extra_exempt_branches'),
        );
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return list<string>
     */
    private function stringList(array $decoded, string $key): array
    {
        $value = $decoded[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $strings[] = trim($item);
            }
        }

        return $strings;
    }
}
