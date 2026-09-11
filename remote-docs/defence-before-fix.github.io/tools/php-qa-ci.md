---
source_url: https://defence-before-fix.github.io/tools/php-qa-ci.html
fetched_at: 2026-09-11T16:17:31.404117+00:00
fidelity: verbatim
source_sha256: c3992f73a420994f77f56f19f74acadc339a400c8c13dd04e7e2a366ce1589b0
licence: CC-BY-4.0
stale_after: 2026-12-10
fetch_method: https-get
---

<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <!-- Begin Jekyll SEO tag v2.8.0 -->
<title>php-qa-ci and Defence Before Fix | Defence Before Fix (DBF)</title>
<meta name="generator" content="Jekyll v3.10.0" />
<meta property="og:title" content="php-qa-ci and Defence Before Fix" />
<meta name="author" content="Joseph Edmonds" />
<meta property="og:locale" content="en_GB" />
<meta name="description" content="A phase that runs before a defect is fixed. The method, detector and toolchain specifications." />
<meta property="og:description" content="A phase that runs before a defect is fixed. The method, detector and toolchain specifications." />
<link rel="canonical" href="https://defence-before-fix.github.io/tools/php-qa-ci.html" />
<meta property="og:url" content="https://defence-before-fix.github.io/tools/php-qa-ci.html" />
<meta property="og:site_name" content="Defence Before Fix (DBF)" />
<meta property="og:type" content="website" />
<meta name="twitter:card" content="summary" />
<meta property="twitter:title" content="php-qa-ci and Defence Before Fix" />
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"WebPage","author":{"@type":"Person","name":"Joseph Edmonds"},"description":"A phase that runs before a defect is fixed. The method, detector and toolchain specifications.","headline":"php-qa-ci and Defence Before Fix","url":"https://defence-before-fix.github.io/tools/php-qa-ci.html"}</script>
<!-- End Jekyll SEO tag -->

  
<link rel="canonical" href="https://defence-before-fix.github.io/tools/php-qa-ci.html">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "DefinedTerm",
  "name": "Defence Before Fix",
  "alternateName": ["Defense Before Fix", "DBF"],
  "description": "A phase that runs before a defect is fixed: the instance is treated as evidence of a class, and the defence that detects the class is built and seen to fire before the fix is made.",
  "url": "https://defence-before-fix.github.io/",
  "inDefinedTermSet": {
    "@type": "DefinedTermSet",
    "name": "Defence Before Fix specification",
    "url": "https://defence-before-fix.github.io/SPEC.html"
  },
  "author": {
    "@type": "Person",
    "name": "Joseph Edmonds",
    "url": "https://ltscommerce.dev",
    "worksFor": {
      "@type": "Organization",
      "name": "Edmonds Commerce",
      "url": "https://edmondscommerce.co.uk"
    }
  },
  "datePublished": "2026-02-22",
  "citation": "https://ltscommerce.dev/articles/defence-before-fix-static-analysis"
}
</script>

  <link rel="stylesheet" href="/assets/css/site.css">
  <link rel="alternate" type="text/plain" href="/llms.txt" title="llms.txt">
</head>
<body>
  
<header class="site-header">
  <p class="site-title"><a href="/">Defence Before Fix (DBF)</a></p>
  <nav aria-label="Site">
    <a href="/SPEC.html">Method specification</a>
    <a href="/DETECTOR-SPEC.html">Detector specification</a>
    <a href="/TOOLING-SPEC.html">Toolchain specification</a>
    <a href="/PRIMER.html">Primer</a>
    <a href="/tools/">Tools</a>
    <a href="/PROVENANCE.html">Provenance</a>
    <a href="/CHANGELOG.html">Changelog</a>
    <a href="/defence-before-fix-project-prompt.md">Agents</a>
    
  </nav>
  <p class="site-byline agent-line">Agents: read <a href="/defence-before-fix-project-prompt.md">the project prompt</a>
    (raw markdown), or start from <a href="/llms.txt">llms.txt</a>.</p>
  <p class="site-byline">By <a href="https://ltscommerce.dev">Joseph Edmonds</a> of
    <a href="https://edmondscommerce.co.uk">Edmonds Commerce</a>. First published 22 February 2026.</p>
</header>

  <main>
    <h1 id="php-qa-ci">php-qa-ci</h1>

