<?php

declare(strict_types=1);

namespace LTS\PHPQA\PackageType;

use LTS\PHPQA\Helper;

/**
 * Resolves the consuming project's package kind from its `composer.json` `type`,
 * for the PHPStan API-surface classification rule.
 *
 * `effectiveType()` returns the declared type normalised (trimmed + lower-cased),
 * falling back to Composer's own silent default of `library` when the field is
 * absent / blank / not a string. Treating an undeclared package as a library is
 * the safe side — it keeps the public-surface discipline ON rather than silently
 * skipping it; the dedicated {@see ExplicitPackageTypeDetector} is what hard-fails
 * the missing declaration in the pipeline.
 *
 * The optional constructor override makes the reader injectable so unit tests (and
 * the rule's own tests) can drive any type without touching a real `composer.json`.
 */
final class ProjectComposerTypeReader
{
    private const string DEFAULT_TYPE = 'library';

    /**
     * @param array<int|string, mixed>|null $composerJsonOverride decoded composer.json
     *                                                            for tests; null reads the live project file
     */
    public function __construct(private readonly ?array $composerJsonOverride = null)
    {
    }

    public function effectiveType(): string
    {
        $composerJson = $this->composerJsonOverride ?? Helper::getComposerJsonDecoded();
        $type         = $composerJson['type'] ?? null;

        if (!\is_string($type) || '' === \trim($type)) {
            return self::DEFAULT_TYPE;
        }

        return \strtolower(\trim($type));
    }
}
