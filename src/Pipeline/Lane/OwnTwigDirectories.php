<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * The configured twig directories that belong to the project. A directory that resolves
 * (symlinks followed) under the project's `vendor/` is code the project does not own, so no
 * Twig lane lints it; when none is left the lane skips, loudly, with the reason.
 *
 * @internal
 */
final readonly class OwnTwigDirectories
{
    public const string SKIP_REASON = 'no template directory of its own';

    /**
     * @return list<string>
     */
    public static function declared(ToolContext $context): array
    {
        $vendor = self::resolved($context->config->paths->projectRoot . '/vendor');

        return array_values(array_filter(
            $context->config->twigDirectories,
            static fn (string $directory): bool => !str_starts_with(self::resolved($directory) . '/', $vendor . '/')
                && !str_starts_with($directory . '/', $context->config->paths->projectRoot . '/vendor/'),
        ));
    }

    public static function announceSkip(ToolContext $context): void
    {
        $context->writeln('Twig: the project declares ' . self::SKIP_REASON . ' (withTwigDirectories(); templates under vendor/ are never linted) — skipping.');
    }

    /** Symlinks followed when the path exists; as written when it does not. */
    private static function resolved(string $path): string
    {
        return is_dir($path) ? \Safe\realpath($path) : $path;
    }
}