<table class="tool-grades">
  <tbody>
    <tr><th scope="row">Language</th><td>PHP</td></tr>
    <tr><th scope="row">Kind</th><td>toolchain</td></tr>
    <tr><th scope="row"><a href="/tools/#grades">Readiness</a></th><td>🟢</td></tr>
    <tr><th scope="row"><a href="/DETECTOR-SPEC.html#8-conformance">Detector conformance</a></th><td>🟡</td></tr>
    
    <tr><th scope="row"><a href="/TOOLING-SPEC.html#9-conformance">Toolchain conformance</a></th><td>🟡</td></tr>
    <tr><th scope="row"><a href="/TOOLING-SPEC.html#91-a-project-that-ships-a-detector-or-a-toolchain-has-two-levels-of-conformance-graded-separately">Project conformance</a></th><td>🟡</td></tr>
    
    <tr><th scope="row">Checked</th><td>2026-09-08, branch php8.4 at commit e25aba4, declaration merged at 4d9b2ba</td></tr>
  </tbody>
</table>

<p>php-qa-ci is a Composer plugin that wraps PHPStan, PHPArkitect, PHPUnit, PHP CS Fixer, Rector, Infection and a set of its own lanes behind one <code class="language-plaintext highlighter-rouge">bin/qa</code> entry point, ships a bundle of PHPStan rules with their documentation, and writes an agent-facing block into the consuming project. It is the PHP reference toolchain for this method, and this page grades it with the same scrutiny as every other entry (<a href="https://github.com/LongTermSupport/php-qa-ci">repository</a>).</p>

<p>Every mechanism below was checked by running it in a checkout: <code class="language-plaintext highlighter-rouge">bin/qa</code>, <code class="language-plaintext highlighter-rouge">bin/qa -t &lt;tool&gt; -p &lt;path&gt;</code>, <code class="language-plaintext highlighter-rouge">bin/rules</code>, <code class="language-plaintext highlighter-rouge">bin/rule-doc &lt;identifier&gt;</code> and <code class="language-plaintext highlighter-rouge">bin/phpstan-rule &lt;identifier&gt; &lt;path&gt;</code>. Detector conformance is graded on the detectors the toolchain routes defences through, each together with the wrapping around it, as toolchain clause <a href="/TOOLING-SPEC.html#41-every-detector-the-toolchain-routes-a-defence-through-must-conform-to-the-detector-specification">4.1</a> requires. Toolchain conformance grades the artefact a consuming project installs. Project conformance grades the repository as a project following the method with the toolchain it ships.</p>

<h2 id="how-it-is-conformant">How it is conformant</h2>

<p>For its own PHPStan bundle, every detector clause holds. A project’s rules go under <code class="language-plaintext highlighter-rouge">rules:</code> in its <code class="language-plaintext highlighter-rouge">qaConfig/phpstan.neon</code> (detector <a href="/DETECTOR-SPEC.html#41-the-detector-must-support-bespoke-rules-written-by-the-project-that-runs-it">4.1</a>). <code class="language-plaintext highlighter-rouge">bin/phpstan-rule phpqaci.nullCoalescingFalse src/ZzProbe.php</code> on a probe containing <code class="language-plaintext highlighter-rouge">?? false</code> printed <code class="language-plaintext highlighter-rouge">FIRED (1)</code> with the location and exited 1, and the same command for <code class="language-plaintext highlighter-rouge">phpqaci.nestedTernary</code> printed <code class="language-plaintext highlighter-rouge">did not fire</code> (<a href="/DETECTOR-SPEC.html#42-the-detector-must-provide-a-harness-that-runs-a-single-rule-against-supplied-code">4.2</a>). PHPStan prints the <code class="language-plaintext highlighter-rouge">phpqaci.*</code> identifier under every finding, and <code class="language-plaintext highlighter-rouge">RequireRuleIdentifierConstantRule</code>, on by default, rejects a magic-string identifier (<a href="/DETECTOR-SPEC.html#43-the-detector-must-allow-a-rule-to-carry-a-stable-identifier-and-must-print-it-with-every-finding">4.3</a>, <a href="/DETECTOR-SPEC.html#44-the-detector-should-enforce-43-with-a-rule-of-its-own">4.4</a>). <code class="language-plaintext highlighter-rouge">bin/qa -t phpstan -p &lt;file&gt;</code> runs one lane over one file locally with the result in the terminal, and the CI script runs the same <code class="language-plaintext highlighter-rouge">bin/qa</code> (<a href="/DETECTOR-SPEC.html#51-the-detector-must-be-invocable-by-the-practitioner-locally-with-no-infrastructure">5.1</a> to <a href="/DETECTOR-SPEC.html#54-a-finding-must-not-be-reportable-only-through-a-hosted-service-licence-tier-or-ci-only-mode-the-practitioner-cannot-invoke-locally">5.4</a>). <code class="language-plaintext highlighter-rouge">bin/rule-doc phpqaci.nullCoalescingFalse</code> resolves the identifier as printed to its rule, bundle, source and the shipped page in <code class="language-plaintext highlighter-rouge">docs/phpstan-rules/</code>, offline (<a href="/DETECTOR-SPEC.html#61-the-detector-must-provide-a-mechanism-that-resolves-a-printed-identifier-to-its-documentation">6.1</a> to <a href="/DETECTOR-SPEC.html#63-a-bundled-rules-documentation-must-ship-with-the-rule-at-a-version-tracked-together">6.3</a> for the bundle). Inline <code class="language-plaintext highlighter-rouge">@phpstan-ignore</code> in every form is a finding of <code class="language-plaintext highlighter-rouge">ForbidInlinePhpstanIgnoreRule</code> (<a href="/DETECTOR-SPEC.html#71-a-detector-may-offer-an-inline-suppression-route-but-must-make-it-detectable-or-disableable">7.1</a>).</p>

