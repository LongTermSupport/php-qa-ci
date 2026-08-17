<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\DeploySkills;

use FilesystemIterator;
use LTS\PHPQA\Tests\Assets\DeploySkills\DeployProcessRunner;
use LTS\PHPQA\Tests\Assets\DeploySkills\ShellRunner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Golden-master characterisation of scripts/deploy-skills.bash.
 *
 * This suite pins the OBSERVABLE behaviour of the unattended consumer-deploy
 * script (run on every consumer `composer install/update` by SkillsDeployPlugin)
 * so the M-072 decomposition into scripts/lib/deploy-*.bash phase modules can be
 * proven behaviour-preserving. Every assertion below holds against the current
 * monolith; it must keep holding, byte-for-byte, against the decomposed
 * orchestrator.
 *
 * PRODUCTION-FIDELITY NOTE: the script is run with its working directory set to
 * the consumer project root, exactly as the composer plugin runs it (composer's
 * working directory is the consumer root). This matters: the "migrate old hook
 * names" step operates on a working-directory-relative `.claude/settings.json`,
 * so it only migrates the consumer's file when that is the working directory.
 * DeployProcessRunner reproduces that faithfully.
 *
 * The consumer fixtures are synthesised in a private temp tree (never the repo)
 * whose PARENT contains no `.claude/hooks-daemon.yaml`, so the script's monorepo
 * daemon-detection cannot spuriously fire.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class DeploySkillsCharacterisationTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /** Absolute path to the php-qa-ci repo root passed as the script's <qaci> arg. */
    private string $qaciPath;

    /** Absolute path to scripts/deploy-skills.bash. */
    private string $scriptPath;

    /** @var list<string> temp base dirs to remove in tearDown */
    private array $tempBases = [];

    protected function setUp(): void
    {
        $qaciPath         = \Safe\realpath(self::REPO_ROOT);
        $this->qaciPath   = $qaciPath;

        $this->scriptPath = $qaciPath . '/scripts/deploy-skills.bash';
        self::assertFileExists($this->scriptPath, 'deploy-skills.bash not found — has it moved?');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempBases as $base) {
            $this->removeTree($base);
        }

        $this->tempBases = [];
    }

    // =====================================================================
    // Scenario 1 — fresh deploy into a bare (no-daemon) consumer.
    // =====================================================================

    public function testFreshDeployLandsEveryArtefact(): void
    {
        $consumer = $this->newConsumer(daemon: false);
        $result   = $this->runDeploy($consumer);

        self::assertSame(0, $result['exit'], "Fresh deploy must exit 0.\n" . $result['output']);

        // Skills: every MANIFEST-listed skill directory is deployed.
        foreach ($this->manifestSkillNames() as $skill) {
            self::assertDirectoryExists(\sprintf('%s/.claude/skills/%s', $consumer, $skill), \sprintf("skill '%s' not deployed", $skill));
        }

        // Agents: every MANIFEST-listed agent .md is deployed.
        foreach ($this->manifestAgentFiles() as $agent) {
            self::assertFileExists(\sprintf('%s/.claude/agents/%s', $consumer, $agent), \sprintf("agent '%s' not deployed", $agent));
        }

        // Classic hooks: every MANIFEST-listed hook .py is deployed and executable.
        foreach ($this->manifestHookFiles() as $hook) {
            $target = \sprintf('%s/.claude/hooks/%s', $consumer, $hook);
            self::assertFileExists($target, \sprintf("hook '%s' not deployed", $hook));
            self::assertTrue(is_executable($target), \sprintf("hook '%s' is not executable", $hook));
        }

        // Git pre-commit hook: installed, executable, byte-identical to source.
        $preCommit = $consumer . '/.git/hooks/pre-commit';
        self::assertFileExists($preCommit);
        self::assertTrue(is_executable($preCommit), 'pre-commit hook is not executable');
        self::assertSame(
            $this->read($this->qaciPath . '/git-hooks/pre-commit-check-vendor-uncommitted'),
            $this->read($preCommit),
            'deployed pre-commit hook differs from the shipped source',
        );

        // PHPStan scaffold seeds (SEED-ONCE) match their templates.
        self::assertSame(
            $this->read($this->qaciPath . '/templates/qaConfig-PHPStan-CLAUDE.md'),
            $this->read($consumer . '/qaConfig/PHPStan/CLAUDE.md'),
        );
        self::assertSame(
            $this->read($this->qaciPath . '/templates/src-PHPStan-CLAUDE.md'),
            $this->read($consumer . '/src/PHPStan/CLAUDE.md'),
        );

        // CLAUDE.md <phpqaci> block injected.
        $claudeMd = $this->read($consumer . '/CLAUDE.md');
        self::assertStringContainsString('<phpqaci>', $claudeMd);
        self::assertStringContainsString('</phpqaci>', $claudeMd);

        // composer.json gains the QaConfig\ autoload-dev mapping.
        $composer    = $this->readJson($consumer . '/composer.json');
        $autoloadDev = $composer['autoload-dev'] ?? null;
        self::assertIsArray($autoloadDev, 'autoload-dev section not added to composer.json');
        $psr4 = $autoloadDev['psr-4'] ?? null;
        self::assertIsArray($psr4, 'autoload-dev.psr-4 section not added to composer.json');
        self::assertSame(
            ['qaConfig/'],
            $psr4['QaConfig\\'] ?? null,
            'QaConfig\ autoload-dev PSR-4 entry not added',
        );
    }

    public function testFreshDeployRegistersHooksInSettings(): void
    {
        $consumer = $this->newConsumer(daemon: false);
        $this->runDeploy($consumer);

        $settings = $this->readJson($consumer . '/.claude/settings.json');
        $pre      = $this->hookCommands($settings, 'PreToolUse');
        $stop     = $this->hookCommands($settings, 'Stop');

        // auto-continue is the sole Stop-only hook (registered with the
        // CLAUDE_HOOK_EVENT=Stop prefix); every other hook lands in PreToolUse.
        foreach ($this->manifestHookFiles() as $hook) {
            $command = '.claude/hooks/' . $hook;
            if ('php-qa-ci__auto-continue.py' === $hook) {
                self::assertContains('CLAUDE_HOOK_EVENT=Stop ' . $command, $stop, 'auto-continue not registered in Stop');
                self::assertNotContains($command, $pre, 'auto-continue must not be in PreToolUse');
                continue;
            }

            self::assertContains($command, $pre, \sprintf("hook '%s' not registered in PreToolUse", $hook));
        }
    }

    // =====================================================================
    // Scenario 2 — re-deploy idempotence (byte-identical, no churn).
    // =====================================================================

    public function testReDeployIsIdempotent(): void
    {
        $consumer = $this->newConsumer(daemon: false);

        $first = $this->runDeploy($consumer);
        self::assertSame(0, $first['exit'], $first['output']);
        $afterFirst = $this->hashTree($consumer);

        $second = $this->runDeploy($consumer);
        self::assertSame(0, $second['exit'], $second['output']);
        $afterSecond = $this->hashTree($consumer);

        self::assertSame($afterFirst, $afterSecond, 'Second deploy changed the consumer tree — not idempotent.');

        // The second run must announce no churn and never claim to overwrite.
        self::assertStringContainsString('settings.json unchanged - skipping write', $second['output']);
        self::assertStringNotContainsString('Overwriting existing', $second['output']);
    }

    // =====================================================================
    // Scenario 3 — foreign .git/hooks/pre-commit preserved + loud warning.
    // =====================================================================

    public function testForeignPreCommitHookIsPreserved(): void
    {
        $consumer = $this->newConsumer(daemon: false);
        $foreign  = "#!/bin/sh\necho \"husky or hand-rolled hook\"\n";
        $this->write($consumer . '/.git/hooks/pre-commit', $foreign);
        $this->chmodExec($consumer . '/.git/hooks/pre-commit');

        $result = $this->runDeploy($consumer);

        self::assertSame(0, $result['exit'], 'A foreign hook must never fail the deploy.');
        self::assertSame($foreign, $this->read($consumer . '/.git/hooks/pre-commit'), 'foreign pre-commit was clobbered');
        self::assertStringContainsString('NOT managed by php-qa-ci', $result['output']);
        self::assertStringContainsString('Refusing to overwrite a foreign hook', $result['output']);
    }

    // =====================================================================
    // Scenario 4 — our own marker-bearing pre-commit IS overwritten.
    // =====================================================================

    public function testMarkerBearingPreCommitHookIsOverwritten(): void
    {
        $consumer = $this->newConsumer(daemon: false);
        // A stale php-qa-ci-signed hook: carries the line-anchored marker but a
        // different body — install_signed must overwrite it with the current one.
        $stale = "#!/usr/bin/env bash\n"
            . "# PHP-QA-CI-HOOK-SIGNATURE: pre-commit-check-vendor-uncommitted\n"
            . "echo 'stale old version'\n";
        $this->write($consumer . '/.git/hooks/pre-commit', $stale);
        $this->chmodExec($consumer . '/.git/hooks/pre-commit');

        $result = $this->runDeploy($consumer);

        self::assertSame(0, $result['exit']);
        self::assertSame(
            $this->read($this->qaciPath . '/git-hooks/pre-commit-check-vendor-uncommitted'),
            $this->read($consumer . '/.git/hooks/pre-commit'),
            'a stale marker-bearing hook must be overwritten with the shipped version',
        );
        self::assertStringContainsString('Overwriting existing git pre-commit hook', $result['output']);
    }

    // =====================================================================
    // Scenario 5 — legacy hook-name migration (old un-prefixed -> php-qa-ci__).
    // =====================================================================

    public function testLegacyHookNamesAreMigratedAndForeignEntriesPreserved(): void
    {
        $consumer = $this->newConsumer(daemon: false);

        $seed = [
            'permissions' => ['allow' => ['Bash(ls:*)']],
            'hooks'       => [
                'PreToolUse' => [
                    [
                        'hooks' => [
                            ['type' => 'command', 'command' => '.claude/hooks/auto-continue.py', 'timeout' => 5],
                            ['type' => 'command', 'command' => '.claude/hooks/prevent-destructive-git.py', 'timeout' => 5],
                            ['type' => 'command', 'command' => '/some/foreign/hook.py', 'timeout' => 5],
                        ],
                    ],
                ],
            ],
        ];
        $this->writeJson($consumer . '/.claude/settings.json', $seed);
        // The old-named files must exist so the migration cleanup can remove them.
        $this->write($consumer . '/.claude/hooks/auto-continue.py', "#!/usr/bin/env python3\n");
        $this->write($consumer . '/.claude/hooks/prevent-destructive-git.py', "#!/usr/bin/env python3\n");

        $result = $this->runDeploy($consumer);
        self::assertSame(0, $result['exit'], $result['output']);

        self::assertStringContainsString(
            'Migrated (PreToolUse): .claude/hooks/auto-continue.py -> .claude/hooks/php-qa-ci__auto-continue.py',
            $result['output'],
        );

        $settings = $this->readJson($consumer . '/.claude/settings.json');
        $pre      = $this->hookCommands($settings, 'PreToolUse');
        $stop     = $this->hookCommands($settings, 'Stop');

        // Old un-prefixed registrations are gone.
        self::assertNotContains('.claude/hooks/auto-continue.py', $pre);
        self::assertNotContains('.claude/hooks/prevent-destructive-git.py', $pre);
        // prevent-destructive-git migrated in place (a PreToolUse hook).
        self::assertContains('.claude/hooks/php-qa-ci__prevent-destructive-git.py', $pre);
        // auto-continue migrated AND relocated to Stop-only.
        self::assertContains('CLAUDE_HOOK_EVENT=Stop .claude/hooks/php-qa-ci__auto-continue.py', $stop);
        self::assertNotContains('.claude/hooks/php-qa-ci__auto-continue.py', $pre);
        // Foreign registration and unrelated keys survive untouched.
        self::assertContains('/some/foreign/hook.py', $pre);
        self::assertSame(['allow' => ['Bash(ls:*)']], $settings['permissions'] ?? null);

        // The old-named hook files were cleaned up.
        self::assertFileDoesNotExist($consumer . '/.claude/hooks/auto-continue.py');
        self::assertFileDoesNotExist($consumer . '/.claude/hooks/prevent-destructive-git.py');
    }

    // =====================================================================
    // Scenario 6 — settings.json merge preserves unrelated pre-existing hooks.
    // =====================================================================

    public function testExistingUnrelatedHookRegistrationsArePreserved(): void
    {
        $consumer = $this->newConsumer(daemon: false);

        $seed = [
            'permissions' => ['allow' => ['Bash(git status:*)']],
            'hooks'       => [
                'PreToolUse' => [
                    ['hooks' => [['type' => 'command', 'command' => '.claude/hooks/my-own-hook.py', 'timeout' => 5]]],
                ],
            ],
        ];
        $this->writeJson($consumer . '/.claude/settings.json', $seed);

        $result = $this->runDeploy($consumer);
        self::assertSame(0, $result['exit'], $result['output']);

        $settings = $this->readJson($consumer . '/.claude/settings.json');
        $pre      = $this->hookCommands($settings, 'PreToolUse');

        // The consumer's own hook and permissions block are untouched...
        self::assertContains('.claude/hooks/my-own-hook.py', $pre);
        self::assertSame(['allow' => ['Bash(git status:*)']], $settings['permissions'] ?? null);
        // ...and php-qa-ci's own hooks are appended alongside it.
        self::assertContains('.claude/hooks/php-qa-ci__prevent-destructive-git.py', $pre);
    }

    // =====================================================================
    // Scenario 7 — daemon present: classic hooks are NOT deployed, and any
    // pre-existing php-qa-ci__ registrations are cleaned from settings.json
    // while foreign registrations survive (the M-014 regression guard).
    // =====================================================================

    public function testDaemonPresentSuppressesClassicHooksAndCleansRegistrations(): void
    {
        $consumer = $this->newConsumer(daemon: true);

        $seed = [
            'hooks' => [
                'PreToolUse' => [
                    [
                        'hooks' => [
                            ['type' => 'command', 'command' => '.claude/hooks/php-qa-ci__prevent-destructive-git.py', 'timeout' => 5],
                            ['type' => 'command', 'command' => '/some/foreign/hook.py', 'timeout' => 5],
                        ],
                    ],
                ],
            ],
        ];
        $this->writeJson($consumer . '/.claude/settings.json', $seed);

        $result = $this->runDeploy($consumer);
        self::assertSame(0, $result['exit'], $result['output']);

        self::assertStringContainsString('hooks-daemon detected', $result['output']);
        self::assertStringContainsString('Removing classic hooks', $result['output']);

        // No classic hook files were deployed under a daemon setup.
        self::assertSame([], $this->deployedHookFiles($consumer), 'classic hooks must not be deployed when the daemon is present');

        // php-qa-ci__ registrations were removed; the foreign one is preserved.
        $settings = $this->readJson($consumer . '/.claude/settings.json');
        $pre      = $this->hookCommands($settings, 'PreToolUse');
        self::assertNotContains('.claude/hooks/php-qa-ci__prevent-destructive-git.py', $pre);
        self::assertContains('/some/foreign/hook.py', $pre);
    }

    /**
     * A skill present in the package but ABSENT from the manifest must not be
     * deployed, and must not disturb one the consumer already has.
     *
     * hooks-daemon is the worked example and the reason the manifest exists:
     * the hooks daemon deploys that skill itself, from its own source tree, on
     * every install/upgrade. Both packages therefore wrote the same path and
     * the last writer won. That was not a harmless overwrite — install_owned_tree
     * is remove-then-copy, so files the daemon ships and our snapshot lacks were
     * DELETED (a consumer on daemon v3.53.1 lost the skill's plan-qa.md to a
     * plain `composer install`). This guard pins both halves: we skip it, and we
     * leave the owner's files byte-identical.
     */
    public function testUnlistedSkillIsNotDeployedAndConsumerCopyIsLeftIntact(): void
    {
        $consumer = $this->newConsumer(daemon: true);

        self::assertNotContains('hooks-daemon', $this->manifestSkillNames(), 'hooks-daemon must stay out of the deploy manifest — the daemon owns it');
        self::assertDirectoryExists($this->qaciPath . '/.claude/skills/hooks-daemon', 'this test is only meaningful while the package still carries the unlisted skill');

        // Stand in for the daemon's own, NEWER deployed skill: one file our
        // packaged copy does not ship at all, and one that it does.
        $skillDir = $consumer . '/.claude/skills/hooks-daemon';
        $this->makeDir($skillDir);
        $this->write($skillDir . '/plan-qa.md', "# only the daemon ships this\n");
        $this->write($skillDir . '/SKILL.md', "# daemon's newer copy\n");

        $result = $this->runDeploy($consumer);
        self::assertSame(0, $result['exit'], $result['output']);

        self::assertStringContainsString("skill 'hooks-daemon' is present but NOT in the deploy manifest", $result['output']);

        self::assertFileExists($skillDir . '/plan-qa.md', 'a file only the owner ships must not be deleted by the deploy');
        self::assertSame("# only the daemon ships this\n", \Safe\file_get_contents($skillDir . '/plan-qa.md'));
        self::assertSame("# daemon's newer copy\n", \Safe\file_get_contents($skillDir . '/SKILL.md'), "the owner's copy must not be overwritten");

        // Every MANIFEST-listed skill still deploys — the omission is surgical,
        // not a bail-out.
        foreach ($this->manifestSkillNames() as $skill) {
            self::assertDirectoryExists(\sprintf('%s/.claude/skills/%s', $consumer, $skill), \sprintf("skill '%s' must still deploy", $skill));
        }
    }

    /**
     * The manifest is the shipping CONTRACT, so an entry it lists that the
     * package does not contain is fatal: deploying less than promised is how a
     * guardrail goes missing without anyone noticing.
     */
    public function testManifestEntryMissingFromThePackageAbortsTheDeploy(): void
    {
        $consumer = $this->newConsumer(daemon: false);

        // A throwaway copy of the package whose manifest promises a skill that
        // is not there. Copying keeps the real package untouched.
        $brokenQaci = $this->makeTempBase() . '/qaci';
        $this->runShell(\sprintf('cp -r %s %s', escapeshellarg($this->qaciPath), escapeshellarg($brokenQaci)));
        $this->runShell(\sprintf('rm -rf %s', escapeshellarg($brokenQaci . '/.claude/skills/qa')));

        $result = DeployProcessRunner::run($brokenQaci . '/scripts/deploy-skills.bash', $brokenQaci, $consumer);

        self::assertNotSame(0, $result['exit'], "a manifest/package mismatch must fail the deploy.\n" . $result['output']);
        self::assertStringContainsString("manifest lists skill 'qa' but it is not in", $result['output']);
    }

    // =====================================================================
    // Fixture + process helpers
    // =====================================================================

    /**
     * Create a synthetic consumer project in a private temp tree and return its
     * absolute path. The project sits one level below the temp base so the
     * script's `dirname(PROJECT_ROOT)/.claude/hooks-daemon.yaml` monorepo probe
     * sees a clean parent.
     */
    private function newConsumer(bool $daemon): string
    {
        $base = $this->makeTempBase();
        $root = $base . '/project';
        $this->makeDir($root . '/.claude');
        $this->makeDir($root . '/src');
        $this->makeDir($root . '/.git/hooks');

        $this->writeJson($root . '/composer.json', [
            'name'    => 'acme/widget',
            'type'    => 'project',
            'require' => ['lts/php-qa-ci' => '^1.0'],
        ]);

        if ($daemon) {
            $this->write($root . '/.claude/hooks-daemon.yaml', "handlers:\n  pre_tool_use: {}\n");
        }

        return $root;
    }

    /**
     * Run deploy-skills.bash with the working directory set to the consumer root
     * (production fidelity — see class docblock).
     *
     * @return array{exit: int, output: string}
     */
    private function runDeploy(string $consumer): array
    {
        return DeployProcessRunner::run($this->scriptPath, $this->qaciPath, $consumer);
    }

    // =====================================================================
    // Manifest introspection (expectations derived from the SHIPPING CONTRACT)
    // =====================================================================
    //
    // These read scripts/lib/deploy-manifest.inc.bash — the explicit list of
    // what php-qa-ci deploys — rather than globbing the source tree. Globbing
    // the source would make the test tautological with the old glob-driven
    // deploy: it could only ever assert "we deployed whatever was there",
    // which is exactly the behaviour the manifest exists to replace. Reading
    // the manifest means these tests fail if the deploy stops honouring it.

    /** @return list<string> */
    private function manifestSkillNames(): array
    {
        return $this->manifestArray('PHPQACI_DEPLOY_SKILLS');
    }

    /** @return list<string> */
    private function manifestAgentFiles(): array
    {
        return $this->manifestArray('PHPQACI_DEPLOY_AGENTS');
    }

    /** @return list<string> */
    private function manifestHookFiles(): array
    {
        return $this->manifestArray('PHPQACI_DEPLOY_HOOKS');
    }

    /**
     * Source the manifest in bash and read one of its arrays back, so the
     * expectations track the single source of truth instead of duplicating it.
     *
     * @return list<string>
     */
    private function manifestArray(string $arrayName): array
    {
        $manifest = $this->qaciPath . '/scripts/lib/deploy-manifest.inc.bash';
        self::assertFileExists($manifest, 'deploy manifest not found — has it moved?');

        $values = ShellRunner::readManifestArray($manifest, $arrayName);
        self::assertNotEmpty($values, \sprintf('%s is empty — the manifest must list what we ship', $arrayName));

        return $values;
    }

    /** @return list<string> deployed classic hook basenames under the consumer */
    private function deployedHookFiles(string $consumer): array
    {
        $dir = $consumer . '/.claude/hooks';
        if (!is_dir($dir)) {
            return [];
        }

        return $this->filesWithSuffix($dir, '.py');
    }

    // =====================================================================
    // JSON / settings helpers
    // =====================================================================

    /**
     * @param array<mixed> $settings
     *
     * @return list<string>
     */
    private function hookCommands(array $settings, string $event): array
    {
        $hooksByEvent = $settings['hooks'] ?? [];
        if (!\is_array($hooksByEvent)) {
            return [];
        }

        $sections = $hooksByEvent[$event] ?? [];
        if (!\is_array($sections) || [] === $sections) {
            return [];
        }

        $first = $sections[0] ?? [];
        if (!\is_array($first)) {
            return [];
        }

        $hooks = $first['hooks'] ?? [];
        if (!\is_array($hooks)) {
            return [];
        }

        $commands = [];
        foreach ($hooks as $hook) {
            if (\is_array($hook) && isset($hook['command']) && \is_string($hook['command'])) {
                $commands[] = $hook['command'];
            }
        }

        return $commands;
    }

    /** @return array<mixed> */
    private function readJson(string $path): array
    {
        $decoded = \Safe\json_decode($this->read($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'Not a JSON object: ' . $path);

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $json = \Safe\json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $this->write($path, $json . "\n");
    }

    // =====================================================================
    // Filesystem primitives (guarded — no silent failures)
    // =====================================================================

    /**
     * Content hash + executable bit of every regular file under $dir, keyed by
     * relative path.
     *
     * @return array<string, string>
     */
    private function hashTree(string $dir): array
    {
        $hashes   = [];
        $baseLen  = \strlen($dir) + 1;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $fileInfo) {
            self::assertInstanceOf(SplFileInfo::class, $fileInfo);
            if (!$fileInfo->isFile()) {
                continue;
            }

            $absolute          = $fileInfo->getPathname();
            $relative          = substr($absolute, $baseLen);
            $hashes[$relative] = sha1($this->read($absolute)) . ':' . (is_executable($absolute) ? 'x' : '-');
        }

        ksort($hashes);

        return $hashes;
    }

    /** Run a shell command for fixture setup, asserting it succeeded. */
    private function runShell(string $command): void
    {
        $result = ShellRunner::run($command);
        self::assertSame(0, $result['exit'], \sprintf("fixture command failed: %s\n%s", $command, $result['output']));
    }

    private function makeTempBase(): string
    {
        $base = sys_get_temp_dir() . '/phpqaci-deploy-' . bin2hex(random_bytes(8));
        $this->makeDir($base);
        $this->tempBases[] = $base;

        return $base;
    }

    private function makeDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        // Safe\mkdir throws on failure — no return value to assert on.
        \Safe\mkdir($path, 0o755, true);
    }

    private function write(string $path, string $content): void
    {
        $this->makeDir(\dirname($path));
        \Safe\file_put_contents($path, $content);
    }

    private function read(string $path): string
    {
        return \Safe\file_get_contents($path);
    }

    private function chmodExec(string $path): void
    {
        \Safe\chmod($path, 0o755);
    }

    /** @return list<string> */
    private function scanDir(string $dir): array
    {
        $entries = \Safe\scandir($dir);

        $result = [];
        foreach ($entries as $entry) {
            self::assertIsString($entry);
            if ('.' !== $entry && '..' !== $entry) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function filesWithSuffix(string $dir, string $suffix): array
    {
        $files = [];
        foreach ($this->scanDir($dir) as $entry) {
            if (str_ends_with($entry, $suffix) && is_file($dir . '/' . $entry)) {
                $files[] = $entry;
            }
        }

        sort($files);

        return $files;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if ($fileInfo->isDir()) {
                \Safe\rmdir($fileInfo->getPathname());
                continue;
            }

            \Safe\unlink($fileInfo->getPathname());
        }

        \Safe\rmdir($dir);
    }
}
