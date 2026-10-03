<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan;

use InvalidArgumentException;
use LTS\PHPQA\DefectRecord\DefectRecordReader;
use LTS\PHPQA\DefectRecord\Dto\DefectRecordDto;
use LTS\PHPQA\DefectRecord\Dto\DeferredDefectDto;
use LTS\PHPQA\DefectRecord\Dto\NoPatternConclusionDto;
use LTS\PHPQA\PHPStan\Dto\ActiveDefencesListingDto;
use LTS\PHPQA\PHPStan\Dto\ActiveRuleEntryDto;
use LTS\PHPQA\PHPStan\Dto\PipelineLaneDto;
use LTS\PHPQA\PHPStan\Dto\ProjectRecordEntryDto;
use LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\ShippedTools;
use LTS\PHPQA\Pipeline\Tool\ToolGateEnum;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use Nette\Neon\Neon;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;
use Throwable;

/**
 * Lists the PHPStan-driven defences active in a project, its project record
 * (ignoreErrors) and its defect record ({@see DefectRecordReader}: deferred
 * Defects and no-pattern conclusions), WITHOUT running PHPStan. A defect
 * record that cannot be read in full is an error, as an include is. Resolves the project's
 * phpstan.neon the same way the pipeline's ConfigPathResolver does, follows
 * `includes:` through {@see NeonIncludeChain} (the walk the justification lane
 * uses, so the record listed is the record checked; an include that cannot be
 * followed is an error), collects every class under `rules:`
 * and every `phpstan.rules.rule`-tagged service, resolves each rule's
 * identifier via RuleDocResolver where it declares one, and always lists the
 * php-qa-ci pipeline's always-on lanes alongside them.
 *
 * One lane is deliberately not surfaced in the pipeline-lanes listing: phpstan
 * itself, because the rules listing already covers it in far more detail, rule
 * by rule. That is a content decision about a redundant entry, not a curated
 * subset of which lanes count as active defences; every other registered tool
 * is listed (toolchain-spec clause 7.1).
 *
 * @internal
 */