<p>At toolchain level, <code class="language-plaintext highlighter-rouge">bin/rules .</code> lists every rule in the resolved neon chain with identifier, summary and documentation route, derived from the configuration and the lane registry rather than hand-maintained, and the package’s contributor-only rules appear in its own listing (<a href="/TOOLING-SPEC.html#52-the-listing-must-be-derived-from-the-active-configuration">5.2</a>, <a href="/TOOLING-SPEC.html#53-a-projects-own-defences-must-appear-in-the-listing-alongside-bundled-ones">5.3</a>). The record is the <code class="language-plaintext highlighter-rouge">ignoreErrors</code> block PHPStan itself loads (<a href="/TOOLING-SPEC.html#61-the-toolchain-must-define-a-location-for-the-project-record-and-must-read-it-itself">6.1</a>); the <code class="language-plaintext highlighter-rouge">pij</code> lane rejects an entry with no comment, a comment matching a paste-anywhere list such as <code class="language-plaintext highlighter-rouge">legacy</code> or <code class="language-plaintext highlighter-rouge">needed for now</code>, or one too short to name a hazard and a scope (<a href="/TOOLING-SPEC.html#62-every-exception-in-the-project-record-must-carry-a-written-justification-that-names-the-hazard-and-the-scope-and-the-toolchain-must-reject-a-generic-one">6.2</a> in part); and the same listing enumerates the record beside the defences, including an entry from an included baseline (<a href="/TOOLING-SPEC.html#63-the-project-record-must-be-enumerable-by-the-same-means-as-the-defences">6.3</a>). The entry point runs coding standards, linting and static analysis before tests and stops on a failure (<a href="/TOOLING-SPEC.html#45-the-toolchains-entry-point-must-run-detectors-before-runners-and-must-stop-on-a-detector-failure">4.5</a>). The self-check configuration includes both bundled rule sets, guarded by a test, and the PHPArkitect tier runs on the package’s own source (<a href="/TOOLING-SPEC.html#82-a-shipped-toolchain-must-run-its-own-bundled-defences-on-its-own-source">8.2</a>). The agent block is written and refreshed on every install (<a href="/TOOLING-SPEC.html#72-the-toolchain-should-deliver-that-summary-into-the-project-automatically">7.2</a>). The manifest declares method 1.0.0 and toolchain 0.2.0 at both levels, with the gaps below recorded against their clauses (<a href="/TOOLING-SPEC.html#92-the-declaration-is-the-claim-and-the-known-gap-record-not-a-condition-of-conformance">9.2</a>).</p>

<h2 id="how-it-is-not-conformant">How it is not conformant</h2>

