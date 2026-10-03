<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use InvalidArgumentException;

/**
 * The headings an `## Unreleased` section may use, in canonical order, and
 * what each does to the next version: a feature or a breaking change bumps
 * the minor (the major is the PHP line), a fix or a security fix the patch.
 * Any other heading makes the changelog invalid rather than being guessed at.
 *
 * @api
 */
enum ChangelogHeadingEnum: string
{
    public function bump(): ReleaseBumpEnum
    {
        return match ($this) {
            self::Fixed, self::Security => ReleaseBumpEnum::Patch,
            default                     => ReleaseBumpEnum::Minor,
        };
    }

    /** Whether an entry here can break a consuming project, so the release notes say so first. */
    public function isBreaking(): bool
    {
        return self::ChangedBreaking === $this || self::Removed === $this;
    }

    /** The position in the canonical order: a new heading is inserted before the first that ranks after it. */
    public function rank(): int
    {
        return (int)array_search($this, self::cases(), true);
    }

    /** The ASCII spelling for a command line, e.g. `changed-breaking`. */
    public function slug(): string
    {
        return strtolower(str_replace(' — ', '-', $this->value));
    }

    /** The heading named by its label or its slug, case-insensitively. */
    public static function fromArgument(string $argument): self
    {
        $wanted = strtolower(trim($argument));
        foreach (self::cases() as $heading) {
            if ($wanted === strtolower($heading->value) || $wanted === $heading->slug()) {
                return $heading;
            }
        }

        throw new InvalidArgumentException(\sprintf(
            'Unknown changelog heading "%s"; use one of: %s',
            $argument,
            implode(', ', array_map(static fn (self $heading): string => \sprintf('%s (%s)', $heading->value, $heading->slug()), self::cases())),
        ));
    }

    case ChangedBreaking = 'Changed — breaking';
    case Removed         = 'Removed';
    case Added           = 'Added';
    case Changed         = 'Changed';
    case Deprecated      = 'Deprecated';
    case Fixed           = 'Fixed';
    case Security        = 'Security';
}
