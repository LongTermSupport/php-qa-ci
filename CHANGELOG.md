# Changelog

Changes a consuming project has to know about: anything that alters the public
CLI surface, an exit code, a config contract, or the behaviour of a lane in a way
a green build could notice. Routine internal work is in git history and does not
belong here.

Every change to a path a consumer receives (`src/`, `bin/`, `configDefaults/`,
`templates/`, the shipped PHARs and binaries, `composer.json`, the deployed
`.claude/` assets; the list is in `qaConfig/qa.php`) needs an entry under
`## Unreleased`, and the `changelog` lane fails the build without one. A commit
no consumer could notice says so instead, with the trailer
`Changelog: none — <reason>`.

`## Unreleased` takes these headings and no others, each once, and the release
cut from it is decided by them: `### Changed — breaking`, `### Removed`,
`### Added`, `### Changed` and `### Deprecated` release a new minor version;
`### Fixed` and `### Security` alone release a patch. The major is the PHP line
(`85` for the `php8.5` branch, the locked-major policy `qaConfig/qa.php`
declares), so a breaking change moves the minor: read the BREAKING entries
before taking one. A green push to the branch opens a release
pull request moving `## Unreleased` into a version section; merging it publishes
the release and its tag. The full rules are in
[docs/tools/changelog.md](docs/tools/changelog.md).

## Unreleased

### Changed — breaking

