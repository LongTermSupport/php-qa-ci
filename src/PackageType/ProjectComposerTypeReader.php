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
 *
 * {@see enforcesApiSurface()} adds the tri-state control that lets a package which
 * is a consumable library but carries a non-`library` composer `type` (e.g. a
 * self-deploying `composer-plugin`) still enforce its public-surface discipline —
 * see {@see ApiSurfaceEnforcementModeEnum}.
 */
final readonly class ProjectComposerTypeReader
{
    private const string DEFAULT_TYPE = 'library';

    private ApiSurfaceEnforcementModeEnum $enforceMode;

    /**
     * @param array<int|string, mixed>|null $composerJsonOverride decoded composer.json
     *                                                            for tests; null reads the live project file
     * @param string                        $enforceMode          API-surface enforcement token
     *                                                            (`auto` | `always` | `never`);
     *                                                            see {@see ApiSurfaceEnforcementModeEnum}
     */
    public function __construct(
        private ?array $composerJsonOverride = null,
        string $enforceMode = 'auto',
    ) {
        $this->enforceMode = ApiSurfaceEnforcementModeEnum::fromConfig($enforceMode);
    }

    /**
     * Whether the API-surface classification rules should enforce for this project.
     *
     * `auto` (the default) preserves the historic behaviour exactly — enforce iff the
     * composer `type` is `library`; `always`/`never` force the decision on/off
     * regardless of `type` (see {@see ApiSurfaceEnforcementModeEnum}).
     */
    public function enforcesApiSurface(): bool
    {
        return match ($this->enforceMode) {
            ApiSurfaceEnforcementModeEnum::Always => true,
            ApiSurfaceEnforcementModeEnum::Never  => false,
            ApiSurfaceEnforcementModeEnum::Auto   => self::DEFAULT_TYPE === $this->effectiveType(),
        };
    }

    public function effectiveType(): string
    {
        $composerJson = $this->composerJsonOverride ?? Helper::getComposerJsonDecoded();
        $type         = $composerJson['type']       ?? null;

        if (!\is_string($type) || '' === trim($type)) {
            return self::DEFAULT_TYPE;
        }

        return strtolower(trim($type));
    }
}
