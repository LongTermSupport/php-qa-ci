<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A throwaway git world for the Large changelog tests: a bare "origin" whose
 * default branch is php8.5, and a clone of it to work in. Every git call runs
 * with the user's global and system config switched off, so signing, hooks or
 * a different default branch on the host cannot change the outcome.
 */
final readonly class GitSandbox
{
    public const string DEFAULT_BRANCH = 'php8.5';

    private function __construct(public TempDir $root, public string $origin, public string $work)
    {
    }

    /** @param array<string, string> $files the first commit on php8.5, project-relative path => contents */
    public static function create(array $files): self
    {
        $root    = TempDir::create('phpqa-git-sandbox');
        $origin  = $root->mkdir('origin.git');
        $seed    = $root->mkdir('seed');
        $sandbox = new self($root, $origin, $root->path . '/work');

        $sandbox->run($origin, 'init', '--bare', '--initial-branch=' . self::DEFAULT_BRANCH);
        $sandbox->run($seed, 'init', '--initial-branch=' . self::DEFAULT_BRANCH);
        foreach ($files as $path => $contents) {
            $root->write('seed/' . $path, $contents);
        }

        $sandbox->run($seed, 'add', '-A');
        $sandbox->run($seed, 'commit', '-m', 'Initial');
        $sandbox->run($seed, 'push', $origin, self::DEFAULT_BRANCH);
        $sandbox->run($root->path, 'clone', $origin, 'work');

        return $sandbox;
    }

    /** Write a file in the work clone and commit it, with an optional extra commit-message paragraph (a trailer). */
    public function commit(string $path, string $contents, string $message = 'Change', ?string $trailer = null): void
    {
        $this->root->write('work/' . $path, $contents);
        $this->git('add', '-A');
        $this->git('commit', '-m', $message, ...(null === $trailer ? [] : ['-m', $trailer]));
    }

    public function git(string ...$args): string
    {
        return $this->run($this->work, ...$args);
    }

    public function read(string $path): string
    {
        return $this->root->read('work/' . $path);
    }

    public function remove(): void
    {
        $this->root->remove();
    }

    /** @return array<string, string> the environment that isolates git from the host's configuration */
    public static function environment(): array
    {
        return [
            'GIT_CONFIG_GLOBAL'   => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME'     => 'Sandbox',
            'GIT_AUTHOR_EMAIL'    => 'sandbox@example.invalid',
            'GIT_COMMITTER_NAME'  => 'Sandbox',
            'GIT_COMMITTER_EMAIL' => 'sandbox@example.invalid',
        ];
    }

    private function run(string $cwd, string ...$args): string
    {
        $process = new Process(['git', ...array_values($args)], $cwd, self::environment());
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(\sprintf('git %s failed in %s: %s', implode(' ', $args), $cwd, $process->getErrorOutput() . $process->getOutput()));
        }

        return $process->getOutput();
    }
}