<p>Toolchain clause <a href="/TOOLING-SPEC.html#41-every-detector-the-toolchain-routes-a-defence-through-must-conform-to-the-detector-specification">4.1</a> fails three ways. PHPArkitect, through which the on-by-default rule tier routes bundled defences, prints a violation as prose (<code class="language-plaintext highlighter-rouge">should have a name that matches *Controller because controllers must be named consistently</code>) with no identifier, has no single-rule or single-file invocation through the lane, and has no resolver for its tier, so as wrapped it fails detector <a href="/DETECTOR-SPEC.html#43-the-detector-must-allow-a-rule-to-carry-a-stable-identifier-and-must-print-it-with-every-finding">4.3</a>, <a href="/DETECTOR-SPEC.html#52-the-detector-must-support-invocation-over-a-subset-at-minimum-a-single-file">5.2</a> and <a href="/DETECTOR-SPEC.html#61-the-detector-must-provide-a-mechanism-that-resolves-a-printed-identifier-to-its-documentation">6.1</a> to <a href="/DETECTOR-SPEC.html#63-a-bundled-rules-documentation-must-ship-with-the-rule-at-a-version-tracked-together">6.3</a>. Five of the toolchain’s own lanes, <code class="language-plaintext highlighter-rouge">packageType</code>, <code class="language-plaintext highlighter-rouge">phpstanIgnoreJustification</code>, <code class="language-plaintext highlighter-rouge">sensitiveParameterUsage</code>, <code class="language-plaintext highlighter-rouge">branchNamePolicy</code> and <code class="language-plaintext highlighter-rouge">phpStrictTypes</code>, print no identifier on failure. And PHPStan’s native identifiers resolve online only: <code class="language-plaintext highlighter-rouge">bin/rule-doc method.notFound</code> answers <code class="language-plaintext highlighter-rouge">Unknown rule identifier</code>, so the wrapping does not close detector <a href="/DETECTOR-SPEC.html#62-resolution-of-a-bundled-rules-identifier-must-work-from-the-installed-copy-without-network-access">6.2</a> and <a href="/DETECTOR-SPEC.html#63-a-bundled-rules-documentation-must-ship-with-the-rule-at-a-version-tracked-together">6.3</a> for the detector’s own catalogue.</p>

<p>Clause <a href="/TOOLING-SPEC.html#42-the-toolchain-must-resolve-every-identifier-a-defence-it-routes-can-print-from-the-installed-copy-without-network-access">4.2</a> is partial: fifteen of the twenty-four bundled PHPStan rules resolve to an index row and no page, so <code class="language-plaintext highlighter-rouge">bin/rule-doc phpqaci.nestedTernary</code> ends at <code class="language-plaintext highlighter-rouge">Summary: No nested ternary expressions</code> and states no correct construction. Clause <a href="/TOOLING-SPEC.html#43-the-toolchain-must-forbid-through-a-defence-of-its-own-every-suppression-route-that-bypasses-the-project-record">4.3</a> is partial: a <code class="language-plaintext highlighter-rouge">phparkitect-baseline.json</code> generated once is read silently on every later run of the lane, which printed <code class="language-plaintext highlighter-rouge">Baseline file found</code> and <code class="language-plaintext highlighter-rouge">No violations detected</code> on a fixture with one violation, and a PHPStan baseline included from <code class="language-plaintext highlighter-rouge">qaConfig/phpstan.neon</code> is listed by <code class="language-plaintext highlighter-rouge">bin/rules</code> but not checked by the justification lane, which reads the top-level file alone (also <a href="/TOOLING-SPEC.html#62-every-exception-in-the-project-record-must-carry-a-written-justification-that-names-the-hazard-and-the-scope-and-the-toolchain-must-reject-a-generic-one">6.2</a>). Clause <a href="/TOOLING-SPEC.html#51-the-toolchain-must-be-able-to-list-the-defences-active-in-a-project-without-triggering-them">5.1</a> is partial: lanes are listed without an identifier or a documentation route, even the two that print one. Clause <a href="/TOOLING-SPEC.html#81-a-shipped-toolchain-must-fail-its-own-release-if-a-bundled-defence-lacks-resolvable-documentation">8.1</a> is partial: the release guard requires an index row rather than a page, and a lane with no identifier is outside it. Clause <a href="/TOOLING-SPEC.html#71-the-toolchain-should-generate-a-summary-of-the-active-defences-suitable-for-an-agents-context">7.1</a> is not met: the agent block carries a pointer and no rule lines, so the toolchain does not conform with agent support.</p>

<p>Project conformance carries the same gaps, because the repository runs the toolchain it ships. Its own record is one justified entry, enumerable in the listing; its contributor-only rules are listed and run; the fifteen rules without a page are among the ones that run on it.</p>

<h2 id="tools-it-bundles">Tools it bundles</h2>