final readonly class ActiveRulesLister
{
    private const string TAG = 'phpstan.rules.rule';

    private const string PIPELINE_LANE_NAME_TO_EXCLUDE = 'phpstan';

    private RuleDocResolver $ruleDocResolver;

    public function __construct(
        private string $qaCiRoot,
        private NeonIncludeChain $includeChain = new NeonIncludeChain(),
        private InstalledPhpstanExtensions $installedExtensions = new InstalledPhpstanExtensions(),
        private DefectRecordReader $defectRecordReader = new DefectRecordReader(),
    ) {
        $this->ruleDocResolver = new RuleDocResolver($this->qaCiRoot);
    }

    public function list(string $projectRoot): ActiveDefencesListingDto
    {
        $configPath = $this->resolveConfigPath($projectRoot);

        $ruleClasses        = [];
        $ignoreErrorEntries = [];
        $seen               = [];
        $this->collectFromNeonTree($configPath, $projectRoot, $ruleClasses, $ignoreErrorEntries, $seen);
        $rules = array_map($this->resolveRuleEntry(...), $ruleClasses);

        foreach ($this->installedExtensions->includes($projectRoot) as $package => $includes) {
            foreach ($includes as $include) {
                $extensionRules = [];
                $this->collectFromNeonTree($include, $projectRoot, $extensionRules, $ignoreErrorEntries, $seen);
                foreach ($extensionRules as $ruleClass) {
                    $entry   = $this->resolveRuleEntry($ruleClass);
                    $rules[] = new ActiveRuleEntryDto($entry->ruleClass, $entry->identifier, $entry->summary, $entry->docPath, $package);
                }
            }
        }

        $lanes = $this->pipelineLanes();

        $defectRecord = $this->defectRecordReader->read($projectRoot);
        if ([] !== $defectRecord->problems) {
            throw new RuntimeException('Cannot read the defect record: ' . implode('; ', $defectRecord->problems));
        }

        return new ActiveDefencesListingDto($configPath, $rules, $lanes, $ignoreErrorEntries, $defectRecord);
    }

    public function renderText(ActiveDefencesListingDto $listing): string
    {
        $out = "Active defences (from {$listing->configPath})\n\n";

        $out .= "PHPStan rules:\n";
        if ([] === $listing->rules) {
            $out .= "  (none)\n";
        }

        foreach ($listing->rules as $rule) {
            $identifier = $rule->identifier ?? 'not declared';
            $summary    = $rule->summary    ?? 'no summary (no IDENTIFIER constant declared)';
            $docRoute   = $rule->docPath    ?? (null === $rule->package
                ? 'no documentation page'
                : \sprintf('documented by %s; its findings carry PHPStan identifiers, which bin/rule-doc routes', $rule->package));
            $out .= \sprintf('  - %s%s', $rule->ruleClass, PHP_EOL);
            if (null !== $rule->package) {
                $out .= \sprintf('      from:       %s (phpstan/extension-installer)%s', $rule->package, PHP_EOL);
            }

            $out .= \sprintf('      identifier: %s%s', $identifier, PHP_EOL);
            $out .= \sprintf('      summary:    %s%s', $summary, PHP_EOL);
            $out .= \sprintf('      doc:        %s%s', $docRoute, PHP_EOL);
        }

        $out .= "\nPipeline lanes (every lane bin/qa registers, minus phpstan — covered above):\n";
        foreach ($listing->pipelineLanes as $lane) {
            $phase = $lane->phase ?? 'no phase recorded';
            $out .= \sprintf('  - %s [%s]: %s%s', $lane->name, $phase, $lane->summary, PHP_EOL);
            // A phase runner is not a defence: it has no identifier and documents
            // nothing of its own, so the reader is not told to go looking for a page.
            if (null !== $lane->identifier) {
                $out .= \sprintf('      identifier: %s%s', $lane->identifier, PHP_EOL);
                $out .= \sprintf('      doc:        %s%s', $lane->docPath ?? 'no documentation page', PHP_EOL);
            }

            if (null !== $lane->optInVariable) {
                $out .= \sprintf('      opt-in:     gated on %s%s', $lane->optInVariable, PHP_EOL);
            }
        }

        $out .= "\nProject record (ignoreErrors):\n";
        if ([] === $listing->projectRecord) {
            $out .= "  (none)\n";
        }

        foreach ($listing->projectRecord as $entry) {
            $out .= '  -';
            if (null !== $entry->identifier) {
                $out .= ' identifier: ' . $entry->identifier;
            }

            if (null !== $entry->path) {
                $out .= ' path: ' . $entry->path;
            }

            if (null !== $entry->count) {
                $out .= ' count: ' . $entry->count;
            }

            if (null !== $entry->message) {
                $out .= ' message: ' . $entry->message;
            }

            if (null !== $entry->raw) {
                $out .= ' raw: ' . $entry->raw;
            }

            $out .= "\n";
            $out .= '      justification: ' . ($entry->justification ?? '(none recorded)') . "\n";
        }

        return $out . $this->renderDefectRecordText($listing->defectRecord);
    }

    public function renderJson(ActiveDefencesListingDto $listing): string
    {
        $rules = array_map(static fn (ActiveRuleEntryDto $rule): array => [
            'ruleClass'  => $rule->ruleClass,
            'identifier' => $rule->identifier,
            'summary'    => $rule->summary,
            'docPath'    => $rule->docPath,
            'package'    => $rule->package,
        ], $listing->rules);

        $lanes = array_map(static fn (PipelineLaneDto $lane): array => [
            'name'          => $lane->name,
            'identifier'    => $lane->identifier,
            'summary'       => $lane->summary,
            'phase'         => $lane->phase,
            'optInVariable' => $lane->optInVariable,
            'docPath'       => $lane->docPath,
        ], $listing->pipelineLanes);

        $projectRecord = array_map(static fn (ProjectRecordEntryDto $entry): array => [
            'identifier'    => $entry->identifier,
            'message'       => $entry->message,
            'path'          => $entry->path,
            'count'         => $entry->count,
            'raw'           => $entry->raw,
            'justification' => $entry->justification,
        ], $listing->projectRecord);

        return \Safe\json_encode([
            'configPath'    => $listing->configPath,
            'rules'         => $rules,
            'pipelineLanes' => $lanes,
            'projectRecord' => $projectRecord,
            'defectRecord'  => [
                'path'      => $listing->defectRecord->path,
                'deferred'  => array_map(static fn (DeferredDefectDto $entry): array => [
                    'defect'     => $entry->defect,
                    'class'      => $entry->class,
                    'found'      => $entry->found,
                    'deferredBy' => $entry->deferredBy,
                ], $listing->defectRecord->deferred),
                'noPattern' => array_map(static fn (NoPatternConclusionDto $entry): array => [
                    'defect'     => $entry->defect,
                    'found'      => $entry->found,
                    'conclusion' => $entry->conclusion,
                    'techniques' => $entry->techniques,
                ], $listing->defectRecord->noPattern),
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    private function renderDefectRecordText(DefectRecordDto $record): string
    {
        $out = \sprintf("\nDefect record (%s%s):\n", DefectRecordReader::RELATIVE_PATH, null === $record->path ? ', not written yet' : '');

        $out .= '  Deferred defects:' . ([] === $record->deferred ? " (none)\n" : "\n");
        foreach ($record->deferred as $entry) {
            $out .= \sprintf("    - defect:      %s\n", $entry->defect);
            $out .= \sprintf("      class:       %s\n", $entry->class ?? 'none apparent yet');
            $out .= \sprintf("      found:       %s\n", $entry->found);
            $out .= \sprintf("      deferred by: %s\n", $entry->deferredBy);
        }

        $out .= '  No-pattern conclusions:' . ([] === $record->noPattern ? " (none)\n" : "\n");
        foreach ($record->noPattern as $entry) {
            $out .= \sprintf("    - defect:     %s\n", $entry->defect);
            $out .= \sprintf("      found:      %s\n", $entry->found);
            $out .= \sprintf("      conclusion: %s\n", $entry->conclusion);
            $out .= \sprintf("      techniques: %s\n", implode('; ', $entry->techniques));
        }

        return $out;
    }

    private function resolveConfigPath(string $projectRoot): string
    {
        $projectPath = $projectRoot . '/qaConfig/phpstan.neon';
        if (is_file($projectPath)) {
            return $projectPath;
        }

        $defaultPath = $this->qaCiRoot . '/configDefaults/generic/phpstan.neon';
        if (is_file($defaultPath)) {
            return $defaultPath;
        }

        throw new InvalidArgumentException(\sprintf(
            'Could not resolve a phpstan.neon for project "%s" — neither %s nor the shipped default %s exists.',
            $projectRoot,
            $projectPath,
            $defaultPath,
        ));
    }

    /**
     * Every pipeline lane, derived from the same ToolRegistry bin/qa resolves
     * tools from (toolchain-spec clause 7.2: never a hand-maintained document
     * that happens to describe the configuration; clause 7.1: every lane bin/qa
     * runs is a listed defence, not a phase-filtered subset). Every registered
     * tool is a lane, in registry order, other than phpstan itself (already
     * covered by the rule listing above — see PIPELINE_LANE_NAME_TO_EXCLUDE).
     *
     * A lane's identifier is the one its shipped ToolInterface prints; a lane
     * with no shipped implementation yet is listed without one rather than
     * guessed.
     *
     * @return list<PipelineLaneDto>
     */
    private function pipelineLanes(): array
    {
        $shipped = ShippedTools::all();

        $lanes = [];
        foreach (ToolRegistry::shipped()->all() as $definition) {
            if (self::PIPELINE_LANE_NAME_TO_EXCLUDE === $definition->name) {
                continue;
            }

            $tool       = $shipped[$definition->name] ?? null;
            $identifier = $tool?->identifier();
            $lanes[]    = new PipelineLaneDto(
                $definition->name,
                $identifier,
                $definition->description,
                $definition->phase,
                $this->optInVariable($definition),
                $this->laneDocPath($identifier),
            );
        }

        return $lanes;
    }

    /**
     * The page a lane's identifier resolves to, or null.
     *
     * Unlike a rule, a lane that does not resolve is not worth a warning on STDERR:
     * a phase runner has no identifier at all, and a lane with an identifier but no
     * page is a gap the listing itself reports, in the row where a reader looking
     * for that page will actually be looking.
     */
    private function laneDocPath(?string $identifier): ?string
    {
        if (null === $identifier) {
            return null;
        }

        return $this->ruleDocResolver->docPathFor($identifier);
    }

    /**
     * A lane's opt-in variable, derived only from what the registry states: the
     * Infection gate is `useInfection`; a description that names a `useXxx`
     * variable (e.g. "useArkitect=0 to disable") is taken at its word. Null,
     * never a guess, otherwise.
     */
    private function optInVariable(ToolDefinitionDto $definition): ?string
    {
        if (ToolGateEnum::Infection === $definition->gate) {
            return 'useInfection';
        }

        if (1 === \Safe\preg_match('/\buse[A-Z]\w*/', $definition->description, $matches) && isset($matches[0])) {
            return $matches[0];
        }

        return null;
    }

    /**
     * @param list<string>                $ruleClasses        accumulator, by reference
     * @param list<ProjectRecordEntryDto> $ignoreErrorEntries accumulator, by reference
     * @param array<string, true>         $seen               files already read, by reference, so a file
     *                                                        reached twice contributes once
     */
    private function collectFromNeonTree(string $neonPath, string $projectRoot, array &$ruleClasses, array &$ignoreErrorEntries, array &$seen): void
    {
        $chain = $this->includeChain->resolve($neonPath, $projectRoot);
        if ([] !== $chain->problems) {
            throw new RuntimeException('Cannot read the PHPStan configuration in full: ' . implode('; ', $chain->problems));
        }

        foreach ($chain->files as $file) {
            if (isset($seen[$file->path])) {
                continue;
            }

            $seen[$file->path] = true;
            $decoded           = Neon::decode($file->neon);
            if (\is_array($decoded)) {
                $this->collectFromNeon($decoded, $file->neon, $ruleClasses, $ignoreErrorEntries);
            }
        }
    }

    /**
     * @param array<array-key, mixed>     $decoded
     * @param list<string>                $ruleClasses        accumulator, by reference
     * @param list<ProjectRecordEntryDto> $ignoreErrorEntries accumulator, by reference
     */
    private function collectFromNeon(array $decoded, string $raw, array &$ruleClasses, array &$ignoreErrorEntries): void
    {
        foreach ((array)($decoded['rules'] ?? []) as $ruleClass) {
            if (\is_string($ruleClass)) {
                $ruleClasses[] = $ruleClass;
            }
        }

        foreach ((array)($decoded['services'] ?? []) as $service) {
            if (!\is_array($service)) {
                continue;
            }

            $tags = (array)($service['tags'] ?? []);
            if (\in_array(self::TAG, $tags, true) && \is_string($service['class'] ?? null)) {
                $ruleClasses[] = $service['class'];
            }
        }

        // A rule tagged behind a parameter (phpstan-strict-rules registers every rule
        // this way) is listed as active: the condition is not evaluated, because that
        // needs PHPStan's merged parameters, and such switches default to on.
        foreach ((array)($decoded['conditionalTags'] ?? []) as $ruleClass => $tags) {
            if (\is_string($ruleClass) && \is_array($tags) && \array_key_exists(self::TAG, $tags)) {
                $ruleClasses[] = $ruleClass;
            }
        }

        $parameters      = (array)($decoded['parameters'] ?? []);
        $ignoreErrorsRaw = (array)($parameters['ignoreErrors'] ?? []);
        foreach ($ignoreErrorsRaw as $ignoreError) {
            $ignoreErrorEntries[] = $this->parseIgnoreError($ignoreError, $raw);
        }
    }

    private function parseIgnoreError(mixed $entry, string $rawNeonText): ProjectRecordEntryDto
    {
        if (\is_string($entry)) {
            return new ProjectRecordEntryDto(
                identifier: null,
                message: null,
                path: null,
                count: null,
                raw: $entry,
                justification: $this->justificationAbove($entry, $rawNeonText),
            );
        }

        $entryArray = \is_array($entry) ? $entry : [];

        $identifier = \is_string($entryArray['identifier'] ?? null) ? $entryArray['identifier'] : null;
        $message    = \is_string($entryArray['message'] ?? null) ? $entryArray['message'] : null;
        $path       = \is_string($entryArray['path'] ?? null) ? $entryArray['path'] : null;
        $count      = \is_int($entryArray['count'] ?? null) ? $entryArray['count'] : null;

        $anchor = $identifier ?? $message ?? $path;

        return new ProjectRecordEntryDto(
            identifier: $identifier,
            message: $message,
            path: $path,
            count: $count,
            raw: null,
            justification: null === $anchor ? null : $this->justificationAbove($anchor, $rawNeonText),
        );
    }

    /**
     * Recovers a `#` comment sitting directly above the line that contains
     * $needle in the raw neon text — the neon parser strips comments, so this
     * is read from the source text separately.
     */
    private function justificationAbove(string $needle, string $rawNeonText): ?string
    {
        if ('' === $needle) {
            return null;
        }

        $lines = explode("\n", $rawNeonText);
        foreach ($lines as $index => $line) {
            if (!str_contains($line, $needle)) {
                continue;
            }

            for ($cursor = $index - 1; $cursor >= 0; --$cursor) {
                $candidate = trim($lines[$cursor]);
                if ('' === $candidate) {
                    continue;
                }

                if (str_starts_with($candidate, '#')) {
                    return trim(ltrim($candidate, '# '));
                }

                if ('-' === $candidate) {
                    // A bare list-item marker line; the comment (if any) sits
                    // above it, not above this entry's first property line.
                    continue;
                }

                break;
            }

            break;
        }

        return null;
    }

    private function resolveRuleEntry(string $ruleClass): ActiveRuleEntryDto
    {
        $identifier = $this->identifierConstantOf($ruleClass);
        if (null === $identifier) {
            return new ActiveRuleEntryDto($ruleClass, null, null, null);
        }

        // An extension's own identifier is documented by that extension, not
        // indexed here, so its absence from the index is expected rather than a gap.
        if (!str_starts_with($identifier, RuleIdentifierInterface::PREFIX . '.')) {
            return new ActiveRuleEntryDto($ruleClass, $identifier, null, null);
        }

        try {
            $entry = $this->ruleDocResolver->resolve($identifier);
        } catch (InvalidArgumentException $invalidArgumentException) {
            \Safe\fwrite(\STDERR, \sprintf('ActiveRulesLister: %s declares identifier "%s" but it did not ', $ruleClass, $identifier)
                . \sprintf('resolve against the doc index: %s%s', $invalidArgumentException->getMessage(), PHP_EOL));

            return new ActiveRuleEntryDto($ruleClass, $identifier, null, null);
        }

        return new ActiveRuleEntryDto($ruleClass, $identifier, $entry->summary, $entry->docPath);
    }

    private function resolvedClassName(Node $classNode): ?string
    {
        if (!$classNode instanceof Node\Name) {
            return null;
        }

        if ('self' === $classNode->toString() || 'static' === $classNode->toString()) {
            return $classNode->toString();
        }

        $resolved = $classNode->getAttribute('resolvedName');

        return $resolved instanceof Node\Name ? $resolved->toString() : $classNode->toString();
    }

    /**
     * Reads the IDENTIFIER class constant WITHOUT loading the rule class —
     * loading it would require the PHPStan API (PHPStan\Rules\Rule etc), which
     * is only available inside the vendored phpstan.phar, not on the ordinary
     * autoload path this lister runs on. The file is located via the Composer
     * autoloader's PSR-4 resolution (findFile — does not execute the file) and
     * parsed with nikic/php-parser to read the constant's initializer.
     *
     * Only a string literal, a `Foo::BAR` fetch of another class's own string
     * constant (resolved the same file-parsing way, one level deep — this is
     * how every bundled rule expresses RuleIdentifierInterface::PREFIX), and
     * `.`-concatenations of those are understood. Anything more dynamic
     * resolves to null (rule listed, identifier "not declared") rather than
     * guessed.
     */
    private function identifierConstantOf(string $ruleClass): ?string
    {
        $file = $this->classFilePath($ruleClass);
        if (null === $file) {
            return null;
        }

        return $this->constantValueFromFile($file, $ruleClass, 'IDENTIFIER');
    }

    private function classFilePath(string $class): ?string
    {
        foreach (spl_autoload_functions() as $autoloader) {
            if (\is_array($autoloader) && $autoloader[0] instanceof \Composer\Autoload\ClassLoader) {
                $path = $autoloader[0]->findFile($class);
                if (\is_string($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function constantValueFromFile(string $file, string $class, string $constantName): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $parser = new ParserFactory()->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse(\Safe\file_get_contents($file));
        } catch (Throwable $throwable) {
            \Safe\fwrite(\STDERR, \sprintf('ActiveRulesLister: could not parse %s while resolving %s::%s: %s%s', $file, $class, $constantName, $throwable->getMessage(), PHP_EOL));

            return null;
        }

        if (null === $ast) {
            return null;
        }

        // Resolves `use`-imported short class names (e.g. RuleIdentifierInterface)
        // to fully-qualified ones, so a ClassConstFetch on one can be located via
        // the autoloader in evaluateConstExpr().
        new NodeTraverser(new NameResolver())->traverse($ast);

        $finder = new NodeFinder();
        /** @var Node\Stmt\ClassConst|null $constNode */
        $constNode = $finder->findFirst($ast, static function (Node $node) use ($constantName): bool {
            if (!$node instanceof Node\Stmt\ClassConst) {
                return false;
            }

            return array_any($node->consts, static fn (Node\Const_ $const): bool => $const->name->toString() === $constantName);
        });

        if (null === $constNode) {
            return null;
        }

        foreach ($constNode->consts as $const) {
            if ($const->name->toString() === $constantName) {
                return $this->evaluateConstExpr($const->value, \dirname($file), $class);
            }
        }

        return null;
    }

    private function evaluateConstExpr(Node\Expr $expr, string $dir, string $class): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left  = $this->evaluateConstExpr($expr->left, $dir, $class);
            $right = $this->evaluateConstExpr($expr->right, $dir, $class);

            return (null === $left || null === $right) ? null : $left . $right;
        }

        if ($expr instanceof Node\Expr\ClassConstFetch && $expr->name instanceof Node\Identifier) {
            $targetClassName = $this->resolvedClassName($expr->class);
            if (null === $targetClassName) {
                return null;
            }

            $targetClass = ('self' === $targetClassName || 'static' === $targetClassName) ? $class : $targetClassName;
            $targetFile  = $this->classFilePath($targetClass);
            if (null === $targetFile) {
                return null;
            }

            return $this->constantValueFromFile($targetFile, $targetClass, $expr->name->toString());
        }

        return null;
    }
}
