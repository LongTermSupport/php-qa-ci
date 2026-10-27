<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DependencyFreshness;

use RuntimeException;
use stdClass;

/**
 * A throwaway copy of a project's composer.json and composer.lock in which
 * chosen packages are required by their branch alternatives alone. Composer
 * prefers a tag to a branch head under `dev-php8.5@dev || ^1.0`, so asking it
 * about a package locked on the branch is only meaningful under the branch
 * alone. The copy sits beside the real files (so relative repositories still
 * resolve), is selected with the `COMPOSER` environment variable, and is
 * removed after the question is asked.
 *
 * @internal
 */
final readonly class BranchOnlyProject
{
    private const string PREFIX = '.qa-freshness-';

    private function __construct(
        public string $jsonFile,
        private string $lockFile,
    ) {
    }

    /**
     * @param array<array-key, string> $branchConstraints package name => the constraint to require it under (names match without regard to case)
     *
     * @throws RuntimeException when composer.json is not a JSON object
     */
    public static function create(string $root, array $branchConstraints): self
    {
        // Decoded to objects, not arrays: an empty JSON object (`"config": {}`) must stay one, as Composer's schema rejects `[]` there.
        $json = \Safe\json_decode(\Safe\file_get_contents($root . '/composer.json'));
        if (!$json instanceof stdClass) {
            throw new RuntimeException('composer.json is not a JSON object');
        }

        $wanted = array_change_key_case($branchConstraints);
        foreach (['require', 'require-dev'] as $section) {
            $required = $json->{$section} ?? null;
            if (!$required instanceof stdClass) {
                continue;
            }

            foreach (array_keys(get_object_vars($required)) as $name) {
                $constraint = $wanted[strtolower((string)$name)] ?? null;
                if (null !== $constraint) {
                    $required->{$name} = $constraint;
                }
            }
        }

        $base = self::PREFIX . bin2hex(random_bytes(6));
        $copy = new self($base . '.json', $base . '.lock');
        \Safe\file_put_contents($root . '/' . $copy->jsonFile, \Safe\json_encode($json, JSON_UNESCAPED_SLASHES));
        \Safe\copy($root . '/composer.lock', $root . '/' . $copy->lockFile);

        return $copy;
    }

    public function remove(string $root): void
    {
        foreach ([$this->jsonFile, $this->lockFile] as $file) {
            if (is_file($root . '/' . $file)) {
                \Safe\unlink($root . '/' . $file);
            }
        }
    }
}