<p>php-qa-ci is a toolchain rather than a tool, so the question for each lane it runs is whether that lane can host a bespoke defence under the method, and which of the toolchain’s own mechanisms wrap it. Every lane below is registered by <code class="language-plaintext highlighter-rouge">bin/qa</code> and appears in the <code class="language-plaintext highlighter-rouge">bin/rules</code> listing; <code class="language-plaintext highlighter-rouge">bin/phpstan-rule</code> and <code class="language-plaintext highlighter-rouge">bin/rule-doc</code> wrap PHPStan and the two lanes that print an identifier.</p>

<ul>
  <li><strong>PHPStan</strong>: hosts bespoke defences; wrapped by <code class="language-plaintext highlighter-rouge">bin/phpstan-rule</code>, <code class="language-plaintext highlighter-rouge">bin/rule-doc</code> and <code class="language-plaintext highlighter-rouge">bin/rules</code>, with the bundled rules and the identifier constant enforced on top. Conforms as wrapped for the <code class="language-plaintext highlighter-rouge">phpqaci.*</code> bundle; fails detector <a href="/DETECTOR-SPEC.html#62-resolution-of-a-bundled-rules-identifier-must-work-from-the-installed-copy-without-network-access">6.2</a> and <a href="/DETECTOR-SPEC.html#63-a-bundled-rules-documentation-must-ship-with-the-rule-at-a-version-tracked-together">6.3</a> for its native catalogue.</li>
  <li><strong>PHPArkitect</strong>: hosts bespoke defences about architecture through <code class="language-plaintext highlighter-rouge">phparkitect.php</code>; wrapped only as a lane, with no identifier, no single-file run and no resolver. Does not conform as wrapped, and the default tier routes bundled defences through it.</li>
  <li><strong>Rector</strong>: a refactoring tool run in dry-run mode; a project can register a custom rule, but the toolchain runs it as a check and routes no defence through it.</li>
  <li><strong>PHP CS Fixer</strong>: a formatter; cannot host a defence in the method’s sense, and is run as a check.</li>
  <li><strong>PSR-4 validation</strong>: a fixed check of autoload mapping; not a host.</li>
  <li><strong>Composer validation and composer require checker</strong>: fixed checks of the manifest and of undeclared dependencies; not hosts.</li>
  <li><strong>Package type</strong>: a bundled defence requiring an explicit <code class="language-plaintext highlighter-rouge">type</code> in <code class="language-plaintext highlighter-rouge">composer.json</code>; prints no identifier, documented in <code class="language-plaintext highlighter-rouge">docs/tools/</code>.</li>
  <li><strong>Config template ignore-list audit</strong>: a bundled defence over the toolchain’s own templates; prints <code class="language-plaintext highlighter-rouge">phpqaci.configTemplateIgnoreList</code>, resolved by <code class="language-plaintext highlighter-rouge">bin/rule-doc</code>.</li>
  <li><strong>Infection config source dirs check</strong>: a bundled defence that <code class="language-plaintext highlighter-rouge">infection.json</code> names real directories; prints <code class="language-plaintext highlighter-rouge">phpqaci.infectionConfigSourceDirectoriesMustExist</code>, resolved by <code class="language-plaintext highlighter-rouge">bin/rule-doc</code>.</li>
  <li><strong>Strict types</strong>: a bundled defence that every file declares strict types; prints no identifier.</li>
  <li><strong>PHP lint</strong>: a syntax check; not a host.</li>
  <li><strong>Markdown links</strong>: a fixed link check; not a host.</li>
  <li><strong>Branch name policy</strong>: a bundled defence over branch naming; prints no identifier.</li>
  <li><strong>PHPStan ignoreErrors justification</strong>: the clause <a href="/TOOLING-SPEC.html#62-every-exception-in-the-project-record-must-carry-a-written-justification-that-names-the-hazard-and-the-scope-and-the-toolchain-must-reject-a-generic-one">6.2</a> mechanism, a bundled defence over the project record; prints no identifier and reads the top-level record file alone.</li>
  <li><strong>SensitiveParameter usage</strong>: a bundled defence requiring the attribute somewhere in <code class="language-plaintext highlighter-rouge">src/</code>; prints no identifier, documented in <code class="language-plaintext highlighter-rouge">docs/tools/</code>.</li>
  <li><strong>PHPUnit</strong>: a test runner; cannot host a defence, since the method rules out a test as the net.</li>
  <li><strong>Infection</strong>: a mutation tester; cannot host a defence, with its configuration guarded by the source dirs check above.</li>
</ul>

<h2 id="clause-by-clause">Clause by clause</h2>

<p>Detector rows grade PHPStan as wrapped, with PHPArkitect as wrapped noted where it differs.</p>