- **A new always-on lane, `documentedQaCommands` (`-t dqc`, identifier `phpqaci.documentedQaCommands`), fails a build whose documentation gives a `qa -t` command that `bin/qa` refuses.** It reads every `.md` file in `README.md`, `CLAUDE.md`, `docs/`, `CLAUDE/` (not `CLAUDE/Plan/`), `.claude/agents/`, `.claude/skills/`, `qaConfig/` and `templates/`, finds each `qa` invocation with a literal `-t` token (behind `vendor/bin/`, `bin/` or `{bin}/`, inside fenced blocks too), and reports a token the project's tool registry does not accept (tokens are case-sensitive, and a lane's name is a token only when it is also an alias) or `-p` given to a tool that takes no path, such as a phase runner. A tool or phase the project adds in `qaConfig/pipeline.php` is accepted, as is one the same document registers in a `pipeline.php` example; placeholders such as `<tool>` and `{tool}` are not read. A project whose documents carry such a command now fails the linting phase until it corrects the command, as `vendor/bin/rule-doc phpqaci.documentedQaCommands` describes. That includes the agents and the `qa` skill php-qa-ci deployed before this release, which give `-t allCs` and `-t toolname`: a `composer install` or `update` replaces them with corrected copies, but a project that sets `PHP_QA_CI_DISABLE_CONFIG_PUSH=true` keeps the old `.claude/agents/php-qa-ci_*.md` and `.claude/skills/qa/SKILL.md` and fails the lane until it deploys them again (a `composer install` without that variable) or corrects those files. ([#228](https://github.com/LongTermSupport/php-qa-ci/issues/228))

- **A new always-on PHPStan rule, `phpqaci.processStopOnThrow`, requires a Symfony `Process` whose child runs alongside your code to be stopped in a `finally`.** It reports `start()`, `run()`, `mustRun()`, `wait()` or `waitUntil()` given a callback, and `run()`, `mustRun()` or `wait()` with no callback when the same function gives the process an input that is, or may be, a `Traversable` (a generator, an iterator, an `InputStream`, or a `mixed` value), because Symfony iterates that input while the child is alive; such an input is itself reported when the function that gives it does not start or run the process. A call is not reported when it is in the `try` or a `catch` block of a `try` whose `finally` calls `stop()` on the same process (for a process held in a variable a loop in the `try` sets, inside a `foreach` over that variable, since a single stop reaches only the last one), or, in a PHPUnit test, the process is a `$this->` property that `tearDown()` or an `#[After]` method stops, directly, through a method of the class it calls, or as a method a trait provides. If that code throws, nothing else stops the child: the `Process` holds its own callback in a reference cycle, so the child keeps running until PHP's cycle collector happens to free it. Calls with no callback and no iterator input are not reported, nor is a `start()` with neither that is the statement immediately before such a `try`. A project with such a call now fails the `phpstan` lane until it moves the call into a `try` and stops the process in the `finally` (`if ($process->isRunning()) { $process->stop(0.0); }`, which follows SIGTERM with SIGKILL at once instead of waiting `stop()`'s ten-second default), as `vendor/bin/rule-doc phpqaci.processStopOnThrow` describes. ([#154](https://github.com/LongTermSupport/php-qa-ci/issues/154))

- **The Infection lane now fails a run in which no test ran for any mutant counted as killed.** Infection scores a mutant as killed whenever the test process exits non-zero, and the lane skips Infection's initial test run, so a test suite that cannot start under Infection (a PHPUnit extension or bootstrap that fails under Infection's generated config) "killed" every mutant and the lane passed on a vacuous score. The lane now runs Infection from a copy of `infection.json` under `var/qa/infection-config/` that always writes a JSON log (`var/qa/infection/infection-log.json`, or the file your config's `logs.json` already names; a `logs.json` sent to a `php://` stream is replaced by the default file, and other `php://` log targets are kept as written), reads every killed mutant's test output from it, and crashes with `phpqaci.infection` when every one shows that PHPUnit ran no test, naming the mutants and printing the first one's output. When only some do, a kill no test made is still not evidence. Such kills come as readily from a suite that starts only some of the time under Infection as from a mutant that breaks code the test bootstrap runs. So for a passing run the lane works out both scores without them, from the counts in that log. If the floors still hold (the diff floor on a diff run), the run passes and the lane names the kills in a notice. If they do not, the pass rests on those kills, and the lane crashes, naming them and the scores without them ([#125](https://github.com/LongTermSupport/php-qa-ci/issues/125)). A run that passes without writing that log crashes too, as does a log whose stats do not add up to the MSI it reports. If your Infection runs now fail this way, the scores they reported were not measurements: make the suite start under Infection, as `docs/tools/infection.md` describes.

- **The Infection lane now fails a run in which a file it would mutate is already loaded before PHPUnit's bootstrap.** Infection swaps a file for its mutant by intercepting the `include` that loads it, from its own bootstrap on, but PHPUnit loads Composer's autoloader first, and Composer runs every `autoload` and `autoload-dev` `files` entry then. A source file such an entry loads, directly or through a class it uses, kept its original code in every mutant run, so each of its mutants escaped whatever the tests asserted, and the score could not tell untestable code from a missing test. Before any coverage is generated, the lane now loads `vendor/autoload.php` as PHPUnit's runner does (the new `bin/autoload-included-files`) and crashes with `phpqaci.infection`, naming each loaded file that Infection would mutate (under the source directories, not in a dot or version-control directory Infection's Finder skips, not excluded, and on a diff run among the files the run mutates); an autoloader that cannot be loaded that way, or a `source.excludes` regex that does not compile, crashes it too ([#155](https://github.com/LongTermSupport/php-qa-ci/issues/155)). If your runs now fail this way, load nothing from your source tree in a `files` entry; a class the tests need before every bootstrap belongs in the PHPUnit bootstrap, as `docs/tools/infection.md` describes.

- **`ShellCheckInstaller::main()`, `TurboInstaller::main()` and `FullQaBlockerConfigurator::main()` now take the console output as their last argument, and `ShellCheckInstaller`'s constructor takes its standard and error outputs, the HTTP request and whether this is a maintainer build.** Each built its own `ConsoleOutput` (or wrote straight to `STDOUT` and `STDERR`), so a test that called it wrote past PHPUnit, where nothing could assert it, and a write to stderr stops Infection's initial test run; and `ShellCheckInstaller`'s update could not run without GitHub. `bin/shellcheck-install`, `bin/turbo-install` and `bin/hooks-daemon-full-qa-blocker` pass `new ConsoleOutput()` and behave as before; code that calls one of these methods directly passes one too, and code that constructs `ShellCheckInstaller` should call `main()` instead, which supplies the real request and the phive check.

- **`RequireReadonlyServiceRule`'s constructor now takes PHPStan's `ReflectionProvider`.** The rule reads a class's interfaces through it, so that its `LoggerAwareInterface` / `ResetInterface` exemption applies. PHPStan supplies it when the rule is registered from `rules-optional.neon`, so a project enabling the rule there changes nothing; code that constructs the rule itself, such as a test, passes one. ([#103](https://github.com/LongTermSupport/php-qa-ci/issues/103))

- **A new always-on PHPStan rule, `phpqaci.unanchoredTestsSubstringCheck`, reports deciding whether a file is test code with a bare `tests/` substring.** It reports `str_contains`, `strpos`, `stripos`, `str_starts_with` or `str_ends_with` given a string literal, or a class constant whose value PHPStan knows, that contains `tests/`. A file path is absolute, so such a check holds for every file of a project whose checkout sits under a directory named `tests`, and a rule that exempts test code then exempts the whole project and reports nothing. A fragment concatenated onto a root variable is not reported. It also reports the same check on a name: `str_contains`, `strpos` or `stripos` given exactly `Tests`, as a literal or a variable PHPStan knows may hold it, which takes `App\TestsHelper` for test code. A project with such a check now fails the `phpstan` lane until it anchors the check on the project root, as `vendor/bin/rule-doc phpqaci.unanchoredTestsSubstringCheck` describes. ([#214](https://github.com/LongTermSupport/php-qa-ci/issues/214))

- **A new always-on PHPStan rule, `phpqaci.pathWordSubstringCheck`, reports a bare word searched for in an absolute path.** It reports `str_contains`, `strpos`, `stripos`, `strrpos`, `strripos`, `strstr` or `stristr` (or their `mb_` forms) given a needle that is, or may be, a word with no separator or dot in it, such as `'Magento'`, when the haystack is known to be a path: `__DIR__`, `__FILE__`, the result of `realpath()`, `getcwd()`, `dirname()`, `sys_get_temp_dir()` or `tempnam()`, `SplFileInfo::getRealPath()`, `getPathname()` or `getPath()`, a reflection's `getFileName()`, PHPStan's `Scope::getFile()`, a string parameter or property whose name states an absolute or root location (`root` after another word, as `$absPathRoot` or `$projectRoot`; a directory word, as `$cacheDir`, `$rootDir` or `$workingDirectory`; or a path qualified `abs`, `absolute`, `real` or `full`, as `$absPath` or `$realPath`), a concatenation that starts with one of those, or a local variable no write in the function gives anything but one. A bare `$path`, `$file`, `$fileName` or `$filePath`, a name with `relative` in it, and a bare `$root` are not read as paths, since they hold URL paths, basenames and relative paths as often; a dotless needle such as `'json'` is a word and is reported. Such a check holds for every project checked out under a directory carrying the word, and fails for the project it means wherever it is checked out under another name. A project with such a check now fails the `phpstan` lane until it decides from what the project is, or compares a path segment below the project root, as `vendor/bin/rule-doc phpqaci.pathWordSubstringCheck` describes. ([#231](https://github.com/LongTermSupport/php-qa-ci/issues/231))

- **`phpqaci.unanchoredVendorSubstringCheck` now reads a class constant as well as a literal.** `str_contains($fileName, self::VENDOR_DIR)`, with the constant holding `/vendor/`, is the same unanchored check as the literal form and is now reported too. A project with such a check now fails the `phpstan` lane until it anchors the check on the project root, as `vendor/bin/rule-doc phpqaci.unanchoredVendorSubstringCheck` describes. ([#225](https://github.com/LongTermSupport/php-qa-ci/issues/225))

- **A new always-on PHPStan rule, `phpqaci.unanchoredNamespaceSubstringCheck`, reports deciding whether a class is in a namespace by finding a bare word anywhere in its name.** It reports `str_contains`, `strpos` or `stripos` looking for a namespace-segment word such as `Generated` (a literal, a constant, or a variable PHPStan knows may hold it) in a class or namespace name: a `class-string`, `Scope::getNamespace()`, a reflected class's name, a node's `namespacedName`, a concatenation starting with one, or a local variable assigned one. Such a check also holds for `App\GeneratedReports\Invoice`, so a rule that exempts the namespace exempts classes nobody chose to exempt. A project with such a check now fails the `phpstan` lane until it compares whole namespace segments, as `vendor/bin/rule-doc phpqaci.unanchoredNamespaceSubstringCheck` describes. ([#225](https://github.com/LongTermSupport/php-qa-ci/issues/225))

- **The optional `phpqaci.requireExplicitDIAttribute` now skips a class for `PHPStan`, `Migrations` or `Generated` only when one of its namespace segments is exactly that word.** It skipped any class whose fully qualified name contained one of the words anywhere, so `App\GeneratedReports\Invoice`, `App\PHPStanAdapter\Client`, `App\DataMigrations\Importer` and a class named `App\Domain\MigrationsRunner` were never checked. The match is now on whole namespace segments, case-sensitively: `App\Migrations\Version1` and `Vendor\Pkg\PHPStan\Rule` are still skipped, and a Doctrine migration (a class extending `Doctrine\Migrations\AbstractMigration`) is now skipped in any namespace, including Symfony's default `DoctrineMigrations`, which the old substring match exempted only by accident. A Symfony project that enables the rule may now see findings in classes that were being skipped; give each `#[Autoconfigure]` or `#[Exclude]`, as `vendor/bin/rule-doc phpqaci.requireExplicitDIAttribute` describes. ([#225](https://github.com/LongTermSupport/php-qa-ci/issues/225))

- **A test file outside `<project root>/tests/` is now exempt from `phpqaci.newDateTime`, `phpqaci.inlinePhpstanIgnore`, `phpqaci.factorySealed` and `phpqaci.requireExplicitDIAttribute` only when its namespace has a segment ending in `Tests`.** These rules exempted any file whose absolute path contained `/tests/`, which also exempted every file of a checkout under a directory named `tests` (see Fixed). They now take a file for test code when it is under the project's own `tests/` directory, or when its namespace (for `phpqaci.factorySealed` and `phpqaci.requireExplicitDIAttribute`, the enclosing class's name) has a segment ending in `Tests`, case-sensitively. A monorepo package's tests under `packages/foo/tests/` therefore keep their exemption in `Vendor\Pkg\Tests\…` or `Vendor\Pkg\IntegrationTests\…`, and are checked as production code in a namespace with no such segment, which can now fail the `phpstan` lane. Give such tests a namespace segment ending in `Tests`, as a PSR-4 `autoload-dev` mapping for the package's tests usually does. ([#214](https://github.com/LongTermSupport/php-qa-ci/issues/214))

- **The constructors of `ForbidNewDateTimeRule`, `ForbidInlinePhpstanIgnoreRule`, `ForbidUnisolatedGitProcessRule` and `RequireExplicitDIAttributeRule` now take a `TestCodeDetector`, and `FactorySealedRule`'s takes one as its second argument.** The rules decide test code through it, anchored on the project root. PHPStan supplies it when the rules are registered from the shipped neon files, so a project that enables them there changes nothing; code that constructs one of these rules itself, such as a test, passes `new TestCodeDetector($projectRoot)`, and code that passes `FactorySealedRule`'s sealing attributes by position passes them third. ([#214](https://github.com/LongTermSupport/php-qa-ci/issues/214))

- **A new always-on PHPStan rule, `phpqaci.safeCallComparedToFalse`, reports a `Safe\` function's result tested for the `false` it never returns.** Rector's Safe set rewrites a native function that returns `false` on failure, such as `realpath()`, into its `Safe\` wrapper, which throws a `Safe\Exceptions` exception instead, so a `false === realpath($path)` check written for the failure becomes dead and the code throws on the case it was written for. The rule reports a `Safe\` call compared with `false` by `===` or `!==` in either order when the function's declared return type cannot be `false`, and one used as an `if`, `elseif`, `while`, `do … while`, `for`, ternary, `!`, `&&`, `||`, `and`, `or` or `xor` condition, or compared with `false` by `==` or `!=` (which test the same truthiness), when its declared return type is always truthy or a non-empty string. It sees the call through `@` and through an assignment, as in `false === @\Safe\realpath($path)`, and as a PHP 8.5 pipe into the function's first-class callable, as in `false === ($path |> \Safe\realpath(...))`, and judges the type the function declares, not a type PHPStan has narrowed after an earlier check of the same call. A `Safe\` function that can itself return `false` (`Safe\json_decode()`) or a falsy value (`Safe\preg_match()`'s `0`) is not reported. It is in `rules-default.neon`, so a project with such a check now fails the `phpstan` lane until it catches the `Safe\` exception or uses an API that reports failure without throwing, such as `SplFileInfo::getRealPath()`, as `vendor/bin/rule-doc phpqaci.safeCallComparedToFalse` describes. ([#243](https://github.com/LongTermSupport/php-qa-ci/issues/243), [#246](https://github.com/LongTermSupport/php-qa-ci/issues/246))

- **The `changelog` lane now fails a branch whose commits carry a `Changelog:` line git does not read as a trailer.** Git reads trailers only from the last paragraph of a commit message, so a `Changelog: none — <reason>` line in its own paragraph above `Co-Authored-By:` declared nothing: the lane ignored it without a word, and when the commit touched a watched path it failed as if no line had been written. On a branch or in a pull request build the lane now reports every line after the subject that starts with the `Changelog` key, matched as git matches it (any case, space allowed before the colon), but is not one of the commit's trailers, by commit, whether or not that commit touches a watched path, and fails (`phpqaci.changelog`) until each is moved into the final trailer block, with no blank line between it and `Co-Authored-By:`. A line in the subject's own paragraph is judged; the subject line itself never is. On the default branch, measured since the last release tag, such lines are listed in the report but do not fail the lane, because that history is not rewritten. A project with such a commit on an open branch now fails the lane until it rewords the commit, as `docs/tools/changelog.md` describes. ([#248](https://github.com/LongTermSupport/php-qa-ci/issues/248))

### Removed

- **`docs/phpqa-tools.md` is gone.** It listed the tools by hand and had fallen behind them: it called Yaml Lint a Symfony-only lane that `-t` cannot select, when `vendor/bin/qa -t yaml` runs it on any project with `symfony/yaml`, and it omitted several lanes. `vendor/bin/qa -h` lists every tool in pipeline order, `docs/pipeline.md` says what each phase is for, and each lane has its page under `docs/tools/`, linked from the identifier index `docs/phpstan-rules/README.md`. A link to the old page from your own documentation now fails your `markdownLinks` lane; point it at one of those. ([#216](https://github.com/LongTermSupport/php-qa-ci/issues/216))

### Added

- **An optional PHPStan rule, `phpqaci.processStream`, reports the process's own standard streams used inside a class, function or closure.** It reports the `STDIN`, `STDOUT` and `STDERR` constants, including as a parameter default, and a `php://stdin`, `php://stdout`, `php://stderr` or `php://fd/N` stream opened by name with `fopen()`, `file_put_contents()`, `file_get_contents()`, `file()`, `readfile()` (or their `Safe\` wrappers) or `new SplFileObject()`, whether the name is written there or held in a variable or constant; `new ConsoleOutput()`; PHP's `readline()` and `readline_*()` functions (a project's own function of such a name, which an unqualified call in its namespace reaches first, is not reported); and `error_log()` to the SAPI logger, which is stderr on the command line. A test cannot capture or assert what such code writes, and a write to stderr makes Infection stop its initial test run (PHPUnit exit 143) wherever random order puts the test. Script-level code, such as a `bin/` entry script, and PHPUnit runner extensions are not reported, nor are `echo` and `print`, which PHPUnit captures. It is in `rules-optional.neon`; the fix is to take the stream as a parameter that the entry script passes, as `vendor/bin/rule-doc phpqaci.processStream` describes.
- **An optional PHPStan rule, `phpqaci.unvalidatedRegexPattern`, reports a regular expression compiled from a value the code does not own where a bad one would not be reported by name.** PCRE only warns on a pattern that does not compile, and `thecodingmachine/safe` turns that into `PREG_INTERNAL_ERROR: Internal error`, so a bad pattern from a command line, a configuration file or a caller fails without saying which value to fix. The rule reports a `preg_*` call, PHP's or `Safe\`'s, whose pattern is not built from literal strings, `preg_quote()` given the pattern's own delimiter and local variables that are themselves built that way, unless the function installs an error handler before the call and the call is in a `try` whose `finally` restores it. It is in `rules-optional.neon`; the fix is to compile such a pattern under an error handler that throws naming it, as `vendor/bin/rule-doc phpqaci.unvalidatedRegexPattern` describes. ([#192](https://github.com/LongTermSupport/php-qa-ci/issues/192))
- **An optional PHPStan rule, `phpqaci.unisolatedGitProcess`, reports test code that runs git with the host's git configuration.** In a namespace with a segment ending in `Tests` or a file under the project's own `tests/` directory, it reports a Symfony `Process` (constructed, or built by `Process::fromShellCommandline()`) or a `proc_open()` whose command is git, unless the environment it is given sets both `GIT_CONFIG_GLOBAL` and `GIT_CONFIG_NOSYSTEM` in a way PHPStan can see: a literal array, a constant, or a value typed as an array shape with both keys. A child process inherits the environment, so without them the global and system config of whoever runs the suite apply: a signing config makes `git commit` leave key files in the temp directory, and hooks, a default branch or `log.showSignature` change what the test sees, on a developer's machine and not in CI. Production code is not reported, since it runs git on the user's own repository. It is in `rules-optional.neon`; the fix is to give the process such an environment, as `vendor/bin/rule-doc phpqaci.unisolatedGitProcess` describes. ([#206](https://github.com/LongTermSupport/php-qa-ci/issues/206))
- **`PhaseDto` takes `repeatsUntilSettled`.** A phase a project adds through `qaConfig/pipeline.php` can set it to `true`, so that a writable run repeats the phase until a pass changes no file under the checked paths, at most three passes, as the coding-standards phase does. It defaults to `false`. ([#193](https://github.com/LongTermSupport/php-qa-ci/issues/193))

### Changed

- **Bundled tool versions updated** by the dependency update: phpstan/phpstan (in rector.phar) 2.2.16 → 2.3.1; rector/rector 2.6.7 → 2.7.0.

- **php-qa-ci's own `CLAUDE.md` is much smaller, and three routing `CLAUDE.md` files join it.** A consumer's agent that reads a file under `vendor/lts/php-qa-ci/` loads that `CLAUDE.md`; it now holds the binding rules, the active defences and a map to the documents that carry the depth, and its hand-written part is about a tenth of its former size. `src/Pipeline/CLAUDE.md`, `src/PHPStan/CLAUDE.md` and `scripts/CLAUDE.md` load when a file in their directory is read and point at the contributor documentation for that area. Nothing a consumer configures or runs changes.

- **Bundled tool versions updated** by the dependency update: infection 0.35.6 → 0.35.8.

- **`qa -t phpstan -p FILE`, the edit-time lint, now starts one PHPStan process instead of three when nothing it depends on has changed.** The lane's `dump-parameters` (does the project set its own `tmpDir`?) and `diagnose` (is Turbo running?) answers are now kept in `var/qa/cache/phpstan-setup/`, keyed by a hash of the phar, every NEON file PHPStan merges, the Turbo binaries and manifest, and the PHP that runs PHPStan (its binary, ini files and version), and a `-p` run whose key matches reuses them. Measured on php-qa-ci itself, a warm one-file run takes about 2.7 s instead of 5.7 s. A run without `-p` still asks PHPStan each time and refreshes the cache; a config that reads `%env.NAME%`, includes a PHP config file or an include the lane cannot follow is never cached. Each text-mode run now also prints one `php -r` line, the question the key asks of the PHP that runs PHPStan. Deleting `var/qa/` resets it; see [docs/tools/phpstan.md](docs/tools/phpstan.md#how-the-lane-runs). ([#219](https://github.com/LongTermSupport/php-qa-ci/issues/219))

### Fixed

- **A failed Turbo install no longer says PHPStan runs without Turbo when it may not.** On any failure of `bin/turbo-install`, the php-qa-ci Composer plugin printed `php-qa-ci: the PHPStan Turbo install failed (see above); PHPStan runs without Turbo`. A failure before the fetch (a `vendor-phar/turbo-ext.json` for another PHPStan release or one that cannot be read, a binary that cannot be removed) leaves the earlier binary beside its core, and a failure after the files are placed (the stamp cannot be written) leaves the new pair; `phpstan.phar` may load either. The plugin now prints `php-qa-ci: the PHPStan Turbo install failed; the installer's output above says what happened`, and every failed install now follows its `ERROR:` line with what it left, judged from the files on disk: `No Turbo binary is in place beside its core, so PHPStan runs without Turbo until an install succeeds.`, or `WARNING: phpstan.phar may still load <binary>, which is still beside its core, though this install could not vouch for it. Delete it to run PHPStan without Turbo.` ([#266](https://github.com/LongTermSupport/php-qa-ci/issues/266))

- **A Turbo install that cannot fetch the new binary no longer leaves the earlier one for PHPStan to load.** When the binary in `vendor-phar/turbo-ext/` is not the one its stamp records (after a PHPStan update, or a killed install that placed the files but not the stamp), the install fetches it again. If a download failed, the install printed `WARNING: could not download turbo-ext/<path>; PHPStan runs without Turbo until the next composer install.` but left the earlier binary in place. `phpstan.phar` loads any binary it finds beside a core, so the next run used that binary: one built for another PHPStan release, or a binary from one release beside the core from another. A binary that fails the SHA-256 check left the earlier binary in place as well. The install now removes the binary and its stamp before it downloads anything, so after a failed fetch PHPStan really does run without Turbo until an install succeeds. ([#263](https://github.com/LongTermSupport/php-qa-ci/issues/263))

- **The `deadCode` lane's detector unpack and the Turbo install now stage only beside what they replace, or fail naming the directory.** Each makes its staging directory in the directory of the file or directory it replaces, so the final `rename()` is on one filesystem and swaps it atomically. Where that directory could not hold a new entry (its path too long for one, or a directory the process may not write to), `tempnam()` quietly made the staging directory in the system temp directory instead, often another filesystem, where the `rename()` copies into the existing file and rewrites it in place: a PHPStan with the Turbo binary mapped could crash. That case now fails with `Cannot create a staging directory in <directory>` instead of replacing the file unsafely. A staging directory a killed Turbo install or ShellCheck update left beside its binary (`.phpqa-turbo<16 hex digits>`, `.phpqa-shellcheck<16 hex digits>`) is now removed by the next run once it has not changed for an hour, and so is one an earlier version's Turbo install left (`.phpqa-turbo<6 letters and digits>`, such as `.phpqa-turboAb12Cd`); one the `deadCode` lane left (`.unpack*` under `var/qa/cache/dead-code-detector/`) is removed on every run, with everything else there but the current copy (#85). Only a directory named exactly as a staging directory is (its prefix, then 16 lowercase hex digits, or then exactly 6 letters and digits as earlier versions named one) is removed, so nothing else beside the binary is. That cleanup never stops a run: a leftover another run removed first is passed over, and one that cannot be removed is kept, with the Turbo install printing `WARNING: could not remove <directory>, a staging directory a killed install left: <reason>` and the ShellCheck update `WARNING: could not remove <directory>, a staging directory a killed update left: <reason>`. A staging directory the Turbo install cannot make is reported on its usual `ERROR: could not install the Turbo files: ...` line with exit 1, not as an uncaught exception. ([#251](https://github.com/LongTermSupport/php-qa-ci/issues/251))

- **The `deadCode` lane no longer fails on every run after its unpacked detector is left incomplete, and it now removes the copies of earlier detector PHARs.** The lane unpacks `dead-code-detector.phar` into `var/qa/cache/dead-code-detector/<hash>/`. When that directory existed without `vendor/autoload.php` (after a partial `rm`, a hand edit or a copied cache), each run failed with `rename(...): Directory not empty` until someone deleted `var/qa/cache`. The incomplete copy is now moved aside and replaced by a fresh one. Everything else in `var/qa/cache/dead-code-detector/` is removed on each run: each new detector PHAR left its own directory of about 560 KB (83 files) there, and a killed run could leave its staging directory. The removal is best-effort: an entry the run cannot remove, such as a copy a root container run left in a user's cache, is named in a `WARNING:` line on the lane's output and does not stop the lane. The prune runs before the current copy is unpacked, so the warning does not say the lane goes on: a detector PHAR that cannot be read still fails the run. ([#85](https://github.com/LongTermSupport/php-qa-ci/issues/85), [#262](https://github.com/LongTermSupport/php-qa-ci/issues/262))

- **The `deadCode` lane no longer fails the run in which it replaces an incomplete detector copy it cannot fully remove.** The incomplete copy is moved aside into a `.discard` directory beside the fresh one, and a failure to remove it, such as an entry a root container run left there, failed that run although the fresh copy was already in place. It is now named in a `WARNING:` line on the lane's output, and the lane goes on with the fresh copy; later runs keep trying to remove it, naming it each time they cannot. When the fresh copy cannot be renamed into place either, that failure fails the run, and the warning names the copy without saying the lane goes on. ([#257](https://github.com/LongTermSupport/php-qa-ci/issues/257), [#259](https://github.com/LongTermSupport/php-qa-ci/issues/259))

- **The `psr4` lane's Magento hint now follows the project, not where it is checked out.** A missing PSR-4 path was reported with "Magento's composer includes this by default, it should be removed from the psr-4 section" whenever the path's absolute form contained `Magento` in any case, so a project under `/home/ci/magento-tools/my-app/` got the hint and a Magento project checked out as `/srv/shop/` did not, unless the missing path itself named Magento. The hint is now given when the project's `composer.json` says it is a Magento project: its `name`, or a package in its `require`, is under the `magento/` vendor, in any case. A `magento/*` package only in `require-dev` does not count. ([#231](https://github.com/LongTermSupport/php-qa-ci/issues/231))

- **A dangling `*.php` symlink under the project root no longer crashes the `phpCsFixer` lane.** PHP CS Fixer, run with `--path-mode=intersection`, compares the real path of every file the config's finder yields, and a dangling symlink has none. The fixer died with exit 255 and `TypeError: str_starts_with(): Argument #1 ($haystack) must be of type string, false given`, and named no file. The shipped finder's `ignoreVCSIgnored(true)` did not prevent it, even for a gitignored symlink: Symfony Finder decides whether a file is ignored from its real path too. The lane now always runs the fixer through the config it generates at `var/qa/phpCsFixer/php_cs.php`, which until now it generated only when `withIgnoredPaths()` was set. That config's finder drops such a file, and the lane prints `PHP CS Fixer skipped <path>: its real path cannot be resolved ...` once for each. The lane does not fail on a skipped file, since there is nothing in it to check. ([#239](https://github.com/LongTermSupport/php-qa-ci/issues/239))

- **A dangling symlink no longer stops the `psr4`, `markdownLinks`, `docsProse`, `phpLint` or `phpArkitect` lane without naming it.** Each read a file of its type whose real path cannot be resolved and failed on it: `psr4`, `markdownLinks` and `docsProse` stopped with a `file_get_contents()` error before checking the rest, `phpLint` reported an `Unknown error` for the file that did not say why, and `phpArkitect` reported its read failure as a rule violation (exit 1). Each lane now skips such a file and prints `<lane> skipped <path>: its real path cannot be resolved (a symlink whose target does not exist), so it was not checked` once for it, as `phpCsFixer` does, and still checks every other file; a skipped file does not fail the lane, and `phpLint` passes without running parallel-lint when every file it would lint is skipped. A root `README.md` that is a dangling symlink still fails `markdownLinks`, now with a message naming it as a symlink whose target does not exist instead of `You have no README.md`. `bin/psr4-validate` names such a file the same way. `phpArkitect` drops it in the shipped class set (`phparkitect-class-set.php`, which now always wraps the set) and lists it at `var/qa/phparkitect_logs/unresolved-paths.txt`, named to the set by the new `PHPQACI_ARKITECT_UNRESOLVED_LIST` variable; a project entry config that builds its own class set drops nothing. ([#241](https://github.com/LongTermSupport/php-qa-ci/issues/241))

- **A read-only `twigCsFixer` run no longer reads twig-cs-fixer's cache, and the lane no longer leaves `.twig-cs-fixer.cache` in your project root.** twig-cs-fixer caches by default, in `.twig-cs-fixer.cache` in its working directory, and skips any template whose content the cache records as clean, so a read-only run, the pre-push gate (`QA_READONLY=1`) included, could pass on what the cache said rather than what CI, which has no cache, finds. A read-only run now passes `--no-cache` and writes no cache; a writable run keeps its cache in `var/qa/cache/twig-cs-fixer.cache`, through a config generated at `var/qa/twigCsFixer/.twig-cs-fixer.php` that wraps whichever `.twig-cs-fixer.php` resolves (a cache manager that config sets is dropped); and a read-only run that finds a pending fix deletes that cache, so the writable run it asks for checks every template. The `.twig-cs-fixer.cache` an earlier run left in the project root is no longer read or written, and can be deleted. No input was found on which twig-cs-fixer 4.1.1 needs a second pass, as PHP CS Fixer did in #235: it repeats its rules until a pass changes nothing and caches only a template it then lints clean, so a writable run stays a single pass. ([#237](https://github.com/LongTermSupport/php-qa-ci/issues/237))

- **A read-only `phpCsFixer` run no longer trusts PHP CS Fixer's cache, so a gate that passed locally now fails as CI does.** PHP CS Fixer is not idempotent on every input: on a heredoc whose blank lines carry whitespace, one pass of `heredoc_indentation` re-indents the heredoc and only the next strips the blank lines. A cached writable pass recorded the half-fixed file as clean in `var/qa/cache/php_cs.cache`, so every later cached run skipped it, the read-only pre-push gate (`QA_READONLY=1`) included, while CI, which has no cache, failed the same commit. A read-only run now passes `--using-cache=no`, so it checks every file as CI does; a project whose cache was hiding such a file sees the lane fail locally, and that failure deletes the cache, so the next writable run fixes the file. A writable pass that changed a file is now followed by an uncached pass, so the coding-standards phase sees the second change and repeats instead of stopping one pass early; a writable run that changed nothing still costs one cached pass. The read-only run is slower by an uncached fixer pass over the checked paths. ([#235](https://github.com/LongTermSupport/php-qa-ci/issues/235))

- **The deployed `php-qa-ci_phpstan-fixer`, `php-qa-ci_phpunit-fixer` and `php-qa-ci_docs-conflict-checker` agents no longer give `qa -t` commands that `bin/qa` refuses.** The fixer agents told themselves to run `{bin}/qa -t allCs -p [changed-files]` after fixing: the token is `allCS` (tokens are case-sensitive), the phase runner takes no `-p`, and the fixers have no Bash tool and are told elsewhere not to run `qa`. They now list the files they changed instead, so the caller can run `qa -t r -p <file>` and `qa -t f -p <file>` on them, and they no longer say the run → fix → run cycle runs the coding standards between iterations, which it does not; the full pipeline runs them first. The conflict checker no longer describes the fixers as running `allCs`, and its suggested exception names `php-qa-ci_qa-tool-runner` as the agent that runs `allCS`; it and the `qa` skill write the tool as a `<tool>` placeholder rather than the bare word `toolname`. ([#228](https://github.com/LongTermSupport/php-qa-ci/issues/228))

- **`phpqaci.newDateTime`, `phpqaci.inlinePhpstanIgnore` and `phpqaci.factorySealed` no longer skip a whole project that sits under a directory named `tests`.** They took any file whose absolute path contained `/tests/` for test code, so a checkout at `/home/ci/tests/my-app/` had every file exempted and the rules reported nothing. `phpqaci.newDateTime` and `phpqaci.inlinePhpstanIgnore` also took any namespace containing `Tests`, such as `App\TestsHelper`, for a test namespace. Test code is now a file under the project's own `tests/` directory or a namespace one of whose segments ends with `Tests`, case-sensitively (`App\Tests`, `App\FunctionalTests`, `Vendor\Pkg\IntegrationTests`; not `App\TestsHelper` or `App\Contests`). The optional `phpqaci.requireExplicitDIAttribute` likewise skipped any class whose name contained `Tests`, and now decides test code the same way, with the class's own name taken as the last segment; it also skips a class in a file under the project's `tests/`. A project in such a checkout, or with such a namespace, may now see findings in code that was being skipped. ([#214](https://github.com/LongTermSupport/php-qa-ci/issues/214))

- **The `infection` lane resolves a relative `logs.debugEvents` against your `infection.json`'s directory.** Infection 0.35.7 added the `logs.debugEvents` setting (a JSONL trace of Infection's internal events), which Infection resolves against the directory of the config file, as it does every other log path. The lane runs Infection from a copy of the config under `var/qa/`, and it makes every such path absolute in that copy, but it did not know this setting. So a relative `logs.debugEvents` resolved against `var/qa/infection-config/` and the trace was written there instead of where your config named. The copy now makes it absolute too. ([#205](https://github.com/LongTermSupport/php-qa-ci/issues/205))

- **The `markdownFormat` lane now runs in a linked git worktree.** It looked for the hooks daemon only up to the root of the checkout it ran in, and the daemon, which a project gitignores, is installed only in the main checkout, so in a worktree the lane skipped and the run passed whatever the markdown held. At a linked worktree's root it now looks in the main checkout too; a submodule still never uses the daemon of the repository holding it. A run in a worktree may now report markdown the daemon would rewrite that earlier runs let through. ([#199](https://github.com/LongTermSupport/php-qa-ci/issues/199))

- **In a linked worktree, the `markdownFormat` lane crashes when it cannot read the worktree's `.git` file, and finds a daemon installed in a package.** A `.git` file, or the `commondir` it leads to, that exists but cannot be read ended the whole run with an uncaught exception; the lane now crashes, naming the file, since whether the daemon is installed is unknown. In the main checkout the daemon was looked for only at its root, so a monorepo that installs it in one package found none from that package's worktree copy and skipped; it is now looked for from the project's place in the main checkout up to its root, as in the worktree itself. ([#202](https://github.com/LongTermSupport/php-qa-ci/issues/202))

- **The optional `phpqaci.readonlyService` rule no longer reports a class implementing `LoggerAwareInterface` or `ResetInterface`.** Its page lists both as exempt, but the rule looked for them in the scope a class is analysed from, which is outside the class and holds no class, so the exemption never applied and every such class was reported. The rule now reads the class's interfaces through PHPStan's reflection. ([#103](https://github.com/LongTermSupport/php-qa-ci/issues/103))

- **The `infection` lane no longer fails on a link to a directory in its output.** Before each run it empties `var/qa/infection/`, and a standalone `-t infection` also removes `var/qa/phpunit_logs/coverage-xml/`; a link to a directory in either made the lane throw `rmdir(…): Not a directory` instead of running. A link inside them is now removed and the directory it points at left alone. When `var/qa/infection/` is itself a link to a directory, what it points at is emptied and the link kept; when `coverage-xml/` is one, the link is removed and the coverage regenerated. ([#116](https://github.com/LongTermSupport/php-qa-ci/issues/116))

- **A writable `allCS` run now leaves nothing for a read-only run to change.** PHP CS Fixer runs after Rector, so Rector never saw what the fixer wrote, and a read-only run (CI) then failed on a tree a writable run had just fixed. Two shapes did this: the fixer reordered class members without the blank line Rector's `NewlineBetweenClassLikeStmtsRector` wants, and the fixer's `final_class` made a class final, after which Rector's final-class rules (`PrivatizeFinalClassConstantRector`, `RemoveFinalFromConstRector`, ...) applied. In a writable run the full pipeline and `-t allCS` now repeat the coding-standards phase until a pass changes no file under the checked paths, at most three passes; a read-only run and a single tool still run once. The shipped `php_cs.php` also now keeps one blank line between constants, properties and methods (`class_attributes_separation`) and orders enum cases first, where they were sorted after every listed member. On the first writable run after upgrading, an enum whose cases had been moved below its methods gets them back at the top. A project with its own `rector.php`, which replaces the shipped PHP 8.5 config, also sees compact runs of constants and of properties separated by blank lines; with the shipped config Rector already required them. A project that overrides `php_cs.php` should make the same two config changes. A phase whose tools undo each other prints a warning naming the files still changing on the third pass. ([#193](https://github.com/LongTermSupport/php-qa-ci/issues/193))

- **A writable run no longer removes the declared type from a grouped constant.** The shipped `rector-php85.php` split `public const int B = 2, C = 3;` into `public const int B = 2;` and `public const C = 3;`, with `C`'s `int` gone, in any class that is not final, an interface or a trait (Rector's `SplitGroupedClassConstantsRector`, reported upstream as [rectorphp/rector#9928](https://github.com/rectorphp/rector/issues/9928)). The shipped config now skips that rule. PHP CS Fixer's `single_class_element_per_statement` makes the same split and keeps the type. A constant that has already lost its type has to be re-typed by hand. A project with its own `rector.php` does not run `rector-php85.php`; if its config includes `CODING_STYLE`, add the same skip. ([#193](https://github.com/LongTermSupport/php-qa-ci/issues/193))

- **`bin/psr4-validate --help` prints the usage instead of failing with `PREG_INTERNAL_ERROR: Internal error`.** Every argument was compiled as an ignore pattern, so `--help`, `-h` or any other option failed with an error that named neither the argument nor the problem. `--help` and `-h` now print the usage and check nothing. An argument, an entry in `psr4-validate-ignore-list.txt` (in the `psr4Validate` lane and the `configTemplateIgnoreList` audit alike) that does not compile is now refused naming the pattern, where it came from and PCRE's reason, such as `ignore pattern "--verbose" is not a valid regular expression: preg_match(): Unknown modifier 'v'`. Run from a directory with no `composer.json`, `bin/psr4-validate` now says so and exits 1; it used to exit 0. A bad entry in the shipped ignore list now fails the `configTemplateIgnoreList` lane with its identifier instead of ending the run. The `markdownLinks` lane now resolves a relative link correctly in a project whose path holds a regex metacharacter, such as `+` or `(`. ([#192](https://github.com/LongTermSupport/php-qa-ci/issues/192))

- **A tool whose output cannot be written no longer leaves its child running.** When writing a child's output to the console threw (a closed terminal, say), the run ended with the child still running and no longer registered, so interrupting `qa` could not stop it; it ran on until PHP's cycle collector happened to free it. The runner now stops the child whenever a run ends. ([#154](https://github.com/LongTermSupport/php-qa-ci/issues/154))

- **A repeated `-p` or `-t` is now refused instead of silently replacing the first.** `qa -t stan -p A -p B` analysed only `B` and could pass, and `-t stan -t rector` ran only rector, in both the `-p A` and `-pA` spellings. The command line has always taken one tool and one path, and two bare paths were already refused, so a run now exits 1 with `Multiple paths not supported: -p A -p B` or `Multiple tools not supported: -t stan -t rector`. A script that passed a path or tool twice was checking less than it named: run the tool once per path. ([#139](https://github.com/LongTermSupport/php-qa-ci/issues/139))

- An interrupted run no longer prints a PHP warning for a child process that exits while the run is stopping it. The process tree read `/proc/<pid>/stat` with a function that warns before it fails, so a process exiting between the check and the read printed `file_get_contents(/proc/…/stat): Failed to open stream`; it is now read without the warning and treated as gone, as before.

- An interrupted run no longer prints a PHP notice, or waits for and signals a child process that has already exited, when that process exits just as its `/proc/<pid>/stat` is being read. The read then printed `Read of 8192 bytes failed with errno=3 No such process` and came back empty, and the empty line was taken for a running process, so stopping the run could keep waiting for a process that had gone, or send `SIGKILL` to its pid, which may by then belong to another process. A read that fails or comes back empty is now treated like a file that cannot be opened: the process is gone if its `/proc` entry has gone, and it is an error otherwise. ([#118](https://github.com/LongTermSupport/php-qa-ci/issues/118))

- **A failed run no longer ends with the `COMPLETED` banner.** The closing banner said `<host> qa <args> COMPLETED` after every run, including one that exited 1, so a reader skimming for the verdict could take a failure for a pass. A run that fails now ends with `<host> qa <args> FAILED (exit N)`, in aggregate and fail-fast mode alike; a passing run still ends with `COMPLETED`. Anything that matched the old banner to detect the end of a run should match both words. ([#112](https://github.com/LongTermSupport/php-qa-ci/issues/112))

- **The PHPStan and dead-code lanes keep PHPStan's cache inside the project.** Neither set PHPStan's `tmpDir`, so both used `/tmp/phpstan`, which every checkout and project on the host shares, and a stale entry written by one checkout failed a clean tree in another. Each lane now sets `tmpDir` in the wrapper neon it generates: `var/qa/cache/phpstan/` for the PHPStan lane and `var/qa/cache/deadCode/` for dead-code detection, kept apart because PHPStan holds one result cache per `tmpDir`. Deleting `var/qa/` resets them. A `tmpDir` your `qaConfig/phpstan.neon`, or a file it includes, already sets is still used, and the lane prints which cache it runs with. The first run after upgrading starts with an empty cache. A CI job that caches `/tmp/phpstan` between runs should cache `var/qa/cache/phpstan/` (and `var/qa/cache/deadCode/`) instead, or set `tmpDir` in `qaConfig/phpstan.neon` or a file it includes; otherwise every CI run starts cold. ([#123](https://github.com/LongTermSupport/php-qa-ci/issues/123))

- **A `tmpDir` set behind an include PHPStan can resolve, but the lanes' own reading of the config could not, is now honoured.** The PHPStan and dead-code lanes follow your `phpstan.neon`'s NEON includes to see whether it sets `tmpDir`, and could not follow an include built from a `%parameter%` (such as `%env.NAME%`) or a PHP config file; a `tmpDir` set there was silently overridden by the lane's `var/qa/cache/<lane>/`. When their own reading finds no `tmpDir`, the lanes now ask PHPStan for the merged value (`phpstan.phar dump-parameters`, which adds a second or two to each run and runs your `bootstrapFiles` once more), keep any value other than PHPStan's default `%sysGetTempDir%/phpstan`, and print the directory. If PHPStan cannot answer, the lane uses its own cache as before. ([#140](https://github.com/LongTermSupport/php-qa-ci/issues/140))

- **The PHARs php-qa-ci builds itself (`rector`, `phpcpd`, `dead-code-detector`, `composer-dependency-analyser`) are now the same bytes whichever host builds them.** Box added their files in the order the build host's filesystem listed them, so a rebuild with no version change on another host reordered every file and changed every byte, and the daily dependency update failed on four PHARs it had not updated. `scripts/build-phar.bash` now has Box add them in byte order. The four PHARs in this release hold the same files with the same contents as before, reordered; nothing they do changes. ([#120](https://github.com/LongTermSupport/php-qa-ci/issues/120))

- **The deployed `qa-tool-runner` skill and `php-qa-ci_qa-tool-runner` agent no longer carry their own list of tools.** Their lists had drifted from the pipeline: they named a `loc` tool that does not exist and a `-t allCs` token that `bin/qa` refuses (the token is `allCS`), called the strict-types and Composer lanes report-only although both fix files in a writable run, and left out most lanes. Both now take the tokens from `vendor/bin/qa -h` and treat the coding-standards phase as the tools that modify files. Section 5.1 of `docs/upgrading-to-8.5.md` likewise drops its table of override file names, which lacked several lanes, and points at `vendor/bin/rules .`, which lists every lane under the canonical name a `qaConfig/tools/<name>.php` override uses. ([#216](https://github.com/LongTermSupport/php-qa-ci/issues/216))

## 85.7.0 — 2026-10-09

### Added

- **A start-up warning when Xdebug is loaded and OPcache JIT is configured on.** PHP prints "JIT is incompatible with third party extensions" on stdout at every start of a process with Xdebug active, which corrupts anything that parses that output. The note says the pipeline is unaffected and that a PHP started by hand needs `XDEBUG_MODE=off`.

### Changed

- **Infection's auto diff mode makes a run full only for the files every mutant depends on, and leaves comment-only changes out.** The full-run triggers are now the resolved Infection config and its `qaConfig/infection.json{,5}{,.dist}` overrides, the resolved PHPUnit config and its `qaConfig/phpunit.xml`, `phpunit.xml.dist` and `phpunit.dist.xml` overrides, and the bootstrap the PHPUnit config names in its `bootstrap` attribute; `composer.json`, `composer.lock` and the rest of `qaConfig/` no longer force a full run, are no longer watched for uncommitted work and are not mentioned. A modified or renamed PHP file whose tokens, comments and whitespace aside, match its merge-base version is not mutated and is named on one "comment-only change, not mutated" line. In a file where either version has a comment containing a directive Infection or the coverage tool reads (`@infection…`, `@codeCoverageIgnore…`, `@deprecated`), every comment counts as code, verbatim and by line, so any comment change mutates it; an added or copied file, or one whose working copy or base cannot be read, is always mutated; a comment-only test change brings nothing into scope.

### Fixed

- **Every child process the pipeline starts now runs with `XDEBUG_MODE=off`, except the coverage runs (PHPUnit with coverage, Infection's coverage generation), which name their own mode.** Before, a child started without a mode of its own (a git call, the markdown formatter, a shell script) inherited whatever the parent had, so with JIT on and Xdebug loaded the "JIT is incompatible" warning reached stdout and broke output parsing.
- The deployed `pre-commit` hook no longer blocks every commit when `php` prints a start-up warning on stdout, such as the OPcache JIT's "JIT is incompatible with third party extensions" when Xdebug is loaded (#100). It reads `composer.lock` with `XDEBUG_MODE=off` and keeps only `name|reference` lines, where a stray line used to stop it with `bad array subscript`. A package off its locked commit is still caught.
- **PHPStan Turbo runs again with PHPStan 2.3.1.** Its phar loads a Turbo binary only with the platform's shared core (`phpstan_turbo_core.so`) beside it, and both live in the `turbo-ext/` of the phpstan/phpstan tag, not in the phpstan/turbo-ext release zips, which hold a single self-contained build the phar does not accept. `bin/turbo-install` now fetches the binary and its core from the tag into `vendor-phar/turbo-ext/<platform>/`, verifies both against `vendor-phar/turbo-ext.json` (whose `assets` object, keyed by release zip name, is now `files`, keyed by path under `turbo-ext/`) and replaces each atomically. Without it PHPStan ran without Turbo and the lane reported "NOT RUNNING". Run `composer update` to refetch.

## 85.6.1 — 2026-10-09

### Fixed

- A `phar://phpstan.phar/...` include in `qaConfig/phpstan.neon` (PHPStan's documented `phar://phpstan.phar/conf/bleedingEdge.neon`) is PHPStan's own configuration, as `%rootDir%/...` is, and is no longer reported as missing: the `phpstanIgnoreJustification` lane passes it, and `rules --write-agent-summary` lists the active defences instead of writing the "could not be generated" fallback into `CLAUDE.md`. Any archive named `phpstan.phar` is treated so; a `phar://` include into any other archive is read like any other file, and fails when the archive cannot be opened.

## 85.6.0 — 2026-10-09

### Changed — breaking

- **BREAKING**: `composer.json` now requires `ext-hash`. Every PHP build since 7.4 includes it, and
  it cannot be disabled, so nothing needs installing. php-qa-ci uses it to check the SHA-256 of each
  PHPStan Turbo binary it downloads.

- **Infection now mutates only what a branch changed, by default.** With `infectionDiffBase` unset, a run on any branch but the default one mutates the PHP files changed since its merge base with the default branch (the target branch in a pull request build), plus the source each changed test is named after; on the default branch, or where no merge base can be found (a shallow clone, an unknown default branch), it mutates everything and prints why. The first Infection line of every run names the scope. Uncommitted work under `src/`, `tests/`, `qaConfig/`, `composer.json` or `composer.lock` (untracked files included) is mutated as it is on disk, and a `WARNING` names each such file, since that verdict is not reproducible from committed history. A branch is now held to the diff floor (`infectionDiffCoveredMsi`, default `coveredCodeMSI`) on its changed files, which can fail where the whole-codebase floor passed. To keep the full run everywhere, set `infectionDiffBase=full` or call `withInfectionFullRun()`. `withInfectionDiffBase(null)` now means this automatic choice rather than a full run. In CI, check out with `fetch-depth: 0` (the shipped templates do), or every run is full. Details: `docs/tools/infection.md`.

- **A diff-mode Infection run, with an explicit `infectionDiffBase` ref as in auto mode, is stricter:**

  - It mutates uncovered code too (`--with-uncovered`) and holds both its MSI and its covered MSI to the diff floor. Before, it only checked the covered MSI, so an untested changed file passed whenever the scope also held a tested one (a scope of untested files alone already failed, at 0%). It now fails either way.
  - A change to `qaConfig/`, `composer.json` or `composer.lock` since the base turns it into a full run, with one line naming the files. Before, those changes were ignored.
  - The clean-tree refusal of an explicit ref now also covers those configuration paths, and names each file inside an untracked directory rather than the directory.
  - A changed test now brings into scope the source file it is named after.

### Added

- **PHPStan runs with Turbo, its native extension.** `bin/turbo-install` downloads the Turbo
  binary built for the host (OS, CPU, libc, PHP version) into `vendor-phar/turbo-ext/`, where
  `phpstan.phar` loads it. It refuses any download whose SHA-256 differs from the one
  `vendor-phar/turbo-ext.json` pins for the shipped phar. In a consuming project the php-qa-ci
  Composer plugin runs it on every `composer install` and `composer update`. A failed download is
  a warning, not a failed install. A host upstream builds nothing for (Windows, an Intel Mac) runs
  PHPStan without Turbo, as before. A run refuses to start when `vendor-phar/turbo-ext.json` is
  missing or names another PHPStan version than the shipped phar, as it does for a missing PHAR.
  With Turbo, PHPStan forks its workers, so the dead-code lane now unpacks its detector into
  `var/qa/cache/dead-code-detector/` instead of loading it as a second PHAR, which forked workers
  cannot share. On this repository the PHPStan lane runs in about half the time (about 20 s to
  9 s) with identical findings, and each worker's peak memory is about 6% higher, so a project
  already close to its memory limit (4G by default, `withMemoryLimit()`) may need a little more.
  The PHPStan lane's text mode says on every run whether Turbo is running, and when it is not on
  a host php-qa-ci ships a build for, prints `NOT RUNNING` with PHPStan's reasons and the fix;
  the run's outcome is unchanged. `vendor-phar/phpstan.phar diagnose` gives the same answer by
  hand.

### Fixed

- **`infectionOnlyCovered=1` no longer makes Infection refuse to run**
  ([#74](https://github.com/LongTermSupport/php-qa-ci/issues/74)). The lane passed
  `--only-covered`, which the bundled Infection 0.35 no longer has: mutating only covered code is
  now its default. The setting is kept and changes nothing. A new test checks every option the
  Infection lane can emit against the shipped `infection.phar`'s own `--help`, so a tool update
  that drops an option fails the build.

- **`qa -t rector -p <path>` no longer runs the PHPUnit Rector set over the whole tests directory**
  ([#76](https://github.com/LongTermSupport/php-qa-ci/issues/76)). The Safe, project and PHP 8.5
  passes were narrowed to the given path, but the PHPUnit pass always got all of `tests/`, so a
  one-file run took about as long as a whole-tree run. On a `-p` run it now gets only the checked
  paths inside the tests directory, the whole directory for a path that contains it (`-p .`), and
  is skipped when there are none. A run without `-p` is unchanged.

- **A PHPStan run that found nothing is no longer reported as findings**
  ([#82](https://github.com/LongTermSupport/php-qa-ci/issues/82)). PHPStan exits 1 for findings,
  but also when it gives up on internal errors ("Result is incomplete because of severe errors"),
  dropping every real finding, and when it stops before analysing anything, on a config error, a
  missing bootstrap file or a rule class it cannot load. The dead-code lane reported all of these
  as "dead code found", with advice to delete members it never named. The PHPStan lane reported
  them as errors found in every mode, without the `--debug` re-run that a crash gets in text mode.
  An exit 1 now counts as findings only when PHPStan reports some, and anything else is a crash.
  The text-mode and dead-code runs pass `--error-format=table`, so a project's `errorFormat` no
  longer changes what they print. `vendor/bin/phpstan-rule` answered "did not fire" for an
  abandoned analysis, including one whose parallel worker died; it now exits 2.

- **Diff-mode Infection mutates renamed and copied source files, under their new path,** where it used to drop them, and reads file names verbatim (`git diff -z`). It now logs at `--log-verbosity=all`, as a full run already did, so `log.txt` lists every escaped mutant. A scope whose changed files hold no mutable code at all (an interface, constants) now passes instead of scoring 0%.

## 85.5.0 — 2026-10-08

### Changed

- **Bundled tool versions updated** by the dependency update: phpstan 2.2.16 → 2.3.0.

### Fixed

- **A `composer update` no longer installs PHPStan extensions the shipped phar cannot load**
  ([#60](https://github.com/LongTermSupport/php-qa-ci/issues/60)). `composer.json` replaced
  `phpstan/phpstan` with `*`, so Composer treated every PHPStan version as present and installed
  the newest extensions: `phpstan/phpstan-strict-rules` 2.1.0 and `phpstan/phpstan-phpunit` 2.1.x
  require PHPStan ^2.3, and the `phpstan` lane then aborted with "Running PHPStan with incompatible
  extensions" against an older phar. The replace is now the phar's exact version (2.3.0), so
  Composer resolves only extension releases the phar can load. `bin/phpstan-replace-sync` verifies
  it on every install and moves it with the phar on a maintainer update.
- **`bin/changelog-release add-tool-updates` records a dependency that moved inside a self-built PHAR**, such as a package Rector bundles, as `<package> (in <tool>.phar)`. Before, only the tool itself was compared, so an update that changed such a PHAR recorded nothing and its pull request failed the `changelog` lane.

## 85.4.0 — 2026-10-07

### Changed — breaking

- **BREAKING**: the `phpArkitect` lane fails, without running arkitect, when the project's own
  `qaConfig/phparkitect.php` builds its class set by hand while a `withIgnoredPaths()` entry is
  under the source directory, since those classes would still be checked. Build the class set
  from the shipped factory instead, `(require getenv('PHPQACI_ARKITECT_CLASS_SET'))($srcDir)`, as
  `templates/qaConfig-phparkitect.php` now does; it applies `Generated` and
  `withArkitectExcludedPaths()` as before.
- **BREAKING**: the `analysedPaths` lane fails a `withIgnoredPaths()` entry that names a path
  where nothing exists, as it already failed a `withUnanalysedPath()` that excuses nothing: the
  exclusion outlived what it excluded, and would hide whatever is added there later from every
  scanning lane. Remove the entry. An ignored directory a build step generates must exist before
  the pipeline runs.
- **BREAKING**: the defect record is removed. `qaConfig/defect-record.neon` is no longer read,
  `vendor/bin/rules` no longer prints a defect-record section or the `defectRecord` JSON key, the
  active-defences region `rules --write-agent-summary` writes has no deferred-defects section, and
  the `phpstanIgnoreJustification` lane checks only the `ignoreErrors` justifications. A project
  that kept a record should fix each entry, or file it as an issue on the upstream project where
  the code is not its own, then delete the file.
- **BREAKING**: `withTypeCoverageFloors()` has no `declare` argument. type-coverage 2.4 stopped
  measuring the share of files declaring `strict_types`, and printed a deprecation on stderr while
  the floor went unenforced. Remove `declare:` from the call; the `phpStrictTypes` lane already
  requires the declaration in every file.

### Added

- A `markdownFormat` lane (`vendor/bin/qa -t mdf`) in the coding-standards phase keeps the
  project's markdown in the form the Claude Code hooks daemon rewrites it to. It runs the daemon's
  own `format-markdown`, so the result matches the daemon byte for byte, and skips where no daemon
  is installed (CI included). A read-only run fails on files the daemon would rewrite; a writable
  run rewrites them. It covers `README.md`, `CLAUDE.md`, `CHANGELOG.md`, `docs/` and `CLAUDE/`, or
  the list given to `withMarkdownFormatPaths()` in `qaConfig/qa.php`; a listed path that is absent
  or gitignored is skipped with a line naming it. See `docs/tools/markdownFormat.md`.
- `vendor/bin/rule-doc` resolves PHPStan's own identifiers, and those of the extensions php-qa-ci
  installs, offline, the way it resolves its own: `rule-doc argument.type` prints PHPStan's page
  for it (code example, why it is reported, how to fix it) rather than a phpstan.org URL. The pages
  are PHPStan's, carried in `vendor-docs/phpstan/` and refreshed with the shipped phpstan.phar by
  `bin/phpstan-docs-install` during a maintainer `composer update`. The type-coverage extension
  publishes no pages, so `docs/phpstan-extension-rules/` holds one for each of its identifiers. An
  identifier none of these carry still names its phpstan.org page.
- Every rule of the bundled PHPArkitect tiers carries an identifier, ending its `because` clause
  (`[phpqaci.interfaceSuffix]`), so a `phpArkitect` violation names the rule that fired.
  `vendor/bin/rule-doc <identifier>` prints its page (`docs/arkitect-rules/`) and
  `vendor/bin/arkitect-rule <identifier> <path>` runs that one rule on one path. A consumer
  matching arkitect output on the old clause text still matches: the identifier is appended.

### Fixed

- The `phpArkitect` lane honours `withIgnoredPaths()`. The shipped class-set factory
  (`configDefaults/generic/phparkitect-class-set.php`, exported as `PHPQACI_ARKITECT_CLASS_SET`)
  drops each ignored path anchored at the source directory, so ignoring `src/Legacy` no longer
  leaves `src/Legacy/` checked, and does not drop `src/Domain/Legacy/` either.
- The `infection` lane honours `withIgnoredPaths()`. When an ignored path lies under one of
  `infection.json`'s source directories, the lane runs Infection with a derived copy of the
  config at `var/qa/infection-config/infection.json`: its paths made absolute and the ignored
  path added to `source.excludes`, anchored at its source directory. Diff mode no longer mutates
  a changed file under an ignored path, and a run whose every source directory is ignored is
  skipped.
- `rules --write-agent-summary` writes the active-defences region in the markdown formatter's
  canonical form: a blank line inside each marker, and a backslash in a summary escaped where it
  is text (a code span keeps it as written). A formatter run over the document, as an editor or
  the hooks daemon does after every edit, no longer rewrites the region and leaves it reported as
  out of date. The `sensitiveParameterUsage` summary puts `#[\SensitiveParameter]` in a code span.

## 85.3.0 — 2026-10-03

### Changed — breaking

- **PHP outside the checked paths fails the run until it is classified.** The
  new always-on `analysedPaths` lane (`-t ap`) lists the project's PHP through
  git and names each directory that is neither under a checked path nor
  declared in `qaConfig/qa.php`; `vendor/` and `var/` are excluded by default.
  Analyse code the project maintains with `withCheckedPaths('config')`, or
  declare the exception with `withUnanalysedPath('<path>', '<reason>')`, whose
  reason is required and printed on every run. See
  [docs/tools/analysedPaths.md](docs/tools/analysedPaths.md).
- **`bin/changelog-release` and the `changelog` lane follow semantic versioning
  unless the project declares otherwise.** A `### Changed — breaking` or
  `### Removed` entry now releases the next major (the next minor while the
  major is 0), and every plain `X.Y.Z` tag counts as a release, so the lane on
  the default branch measures from the newest one. A project that relied on the
  major being its PHP line declares
  `withReleaseVersionPolicy(ReleaseVersionPolicy::lockedMajorFromPhpRequirement())`
  in `qaConfig/qa.php` and keeps today's versions. `pending-tags` prints the tag
  name, prefix included, rather than the version. `ReleaseVersionCalculator` is
  replaced by `ReleaseVersionPolicy` and `ReleaseLine`, `ReleaseBumpEnum` gains
  `Major`, and `ReleasedSections::untagged()` takes a `ReleaseLine`. See
  [docs/tools/changelog.md](docs/tools/changelog.md#versioning-policies).

### Added

- **`vendor/bin/arkitect-rule <because> <path>` proves a PHPArkitect rule
  fires.** It runs the project's own rules (its resolved entry config, tiers and
  environment, no baseline) over one fixture file or directory instead of their
  class sets, and reports whether the rule with that `because` clause fired: exit
  1 with each class, 0 when it did not, 2 when there is no verdict. A rule with
  no instance in `src/` can now be seen to fire before a green arch run is
  trusted. See
  [docs/tools/phpArkitect.md](docs/tools/phpArkitect.md#proving-a-rule-fires).

- **A defect record: `qaConfig/defect-record.neon`.** Method specification
  1.1.0 requires a defect found and not fixed now, and the conclusion that no
  pattern exists, to be recorded where the project's decisions are enumerable.
  Write them under `deferred` (defect, class where apparent, found, deferredBy)
  and `noPattern` (defect, found, conclusion, two or more techniques).
  `vendor/bin/rules` lists the record in text and in JSON (`defectRecord`), the
  active-defences region of `CLAUDE.md` carries it, and the
  `phpstanIgnoreJustification` lane fails on an entry it cannot read, such as a
  misspelt field. A project without the file passes as before; its `CLAUDE.md`
  region gains a line saying where a deferred defect goes. Format:
  `vendor/bin/rule-doc phpqaci.phpstanIgnoreJustification`.

- **Releases follow a versioning policy declared in `qaConfig/qa.php`.**
  `withReleaseVersionPolicy()` takes
  `ReleaseVersionPolicy::semanticVersioning()`, the default, whose first release
  is `0.1.0` unless given another; `lockedMajor(<int>)`, which never moves the
  major; or `lockedMajorFromPhpRequirement()`, the major being composer.json's
  PHP line (`^8.5` is `85`). Each takes a tag prefix such as `v`; a tag of any
  other shape is not a release. `notes` accepts the tag as well as the version.

- **A release workflow ships for consuming projects.**
  `templates/github-actions/release.yml` and
  `templates/github-actions/approve-held-ci/action.yml`, copied unchanged into
  `.github/`, give a project the release pull request php-qa-ci releases itself
  with: a green push to the default branch opens or refreshes
  `chore/release-<branch>`, and merging it publishes the GitHub Release and its
  tag. The branch comes from the repository, the CLI from
  `composer config bin-dir`, the version from the project's release policy.
  Setup and the repository settings it needs are in
  [docs/github-actions.md](docs/github-actions.md#release-automation).

### Changed

- **Bundled tool versions updated** by the weekly dependency update: shipmonk/dead-code-detector 1.4.1 → 1.4.2; phpcpd-next/phpcpd v1.4 → v2.0; rector/rector 2.6.6 → 2.6.7.
- **The Defence Before Fix declaration moves to method specification 1.1.0.**
  `composer.json` `extra.defence-before-fix` states method 1.1.0 at both the
  artefact and the project level, with no new known gap.

### Fixed

- **A diff-scoped Infection run with nothing to mutate no longer generates
  coverage first.** With `infectionDiffBase` set, the lane resolves the
  changed-file list before any coverage run, so a docs-only or config-only
  change skips at once instead of spending a full Xdebug PHPUnit run and then
  reporting nothing to mutate. When there are changed source files, coverage
  is reused or generated exactly as before.
- **The GitHub Actions templates run on Node 24.** `templates/github-actions/`
  pinned `actions/checkout`, `actions/cache`, `actions/upload-artifact` and
  `actions/download-artifact` at v4 and `marocchino/sticky-pull-request-comment`
  at v2, all on the deprecated Node 20 runtime, so every run printed a
  deprecation annotation and the steps stop running once GitHub removes Node 20.
  They are now v7, v6, v7, v8 and v3. `actions/download-artifact@v8` fails on a
  digest mismatch where v4 only warned. A project that copied the templates
  should copy them again.
- **`withIgnoredPaths()` reaches every lane that scans the checked paths.**
  PHPStan, Dead Code Detection, PHP CS Fixer, PHP Strict Types, PHPCPD and
  PSR-4 Validation scanned the ignored paths anyway, so a project following the
  docs had its fixtures' deliberate violations reported and had to repeat each
  path in every tool's own config. PHPStan and Dead Code Detection now exclude
  them from the report (`excludePaths.analyse`, so their classes stay
  discoverable), PHP CS Fixer filters them out of whatever finder the project's
  config uses, and the rest skip them; a `-p` PHPStan run inside an ignored
  path is skipped. An exclusion repeated in a project's own `phpstan.neon`,
  `php_cs_finder.php` or `psr4-validate-ignore-list.txt` can be dropped.
  PHPArkitect and Infection still need their own setting.
- **Rector no longer warns "This skipped rule is never registered" on every
  run.** The shipped `rector-php85.php` skipped
  `NullToStrictStringFuncCallArgRector`, which Rector 2.6.7 registers in no set,
  so the skip did nothing except print a warning naming a file the project does
  not own. The skip is gone; the rule stays out because no set registers it.

## 85.2.0 — 2026-10-03

### Changed — breaking

- **Suppressions reached through `includes:` must be justified too.** The
  `phpstanIgnoreJustification` lane reads `qaConfig/phpstan.neon` and every NEON
  file it includes, so an `ignoreErrors` entry in an included file, including a
  generated baseline, needs the same comment above it. It also fails on an entry
  written inline (`ignoreErrors: [...]`), an include that does not exist or uses
  a `%parameter%` other than `%currentWorkingDirectory%`, and a `.php` include
  that sets `ignoreErrors`. See
  [docs/tools/phpstan.md](docs/tools/phpstan.md#suppressing-errors).

- **The PHPArkitect lane never reads `phparkitect-baseline.json`.** It runs with
  `--skip-baseline`, so violations a baseline listed are reported, and it names
  the file when present. Fix them, or declare the exception with
  `withArkitectExcludedPaths()` in `qaConfig/qa.php`.

- **An inline PHPStan ignore must give its reason.** `rules-default.neon` turns
  on `reportIgnoresWithoutComments`, behind the tier's ban on inline ignores:
  where a project has excluded that rule, an inline ignore must name an
  identifier and give a reason in parentheses.

### Added

- **The `CLAUDE.md` block lists the defences active in the project.** Composer
  install/update writes one line per active defence into the `<phpqaci>` block,
  with its identifier and page, generated from the project's own configuration
  by `vendor/bin/rules --write-agent-summary`; `--agent-summary` prints it.
  Rules without an identifier are gathered into one line per package, so the
  section stays short.

- **The defaults php-qa-ci assumes for what Defence Before Fix leaves to the
  project.** `docs/defence-before-fix-defaults.md` states the Owner, where the
  project record is, the sweep scope, where fixtures go, and the hazard, search
  and class-breadth calibrations a project has not recorded, and the `CLAUDE.md`
  block points an agent at it. Toolchain 6.4 is no longer a known gap in
  `extra.defence-before-fix`.

### Fixed

- **The GitHub Actions templates work with any bin-dir and fetch nothing.**
  `php-qa-ci.yml` and `qa-autofix.yml` run `"$(composer config bin-dir)/qa"`
  instead of `vendor/bin/qa`, so a project that sets `config.bin-dir` needs no
  edit. `php-qa-ci.yml` no longer installs PHIVE and fetches PHARs into
  `vendor/lts/php-qa-ci` (the package ships them) or caches `vendor-phar/`,
  which could restore older PHARs over the installed ones; it triggers on the
  `bugfix/`, `chore/` and `hotfix/` branches the branch policy allows; and
  `AUTO_COMMIT_FIXES: true` now runs the QA step writable, so it has fixes to
  commit. Copy the templates again to pick this up.

- **`bin/rules` no longer hangs on a cyclic include** or fails on PHPStan's own
  `%rootDir%` configuration: it walks `includes:` the way the justification lane
  does, and names an include it cannot follow.

- **`bin/rules` lists the rules `phpstan/extension-installer` delivers.** In a
  consuming project the bundled tiers arrive that way, so the listing showed
  none of them; it now reads the installer's configuration, names the package
  each rule came from, and counts rules registered behind a parameter
  (`conditionalTags`), as `phpstan-strict-rules` registers all of its.

- **Every rule and lane page states the correct construction.** The PHPStan,
  PHPUnit, Infection, SensitiveParameter-usage, branch-name, Twig Lint and Yaml
  Lint pages now say what to write when the lane fails, and the release guard
  fails a page whose fix section is missing or only restates the summary, so
  toolchain 8.1 is no longer a known gap in `extra.defence-before-fix`.

- **The default `phpunit.xml` validates against PHPUnit 13.4.** It set
  `executionOrder="depends,random"`, which PHPUnit 13.4 no longer accepts, so
  every run printed two test runner deprecations and PHPUnit 14 would stop
  running it. It now sets `executionOrder="random"`; dependencies are still
  resolved, which is PHPUnit's default. The schema URL and
  `SYMFONY_PHPUNIT_VERSION` move to 13.4. A project with its own copy of the
  file makes the same change.

## 85.1.0 — 2026-10-03

### Changed — breaking

- **`ext-pcntl` and `ext-posix` are required.** The run handles SIGINT, SIGTERM,
  SIGHUP and SIGQUIT itself and stops its tools' worker processes (see Fixed
  below), which needs both. A host without them is told at `composer install`
  rather than left with runs that cannot clean up after Ctrl-C.

- **An Infection MSI floor of 100 or more is refused, and diff mode no longer
  defaults to 100.** Real code has equivalent mutants no test can kill, so a
  100% floor is only ever met by suppressing mutants or contorting code. The
  configuration now fails to build when `mutationScoreIndicator`,
  `coveredCodeMSI` or `infectionDiffCoveredMsi` (or the matching
  `withInfectionFloors()` / `withInfectionDiffBase()` argument) is 100 or more;
  set 90 to 95 instead. Diff mode, which used to demand 100, now holds changed
  files to the covered-MSI floor unless given its own.

### Added

- **Releases are cut from this changelog.** When CI on a push to `php8.5` is
  green, a release pull request moves `## Unreleased` into a dated
  `85.<minor>.<patch>` section, and is refreshed by every later green push.
  Merging it is the release: CI on the merge publishes a GitHub Release, whose
  tag Packagist reads, with the section as its notes. `bin/changelog-release`
  does the work (`next-version`, `apply`, `notes`, `pending-tags`, `add-entry`,
  `add-tool-updates`) and is available to consuming projects too. See
  [docs/tools/changelog.md](docs/tools/changelog.md).

- **A `changelog` lane (`-t cl`, `phpqaci.changelog`), opt-in.**
  `withChangelogCheck(true)` and `withChangelogWatchedPaths(...)` in
  `qaConfig/qa.php` (or `useChangelogCheck=1`) make the build fail when
  `## Unreleased` is malformed, when a watched path changed without a new entry
  or a `Changelog: none — <reason>` trailer, or when a `composer.json`
  requirement was added or tightened without a `### Changed — breaking` entry.
  It needs the git history (`fetch-depth: 0` in GitHub Actions) and fails
  without it. Off unless enabled, so no consuming project is affected.

- **The hooks daemon keeps the full pipeline with the coordinating session.** Where the
  Claude Code hooks daemon is v3.67.0 or later, composer install/update declares its
  `subagent_full_qa_blocker` in `.claude/hooks-daemon.yaml`: a sub-agent's bare `qa` run
  (or `-p src`, `-p tests`, `-p .`) is denied, single lanes and path-scoped runs are not.
  The edit keeps every comment, leaves a disabled or project-written block alone, and is
  written only after the daemon's `config-validate` accepts it. The deployed guidance
  follows: the coordinating session runs the full pipeline itself, in the background, and
  `php-qa-ci_full-pipeline-runner` now summarises its log instead of running it. See
  [docs/hooks-daemon-full-qa-blocker.md](docs/hooks-daemon-full-qa-blocker.md).

### Changed

- **The GitHub Actions template checks out full history.** The `qa` job in
  `templates/github-actions/php-qa-ci.yml` always uses `fetch-depth: 0`, so
  switching the `changelog` lane on needs no workflow edit. A large repository
  pays a slower checkout.

### Fixed

- **An interrupted run no longer locks out every run after it.** Ctrl-C, `kill`,
  a closed terminal or an agent harness stopping the task used to kill PHP before
  the lock was released, leaving the running tool (and PHPStan's parallel workers)
  orphaned and every following run refused for up to ten minutes. The run now stops
  its running tool and everything that tool started, releases the lock and exits
  128 + the signal number (130 for Ctrl-C, 143 for SIGTERM). The
  lock itself is now an `flock`, which the kernel frees however the holder dies, even
  SIGKILL or the OOM killer, so the ten-minute stale window is gone: a holder that
  is reported is a live one.

- **An interrupted run waits for the workers it kills.** A tool worker that
  ignores SIGTERM is sent SIGKILL after the grace period, and the run now waits
  (up to two seconds) for it to be gone before releasing the lock, so the next
  run cannot start beside a worker that is still exiting.

## 85.0.0 — 2026-10-02

### Changed — breaking

- **Lock contention exits 75, not 1.** A run that cannot take the run lock has
  checked nothing, so it no longer reports the same code as a failing tool. 75 is
  the conventional `EX_TEMPFAIL`, and the run also prints one line to stderr. A
  caller that treated any non-zero exit as "QA failed" now sees contention as its
  own outcome, and should retry rather than report a defect.

### Added

- **`rule-doc` resolves a consuming project's own rule identifiers.** Declare the
  indexes in `qaConfig/rule-docs.json`. The summary comes from the column headed
  `Forbids`, `Summary`, `Description` or `What`, falling back to the trailing
  cell, so a project ending its rows with provenance needs no reshuffling. The
  shipped index is read first, so a project cannot shadow a `phpqaci.*`
  identifier. See [docs/phpstan-rules/README.md](docs/phpstan-rules/README.md).

### Fixed

- **Per-file PHPStan on a phar tool's config file is actionable.** The wrapper
  neon lists each phar's config-API sources under `scanDirectories`, so
  `qaConfig/phparkitect.php` and `qaConfig/composer-dependency-analyser.php` no
  longer report every class in them as missing.

- **Log retention bounds its directory.** A cap on each tool's archives prunes
  oldest-first, replacing a warning that asked the reader to clear the logs by
  hand. Per-pattern retention alone could not bound the directory, because a
  `-p` run mints a pattern per path analysed.

- **The managed `<phpqaci>` CLAUDE.md block always carries a blank line before
  its closing tag**, so a markdown formatter cannot indent the tag and turn every
  subsequent install into a committed diff.