<table>
  <thead>
    <tr>
      <th>Document</th>
      <th>Clause</th>
      <th>Result</th>
      <th>Evidence</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#41-the-detector-must-support-bespoke-rules-written-by-the-project-that-runs-it">4.1</a></td>
      <td>Yes</td>
      <td>Project rules under <code class="language-plaintext highlighter-rouge">rules:</code> in <code class="language-plaintext highlighter-rouge">qaConfig/phpstan.neon</code>; PHPArkitect project rules in <code class="language-plaintext highlighter-rouge">phparkitect.php</code></td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#42-the-detector-must-provide-a-harness-that-runs-a-single-rule-against-supplied-code">4.2</a></td>
      <td>Yes</td>
      <td><code class="language-plaintext highlighter-rouge">bin/phpstan-rule &lt;identifier&gt; &lt;path&gt;</code> fired red on a probe and stayed silent on another rule; PHPArkitect as wrapped has none</td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#43-the-detector-must-allow-a-rule-to-carry-a-stable-identifier-and-must-print-it-with-every-finding">4.3</a></td>
      <td>Yes</td>
      <td><code class="language-plaintext highlighter-rouge">🪪  phpqaci.nullCoalescingFalse</code> printed under the finding, identifier in JSON mode too; PHPArkitect prints prose only</td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#44-the-detector-should-enforce-43-with-a-rule-of-its-own">4.4</a></td>
      <td>Yes</td>
      <td><code class="language-plaintext highlighter-rouge">RequireRuleIdentifierConstantRule</code> in <code class="language-plaintext highlighter-rouge">rules-default.neon</code></td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#51-the-detector-must-be-invocable-by-the-practitioner-locally-with-no-infrastructure">5.1</a></td>
      <td>Yes</td>
      <td>Every command ran in the checkout with no service</td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#52-the-detector-must-support-invocation-over-a-subset-at-minimum-a-single-file">5.2</a></td>
      <td>Yes</td>
      <td><code class="language-plaintext highlighter-rouge">bin/qa -t phpstan -p src/ZzProbe.php</code> scanned that path alone; PHPArkitect’s lane is non-path-supporting</td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#53-the-result-must-reach-the-practitioner-in-the-output-of-the-command-they-ran">5.3</a></td>
      <td>Yes</td>
      <td>Findings in the terminal, archived log named after them</td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#54-a-finding-must-not-be-reportable-only-through-a-hosted-service-licence-tier-or-ci-only-mode-the-practitioner-cannot-invoke-locally">5.4</a></td>
      <td>Yes</td>
      <td><code class="language-plaintext highlighter-rouge">ci.bash</code> runs <code class="language-plaintext highlighter-rouge">bin/qa</code></td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#61-the-detector-must-provide-a-mechanism-that-resolves-a-printed-identifier-to-its-documentation">6.1</a></td>
      <td>Yes</td>
      <td><code class="language-plaintext highlighter-rouge">bin/rule-doc phpqaci.nullCoalescingFalse</code> resolved as printed; an unknown identifier exits 1</td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#62-resolution-of-a-bundled-rules-identifier-must-work-from-the-installed-copy-without-network-access">6.2</a></td>
      <td>Partial</td>
      <td>Bundle resolves offline from <code class="language-plaintext highlighter-rouge">docs/phpstan-rules/</code>; <code class="language-plaintext highlighter-rouge">bin/rule-doc method.notFound</code> is unknown, PHPStan’s catalogue is online only</td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#63-a-bundled-rules-documentation-must-ship-with-the-rule-at-a-version-tracked-together">6.3</a></td>
      <td>Partial</td>
      <td>Bundle pages ship with the package; PHPStan’s own rules ship none; PHPArkitect’s tier has no page</td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#64-the-detector-should-fail-its-own-release-if-a-bundled-rule-lacks-resolvable-documentation">6.4</a></td>
      <td>Partial</td>
      <td><code class="language-plaintext highlighter-rouge">RuleDocumentationTest</code> fails the build on a dangling page reference or an unindexed identifier</td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#71-a-detector-may-offer-an-inline-suppression-route-but-must-make-it-detectable-or-disableable">7.1</a></td>
      <td>Yes</td>
      <td>Inline ignores detectable by a bundled rule; PHPArkitect’s baseline disableable by <code class="language-plaintext highlighter-rouge">--skip-baseline</code></td>
    </tr>
    <tr>
      <td>Detector</td>
      <td><a href="/DETECTOR-SPEC.html#72-an-inline-suppression-route-should-require-a-written-reason">7.2</a></td>
      <td>No</td>
      <td><code class="language-plaintext highlighter-rouge">reportIgnoresWithoutComments</code> not configured; the toolchain’s justification lane stands in</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#41-every-detector-the-toolchain-routes-a-defence-through-must-conform-to-the-detector-specification">4.1</a></td>
      <td>No</td>
      <td>PHPArkitect as wrapped fails detector <a href="/DETECTOR-SPEC.html#43-the-detector-must-allow-a-rule-to-carry-a-stable-identifier-and-must-print-it-with-every-finding">4.3</a>, <a href="/DETECTOR-SPEC.html#52-the-detector-must-support-invocation-over-a-subset-at-minimum-a-single-file">5.2</a> and <a href="/DETECTOR-SPEC.html#61-the-detector-must-provide-a-mechanism-that-resolves-a-printed-identifier-to-its-documentation">6.1</a> to <a href="/DETECTOR-SPEC.html#63-a-bundled-rules-documentation-must-ship-with-the-rule-at-a-version-tracked-together">6.3</a>; five lanes print no identifier; PHPStan’s native catalogue fails detector <a href="/DETECTOR-SPEC.html#62-resolution-of-a-bundled-rules-identifier-must-work-from-the-installed-copy-without-network-access">6.2</a> and <a href="/DETECTOR-SPEC.html#63-a-bundled-rules-documentation-must-ship-with-the-rule-at-a-version-tracked-together">6.3</a></td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#42-the-toolchain-must-resolve-every-identifier-a-defence-it-routes-can-print-from-the-installed-copy-without-network-access">4.2</a></td>
      <td>Partial</td>
      <td><code class="language-plaintext highlighter-rouge">bin/rules .</code>: <code class="language-plaintext highlighter-rouge">doc: no documentation page</code> for 15 of 24 rules; <code class="language-plaintext highlighter-rouge">bin/rule-doc phpqaci.packageType</code> unknown</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#43-the-toolchain-must-forbid-through-a-defence-of-its-own-every-suppression-route-that-bypasses-the-project-record">4.3</a></td>
      <td>Partial</td>
      <td>Inline ignore forbidden; <code class="language-plaintext highlighter-rouge">phparkitect-baseline.json</code> read silently; an included PHPStan baseline escapes the justification lane</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#44-the-toolchains-own-invocation-must-satisfy-the-detector-specifications-reporting-clauses-for-every-defence-it-routes">4.4</a></td>
      <td>Yes</td>
      <td><code class="language-plaintext highlighter-rouge">bin/qa -t &lt;tool&gt; -p &lt;path&gt;</code> for every path-supporting lane</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#45-the-toolchains-entry-point-must-run-detectors-before-runners-and-must-stop-on-a-detector-failure">4.5</a></td>
      <td>Yes</td>
      <td><code class="language-plaintext highlighter-rouge">bin/qa</code> runs coding standards, linting, static analysis, then tests, failing fast or reporting every failure before the success banner</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#51-the-toolchain-must-be-able-to-list-the-defences-active-in-a-project-without-triggering-them">5.1</a></td>
      <td>Partial</td>
      <td>Rules listed with identifier, summary and route; lanes listed with <code class="language-plaintext highlighter-rouge">identifier: null</code> and no route</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#52-the-listing-must-be-derived-from-the-active-configuration">5.2</a></td>
      <td>Yes</td>
      <td>Derived from the resolved neon chain and the lane registry</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#53-a-projects-own-defences-must-appear-in-the-listing-alongside-bundled-ones">5.3</a></td>
      <td>Yes</td>
      <td>Contributor-only rules in the self-listing</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#61-the-toolchain-must-define-a-location-for-the-project-record-and-must-read-it-itself">6.1</a></td>
      <td>Yes</td>
      <td><code class="language-plaintext highlighter-rouge">ignoreErrors</code> in <code class="language-plaintext highlighter-rouge">qaConfig/phpstan.neon</code>, loaded by PHPStan</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#62-every-exception-in-the-project-record-must-carry-a-written-justification-that-names-the-hazard-and-the-scope-and-the-toolchain-must-reject-a-generic-one">6.2</a></td>
      <td>Partial</td>
      <td><code class="language-plaintext highlighter-rouge">bin/qa -t pij</code> requires a comment, rejects the paste-anywhere list and short reasons; reads the top-level file alone</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#63-the-project-record-must-be-enumerable-by-the-same-means-as-the-defences">6.3</a></td>
      <td>Yes</td>
      <td>The record, including an included baseline’s entries, appears in <code class="language-plaintext highlighter-rouge">bin/rules</code> output</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#64-the-toolchain-should-state-its-own-defaults-for-anything-the-method-leaves-to-the-project">6.4</a></td>
      <td>Partial</td>
      <td>Lane defaults in <code class="language-plaintext highlighter-rouge">docs/tools/</code>; no default for the method’s calibrations</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#71-the-toolchain-should-generate-a-summary-of-the-active-defences-suitable-for-an-agents-context">7.1</a></td>
      <td>No</td>
      <td>The agent block carries no rule lines</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#72-the-toolchain-should-deliver-that-summary-into-the-project-automatically">7.2</a></td>
      <td>Yes</td>
      <td>Block written and refreshed on install and update</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#81-a-shipped-toolchain-must-fail-its-own-release-if-a-bundled-defence-lacks-resolvable-documentation">8.1</a></td>
      <td>Partial</td>
      <td><code class="language-plaintext highlighter-rouge">RuleDocumentationTest</code> covers rules and identifier-printing lanes; requires an index row, not a page</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#82-a-shipped-toolchain-must-run-its-own-bundled-defences-on-its-own-source">8.2</a></td>
      <td>Yes</td>
      <td><code class="language-plaintext highlighter-rouge">SelfCheckRunsBundledRulesTest</code>; own <code class="language-plaintext highlighter-rouge">qaConfig/phpstan.neon</code> includes both bundles; <code class="language-plaintext highlighter-rouge">bin/qa -t arch</code> runs the tier on <code class="language-plaintext highlighter-rouge">src/</code></td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#91-a-project-that-ships-a-detector-or-a-toolchain-has-two-levels-of-conformance-graded-separately">9.1</a></td>
      <td>Yes</td>
      <td>Artefact and project graded separately above</td>
    </tr>
    <tr>
      <td>Toolchain</td>
      <td><a href="/TOOLING-SPEC.html#92-the-declaration-is-the-claim-and-the-known-gap-record-not-a-condition-of-conformance">9.2</a></td>
      <td>Yes</td>
      <td><code class="language-plaintext highlighter-rouge">composer.json</code> <code class="language-plaintext highlighter-rouge">extra.defence-before-fix</code>: method 1.0.0, toolchain 0.2.0, eight gaps; <code class="language-plaintext highlighter-rouge">project</code> object with the same keys and three gaps</td>
    </tr>
  </tbody>
</table>

<h2 id="notes-for-a-practitioner">Notes for a practitioner</h2>

<p>Install the plugin, write the rule under <code class="language-plaintext highlighter-rouge">rules:</code> with a <code class="language-plaintext highlighter-rouge">phpqaci</code>-style identifier constant, prove it with <code class="language-plaintext highlighter-rouge">bin/phpstan-rule</code> on a fixture, sweep with <code class="language-plaintext highlighter-rouge">bin/qa -t phpstan</code>, and add the documentation page so <code class="language-plaintext highlighter-rouge">bin/rule-doc</code> resolves it to a correct construction and not only to a summary. Run <code class="language-plaintext highlighter-rouge">bin/rules .</code> first on any project you arrive at: the listing and the justified exceptions are the record the method tells you to read before guessing. Treat a PHPArkitect violation as a defence with no identifier, keep <code class="language-plaintext highlighter-rouge">phparkitect-baseline.json</code> out of the repository, and put any PHPStan baseline entries in <code class="language-plaintext highlighter-rouge">qaConfig/phpstan.neon</code> itself so the justification lane sees them.</p>

  </main>
  
<footer class="site-footer">
  <p>Method specification 1.0.1, detector specification 1.0.0 and
    toolchain specification 0.2.0, published 8 September 2026. Source and history at
    <a href="https://github.com/Defence-Before-Fix/defence-before-fix.github.io">github.com/Defence-Before-Fix</a>.</p>
  <p>Defence Before Fix (DBF) was coined by <a href="https://ltscommerce.dev">Joseph Edmonds</a> of
    <a href="https://edmondscommerce.co.uk">Edmonds Commerce</a>. US spelling:
    <a href="https://defense-before-fix.github.io/">Defense Before Fix</a>.
    Licensed under <a href="https://creativecommons.org/licenses/by/4.0/">CC BY 4.0</a>.</p>
</footer>

</body>
</html>
