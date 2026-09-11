---
source_url: https://defence-before-fix.github.io/DETECTOR-SPEC.html
fetched_at: 2026-09-11T16:15:15.270640+00:00
fidelity: verbatim
source_sha256: ae0a2ebf02adbd34159023e4ddb3276ab68d30460e4eaab735a5c7d831d9266b
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
<title>Defence Before Fix: Detector Specification | Defence Before Fix (DBF)</title>
<meta name="generator" content="Jekyll v3.10.0" />
<meta property="og:title" content="Defence Before Fix: Detector Specification" />
<meta name="author" content="Joseph Edmonds" />
<meta property="og:locale" content="en_GB" />
<meta name="description" content="A phase that runs before a defect is fixed. The method, detector and toolchain specifications." />
<meta property="og:description" content="A phase that runs before a defect is fixed. The method, detector and toolchain specifications." />
<link rel="canonical" href="https://defence-before-fix.github.io/DETECTOR-SPEC.html" />
<meta property="og:url" content="https://defence-before-fix.github.io/DETECTOR-SPEC.html" />
<meta property="og:site_name" content="Defence Before Fix (DBF)" />
<meta property="og:type" content="website" />
<meta name="twitter:card" content="summary" />
<meta property="twitter:title" content="Defence Before Fix: Detector Specification" />
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"WebPage","author":{"@type":"Person","name":"Joseph Edmonds"},"description":"A phase that runs before a defect is fixed. The method, detector and toolchain specifications.","headline":"Defence Before Fix: Detector Specification","url":"https://defence-before-fix.github.io/DETECTOR-SPEC.html"}</script>
<!-- End Jekyll SEO tag -->

  
<link rel="canonical" href="https://defence-before-fix.github.io/DETECTOR-SPEC.html">
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
  <link rel="alternate" type="text/markdown" href="/raw/DETECTOR-SPEC.md" title="Raw markdown">
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
    (raw markdown), or start from <a href="/llms.txt">llms.txt</a>.
    This page as <a href="/raw/DETECTOR-SPEC.md">raw markdown</a>.</p>
  <p class="site-byline">By <a href="https://ltscommerce.dev">Joseph Edmonds</a> of
    <a href="https://edmondscommerce.co.uk">Edmonds Commerce</a>. First published 22 February 2026.</p>
</header>

  <main>
    <h1 id="defence-before-fix-detector-specification">Defence Before Fix: Detector Specification</h1>

<p><strong>Version</strong>: 1.0.0, published 2026-09-08
<strong>Companion to</strong>: <a href="/SPEC.html">the method specification</a>, version 1.0.1, and <a href="/TOOLING-SPEC.html">the toolchain specification</a>, version 0.2.0
<strong>Author</strong>: <a href="https://ltscommerce.dev">Joseph Edmonds</a>, <a href="https://edmondscommerce.co.uk">Edmonds Commerce</a>
<strong>Coined</strong>: 22 February 2026, in <a href="https://ltscommerce.dev/articles/defence-before-fix-static-analysis">the original article</a></p>

<h2 id="1-what-this-document-is-for">1. What this document is for</h2>

<p>The key words MUST, MUST NOT, REQUIRED, SHOULD, SHOULD NOT and MAY are to be interpreted as
described in RFC 2119.</p>

<p><a href="/SPEC.html">The method specification</a> states what a <a href="/SPEC.html#practitioner">Practitioner</a> does when a
<a href="/SPEC.html#defect">Defect</a> is found. This document states what a <a href="/SPEC.html#detector">Detector</a> must offer so that they
can do it: write a <a href="/SPEC.html#rule">Rule</a>, prove it, run it and resolve what it prints. It is addressed to
whoever maintains a <a href="/SPEC.html#detector">Detector</a>, which the method specification defines as a tool that reads
code without executing it and reports occurrences of a pattern.</p>

<p>A <a href="/SPEC.html#detector">Detector</a> is one part of a project’s <a href="/SPEC.html#toolchain">Toolchain</a>, and this document judges it
on what it offers alone. What the assembled <a href="/SPEC.html#toolchain">Toolchain</a> of a project must add on top, the
<a href="/TOOLING-SPEC.html#project-record">Project record</a>, the listing of active <a href="/SPEC.html#defence">Defences</a> and the
governance of <a href="/SPEC.html#suppression">Suppression</a>, is stated in <a href="/TOOLING-SPEC.html">the toolchain specification</a>.
The two are separable because they are built by different people: a <a href="/SPEC.html#detector">Detector</a> is authored once
and installed into projects its maintainer will never see, whilst governance is decided by each
project for itself. A document that asked a <a href="/SPEC.html#detector">Detector</a> to enforce a project’s governance would
fail every <a href="/SPEC.html#detector">Detector</a> in use and would not make any project better governed.</p>

<p><strong>The governing principle</strong>: where the method specification requires a <a href="/SPEC.html#practitioner">Practitioner</a> to do
something with a <a href="/SPEC.html#rule">Rule</a>, a <a href="/SPEC.html#detector">Conforming</a> MUST make that possible
without the project building the mechanism first. A <a href="/SPEC.html#detector">Detector</a> that leaves the project to
construct the means has moved the obligation rather than met it.</p>

<h2 id="2-terminology">2. Terminology</h2>

<p>Terms from the method specification carry over unchanged. Every capitalised term links to its
definition; the ones this document leans on most are, in short:</p>

<table>
  <thead>
    <tr>
      <th>Term</th>
      <th>In one line</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><a href="/SPEC.html#detector">Detector</a></td>
      <td>A tool that reads code without executing it and reports what it finds</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#rule">Rule</a></td>
      <td>One check a <a href="/SPEC.html#detector">Detector</a> evaluates</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#identifier">Identifier</a></td>
      <td>The stable name printed with a finding that leads to its documentation</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#practitioner">Practitioner</a></td>
      <td>Whoever is doing the work, a person or an <a href="/SPEC.html#agent">Agent</a></td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#toolchain">Toolchain</a></td>
      <td>Everything a project assembles to run its checks through, <a href="/SPEC.html#detector">Detectors</a> included</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#suppression">Suppression</a></td>
      <td>Making a finding go away without fixing it</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#baseline">Baseline</a></td>
      <td>A recorded set of existing findings a <a href="/SPEC.html#rule">Rule</a> is told to ignore</td>
    </tr>
  </tbody>
</table>

<p>These are additional.</p>

<h4 id="rule-author">Rule author</h4>

<p>Whoever writes a <a href="/SPEC.html#rule">Rule</a>. May be the <a href="/SPEC.html#detector">Detector</a>’s maintainer, a <a href="/SPEC.html#toolchain">Toolchain</a>’s maintainer or the project that runs it.</p>

<h4 id="bundled-rule">Bundled rule</h4>

<p>A <a href="/SPEC.html#rule">Rule</a> the <a href="/SPEC.html#detector">Detector</a> ships, or fetches on the <a href="/SPEC.html#practitioner">Practitioner</a>’s behalf from a source the <a href="/SPEC.html#detector">Detector</a>’s maintainer controls. Its <a href="#rule-author">Rule author</a> will never see the codebases it runs in, so everything a <a href="/SPEC.html#practitioner">Practitioner</a> needs in order to act on it has to travel with it.</p>

<h4 id="harness">Harness</h4>

<p>The route by which one <a href="/SPEC.html#rule">Rule</a> is run against supplied code and its findings observed, without the project’s own test suite and without every other <a href="/SPEC.html#rule">Rule</a> running alongside it.</p>

<p>The distinction between a <a href="#bundled-rule">Bundled rule</a> and a project’s own <a href="/SPEC.html#rule">Rule</a> matters throughout.
The <a href="/SPEC.html#detector">Detector</a> owns the documentation of the first and can be held to shipping it; it cannot
know the documentation of the second, and what it owes there is the mechanism that lets the project
attach its own.</p>

<h2 id="3-the-division-of-responsibility">3. The division of responsibility</h2>

<p>Each mechanism the method needs has two halves. Stating only one of them is what produces a
<a href="/SPEC.html#detector">Detector</a> that is <em>nearly</em> usable.</p>

<table>
  <thead>
    <tr>
      <th>Requirement</th>
      <th>The <a href="/SPEC.html#detector">Detector</a> MUST provide</th>
      <th>The <a href="#rule-author">Rule author</a> supplies</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Bespoke <a href="/SPEC.html#rule">Rules</a></td>
      <td>The authoring and registration route</td>
      <td>The <a href="/SPEC.html#rule">Rules</a></td>
    </tr>
    <tr>
      <td>The red proof</td>
      <td>A <a href="#harness">Harness</a></td>
      <td>The <a href="/SPEC.html#fixture">Fixture</a> and the red run</td>
    </tr>
    <tr>
      <td>Running the <a href="/SPEC.html#rule">Rule</a></td>
      <td>A local invocation, including subsets</td>
      <td>Running it</td>
    </tr>
    <tr>
      <td>The <a href="/SPEC.html#identifier">Identifier</a></td>
      <td>A place to carry it, and printing it with every finding</td>
      <td>Choosing it once</td>
    </tr>
    <tr>
      <td>Resolution of a <a href="#bundled-rule">Bundled rule</a></td>
      <td>The lookup, and the documentation, on disk</td>
      <td>Nothing</td>
    </tr>
    <tr>
      <td>Resolution of a project’s own <a href="/SPEC.html#rule">Rule</a></td>
      <td>The <a href="/SPEC.html#identifier">Identifier</a> printed unaltered</td>
      <td>The documentation and, with the <a href="/SPEC.html#toolchain">Toolchain</a>, the lookup</td>
    </tr>
    <tr>
      <td>Inline <a href="/SPEC.html#suppression">Suppression</a></td>
      <td>A route the project can detect or disable</td>
      <td>The decision whether to forbid it</td>
    </tr>
  </tbody>
</table>

<p>Read down the middle column and the shape of a <a href="/SPEC.html#detector">Conforming</a> is already
visible. Read across any row and the failure mode is visible too: either half alone leaves the
<a href="/SPEC.html#practitioner">Practitioner</a> stuck.</p>

<h2 id="4-authoring-rules">4. Authoring rules</h2>

<h3 id="41-the-detector-must-support-bespoke-rules-written-by-the-project-that-runs-it">4.1 The detector MUST support bespoke rules written by the project that runs it</h3>

<p>Configuration of an existing <a href="/SPEC.html#rule">Rule</a> set is not sufficient. The project must be able to express a
pattern the <a href="/SPEC.html#detector">Detector</a>’s authors have never anticipated, and register it so that it runs with
the same standing as a <a href="#bundled-rule">Bundled rule</a>.</p>

<p><strong>Why</strong>: the method turns a specific <a href="/SPEC.html#defect">Defect</a> into a <a href="/SPEC.html#defence">Class</a>, and the
<a href="/SPEC.html#class">Classes</a> that matter most to a project are the ones peculiar to it. A <a href="/SPEC.html#detector">Detector</a> offering
only a fixed catalogue can defend against the industry’s known <a href="/SPEC.html#hazard">Hazards</a> and none of the
project’s own, which is the half that carries its institutional knowledge.</p>

<h3 id="42-the-detector-must-provide-a-harness-that-runs-a-single-rule-against-supplied-code">4.2 The detector MUST provide a harness that runs a single rule against supplied code</h3>

<p>A <a href="#harness">Harness</a> reports, for a given input, whether the <a href="/SPEC.html#rule">Rule</a> fired and what it printed, without
executing the project’s own test suite and without the other <a href="/SPEC.html#rule">Rules</a> obscuring the answer.
This applies to every <a href="/SPEC.html#rule">Rule</a> the <a href="/SPEC.html#detector">Detector</a> runs, bundled or bespoke; a
<a href="/SPEC.html#detector">Detector</a> failing 4.1 is graded here on its <a href="#bundled-rule">Bundled rules</a>.</p>

<p><strong>Why</strong>: clause 3.3 of the method specification requires the <a href="/SPEC.html#rule">Rule</a> to be proven by making it go
red. A <a href="/SPEC.html#practitioner">Practitioner</a> who can only observe a <a href="/SPEC.html#rule">Rule</a>’s behaviour by running every
<a href="/SPEC.html#rule">Rule</a> over the whole codebase cannot demonstrate that a <a href="/SPEC.html#rule">Rule</a> fires on the pattern rather
than on something incidental.</p>

<h3 id="43-the-detector-must-allow-a-rule-to-carry-a-stable-identifier-and-must-print-it-with-every-finding">4.3 The detector MUST allow a rule to carry a stable identifier, and MUST print it with every finding</h3>

<p>The <a href="/SPEC.html#identifier">Identifier</a> MUST be stable across releases and MUST NOT be derived from the <a href="/SPEC.html#rule">Rule</a>’s
file path, <a href="/SPEC.html#class">Class</a> name or position in a configuration file. The <a href="#rule-author">Rule author</a> chooses it
once. The <a href="/SPEC.html#detector">Detector</a> MUST print it, unaltered, alongside every finding the <a href="/SPEC.html#rule">Rule</a>
reports, in its default output and in every machine-readable format it offers. A prefix the
<a href="/SPEC.html#detector">Detector</a> adds from the invoking directory or the <a href="/SPEC.html#rule">Rule</a>’s location is derived from
the file path. A namespace the <a href="#rule-author">Rule author</a> assigns once in configuration is not.</p>

<table>
  <thead>
    <tr>
      <th><a href="/SPEC.html#identifier">Identifier</a></th>
      <th>Where it comes from</th>
      <th>Stable?</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><code class="language-plaintext highlighter-rouge">proj.no-raw-sql</code></td>
      <td>Written once by the <a href="#rule-author">Rule author</a> in configuration, printed as is</td>
      <td>Yes</td>
    </tr>
    <tr>
      <td><code class="language-plaintext highlighter-rouge">LM-0107</code></td>
      <td>Assigned once by the <a href="/SPEC.html#detector">Detector</a>’s maintainer at the <a href="/SPEC.html#rule">Rule</a>’s first release</td>
      <td>Yes</td>
    </tr>
    <tr>
      <td><code class="language-plaintext highlighter-rouge">rules/no-raw-sql</code></td>
      <td>Produced from where the <a href="/SPEC.html#rule">Rule</a>’s file sits; changes when the file moves</td>
      <td>No</td>
    </tr>
    <tr>
      <td><code class="language-plaintext highlighter-rouge">NoRawSqlRule</code></td>
      <td>The <a href="/SPEC.html#rule">Rule</a>’s <a href="/SPEC.html#class">Class</a> name; changes when the <a href="/SPEC.html#rule">Rule</a> is renamed</td>
      <td>No</td>
    </tr>
    <tr>
      <td><code class="language-plaintext highlighter-rouge">rule 7</code></td>
      <td>The <a href="/SPEC.html#rule">Rule</a>’s position in a configuration file; changes when one is inserted</td>
      <td>No</td>
    </tr>
  </tbody>
</table>

<p><strong>Why</strong>: the <a href="/SPEC.html#identifier">Identifier</a> is the only string that reaches the <a href="/SPEC.html#practitioner">Practitioner</a> and the
only key their lookup can use. An <a href="/SPEC.html#identifier">Identifier</a> that changes when a <a href="/SPEC.html#rule">Rule</a> is renamed
silently breaks every reference to it, including references written down by people who have left.
An <a href="/SPEC.html#identifier">Identifier</a> the <a href="/SPEC.html#detector">Detector</a> carries but does not print is one the
<a href="/SPEC.html#practitioner">Practitioner</a> was never given.</p>

<h3 id="44-the-detector-should-enforce-43-with-a-rule-of-its-own">4.4 The detector SHOULD enforce 4.3 with a rule of its own</h3>

<p>A <a href="/SPEC.html#rule">Rule</a> over the <a href="/SPEC.html#rule">Rules</a>, failing any <a href="/SPEC.html#rule">Rule</a> that reports without a stable
<a href="/SPEC.html#identifier">Identifier</a>.</p>

<p><strong>Why</strong>: this is the method applied to the <a href="/SPEC.html#detector">Detector</a> itself, and it is cheap. The worked
example is <code class="language-plaintext highlighter-rouge">php-qa-ci</code>’s <code class="language-plaintext highlighter-rouge">RequireRuleIdentifierConstantRule</code>, a <a href="/SPEC.html#rule">Rule</a> hosted in PHPStan by a
<a href="/SPEC.html#toolchain">Toolchain</a> built on it: it rejects a magic-string <a href="/SPEC.html#identifier">Identifier</a> and names the
constant to declare instead. Nothing about it needed to live outside the <a href="/SPEC.html#detector">Detector</a>.</p>

<h2 id="5-reporting">5. Reporting</h2>

<h3 id="51-the-detector-must-be-invocable-by-the-practitioner-locally-with-no-infrastructure">5.1 The detector MUST be invocable by the practitioner, locally, with no infrastructure</h3>

<p><strong>Why</strong>: method specification clause 8.1. An <a href="/SPEC.html#agent">Agent</a> that cannot check its own work cannot
iterate against a <a href="/SPEC.html#defence">Defence</a>, so the loop never closes in the turn where it is cheap to close.</p>

<h3 id="52-the-detector-must-support-invocation-over-a-subset-at-minimum-a-single-file">5.2 The detector MUST support invocation over a subset, at minimum a single file</h3>

<p><strong>Why</strong>: 5.1 is satisfied in principle by a whole-codebase run and defeated in practice by one.
Checking a single edited file has to be fast enough to do on every edit, or it will not be done on
any.</p>

<h3 id="53-the-result-must-reach-the-practitioner-in-the-output-of-the-command-they-ran">5.3 The result MUST reach the practitioner in the output of the command they ran</h3>

<p>Where the <a href="/SPEC.html#detector">Detector</a> writes fuller detail elsewhere, the invoked command’s own output MUST
carry both a usable summary and the location of the remainder.</p>

<p><strong>Why</strong>: method specification clause 8.2. A report the <a href="/SPEC.html#practitioner">Practitioner</a> has to go and find is a
report that arrives after the decision it was meant to inform.</p>

<h3 id="54-a-finding-must-not-be-reportable-only-through-a-hosted-service-licence-tier-or-ci-only-mode-the-practitioner-cannot-invoke-locally">5.4 A finding MUST NOT be reportable only through a hosted service, licence tier or CI-only mode the practitioner cannot invoke locally</h3>

<p>A finding that the <a href="/SPEC.html#detector">Detector</a> reports only through a hosted service, a licence tier or a
continuous-integration-only mode the <a href="/SPEC.html#practitioner">Practitioner</a> cannot invoke locally does not
<a href="/SPEC.html#conform">Conform</a>, whatever the same <a href="/SPEC.html#rule">Rule</a> reports there.</p>

<p><strong>Why</strong>: a <a href="/SPEC.html#rule">Rule</a> that fires only in an environment the <a href="/SPEC.html#practitioner">Practitioner</a> has no access to
teaches nobody anything and blocks them anyway, which is the worst combination available.</p>

<h2 id="6-resolving-an-identifier">6. Resolving an identifier</h2>

<h3 id="61-the-detector-must-provide-a-mechanism-that-resolves-a-printed-identifier-to-its-documentation">6.1 The detector MUST provide a mechanism that resolves a printed identifier to its documentation</h3>

<p>Two cases, by who wrote the <a href="/SPEC.html#rule">Rule</a>:</p>

<table>
  <thead>
    <tr>
      <th>The <a href="/SPEC.html#rule">Rule</a></th>
      <th>Who supplies the documentation</th>
      <th>What this clause asks of the <a href="/SPEC.html#detector">Detector</a></th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>A <a href="#bundled-rule">Bundled rule</a></td>
      <td>The <a href="/SPEC.html#detector">Detector</a></td>
      <td>Resolve the <a href="/SPEC.html#identifier">Identifier</a>, presented alone, to that documentation</td>
    </tr>
    <tr>
      <td>The project’s own</td>
      <td>The project</td>
      <td>Print the <a href="/SPEC.html#identifier">Identifier</a> unaltered; the lookup is the <a href="/SPEC.html#toolchain">Toolchain</a>’s to give</td>
    </tr>
  </tbody>
</table>

<p>In both, the mechanism is keyed on <strong>the <a href="/SPEC.html#identifier">Identifier</a> exactly as printed</strong>. A command, an index
file or a URL are all acceptable forms. Each case in turn:</p>

<p><strong>For a <a href="#bundled-rule">Bundled rule</a></strong>, the <a href="/SPEC.html#detector">Detector</a> supplies the documentation and the
lookup, and clauses 6.2 and 6.3 say where. The mechanism MUST resolve an <a href="/SPEC.html#identifier">Identifier</a>
presented alone, because the reader who needs it most has the <a href="/SPEC.html#identifier">Identifier</a> from a log, a
ticket or a colleague and not the <a href="/SPEC.html#message">Message</a>. A <a href="/SPEC.html#message">Message</a> that carries its own
documentation path resolves that <a href="/SPEC.html#message">Message</a>, not the <a href="/SPEC.html#identifier">Identifier</a>.</p>

<p><strong>For a project’s own <a href="/SPEC.html#rule">Rule</a></strong>, the <a href="/SPEC.html#detector">Detector</a> cannot know the documentation, so what
it owes is the half it can give: the <a href="/SPEC.html#identifier">Identifier</a> printed unaltered under clause 4.3, and no
transformation of it. Where the <a href="/SPEC.html#identifier">Identifier</a> is itself a URL, as method clause 3.6 allows,
the <a href="/SPEC.html#detector">Detector</a> prints that URL and has done what this clause asks. What this clause forbids is
a documentation path printed alongside a shorter <a href="/SPEC.html#identifier">Identifier</a> that cannot be looked up on its
own. The lookup for such
a <a href="/SPEC.html#rule">Rule</a> is an obligation on the project’s assembled <a href="/SPEC.html#toolchain">Toolchain</a>, under clause 4.2
of the <a href="/TOOLING-SPEC.html">toolchain specification</a>, and a <a href="/SPEC.html#detector">Detector</a> that offers it as well has
gone further than this clause asks.</p>

<p><strong>Why</strong>: method specification clause 8.3 requires the <a href="/SPEC.html#identifier">Identifier</a> to resolve without a human.
An index keyed on anything else does not resolve it. This is the most commonly failed clause in this
document and it fails in a specific way: documentation exists, is genuinely good, and is keyed on the
<a href="/SPEC.html#rule">Rule</a>’s <a href="/SPEC.html#class">Class</a> or file name, which is a string the <a href="/SPEC.html#practitioner">Practitioner</a> was never
given. The lookup they can actually perform is the only one that counts.</p>

<h3 id="62-resolution-of-a-bundled-rules-identifier-must-work-from-the-installed-copy-without-network-access">6.2 Resolution of a bundled rule’s identifier MUST work from the installed copy, without network access</h3>

<p><strong>Why</strong>: an <a href="/SPEC.html#agent">Agent</a> working offline, behind a proxy, or against a URL that has since moved needs
the answer to be on disk. A dependency the project already installed is on disk by definition. A
catalogue on the <a href="/SPEC.html#detector">Detector</a>’s website, however complete, is the right documentation in the wrong
place.</p>

<h3 id="63-a-bundled-rules-documentation-must-ship-with-the-rule-at-a-version-tracked-together">6.3 A bundled rule’s documentation MUST ship with the rule, at a version tracked together</h3>

<p>Every <a href="/SPEC.html#identifier">Identifier</a> a <a href="#bundled-rule">Bundled rule</a> can print MUST resolve, under clause 6.1,
to a page in that shipped documentation. One <a href="/SPEC.html#identifier">Identifier</a> that does not resolve fails this
clause, however many others do. A page per <a href="/SPEC.html#identifier">Identifier</a> satisfies this; a page per family of
<a href="/SPEC.html#identifier">Identifiers</a> MAY be used instead, under clause 6.5.</p>

<p><strong>Why</strong>: method specification clause 3.6. A <a href="#bundled-rule">Bundled rule</a> travels into codebases its author
will never see. If its documentation lives only in the <a href="/SPEC.html#detector">Detector</a>’s repository or on its
website, then every project that installs it is one link rot away from a <a href="/SPEC.html#rule">Rule</a> that blocks
without explaining.</p>

<p>The failure to guard against is not the absent document but the <strong>dangling one</strong>: a reference to
documentation that was planned and never written is worse than no reference, because it consumes
the <a href="/SPEC.html#practitioner">Practitioner</a>’s attention before failing them.</p>

<h3 id="64-the-detector-should-fail-its-own-release-if-a-bundled-rule-lacks-resolvable-documentation">6.4 The detector SHOULD fail its own release if a bundled rule lacks resolvable documentation</h3>

<p>An automated check over every <a href="/SPEC.html#identifier">Identifier</a> a <a href="#bundled-rule">Bundled rule</a> can print, applying the
family list or pattern of clause 6.5 where one is used, that blocks the release when any of them
lands on no page.</p>

<p><strong>Why</strong>: clause 6.1 is the clause most easily believed to be satisfied whilst being broken, because
the documentation is written by the same person who wrote the <a href="/SPEC.html#rule">Rule</a> and its absence is
invisible from the inside. Where the <a href="/SPEC.html#detector">Detector</a> is shipped inside a <a href="/SPEC.html#toolchain">Toolchain</a>,
the <a href="/TOOLING-SPEC.html">toolchain specification</a>’s self-audit makes this check a MUST for the <a href="/SPEC.html#toolchain">Toolchain</a>; a
<a href="/SPEC.html#detector">Detector</a> released on its own is asked for it as a SHOULD because the same <a href="/SPEC.html#class">Class</a> of
<a href="/SPEC.html#defect">Defect</a>, a <a href="/SPEC.html#rule">Rule</a> that blocks without explaining, is detectable mechanically there too.</p>

<h3 id="65-one-page-may-document-a-family-of-identifiers-and-should-name-its-members-so-a-check-can-confirm-them">6.5 One page MAY document a family of identifiers, and SHOULD name its members so a check can confirm them</h3>

<p>Where several <a href="#bundled-rule">Bundled rules</a> share a prefix, one page MAY document them all. Such a page
SHOULD let the check clause 6.4 asks for confirm that each member resolves to it. Two forms do
that and one does not:</p>

<table>
  <thead>
    <tr>
      <th>The page</th>
      <th>A check can confirm each member?</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Lists every full <a href="/SPEC.html#identifier">Identifier</a> in the family, <code class="language-plaintext highlighter-rouge">LM-0100</code>, <code class="language-plaintext highlighter-rouge">LM-0101</code>, and so on</td>
      <td>Yes</td>
    </tr>
    <tr>
      <td>Carries a regular expression the check can run, <code class="language-plaintext highlighter-rouge">^LM-01[0-9]{2}$</code>, matching every member and nothing else</td>
      <td>Yes</td>
    </tr>
    <tr>
      <td>Says in prose that everything under <code class="language-plaintext highlighter-rouge">LM-01</code> is documented here</td>
      <td>No: a person can infer it, a check cannot</td>
    </tr>
  </tbody>
</table>

<p>The list or the regular expression lives in the installed file, since clause 6.2 requires
resolution to work from the installed copy, not only in a rendered web page.</p>

<p><strong>Why</strong>: a family page is a convenience for the author, and the cost of it must not fall on the
check. A page that a person can see covers <code class="language-plaintext highlighter-rouge">LM-0107</code> but a check cannot leaves clause 6.4 with
nothing to run.</p>

<h2 id="7-suppression">7. Suppression</h2>

<h3 id="71-a-detector-may-offer-an-inline-suppression-route-but-must-make-it-detectable-or-disableable">7.1 A detector MAY offer an inline suppression route, but MUST make it detectable or disableable</h3>

<p>An inline ignore comment, a per-line directive and a generated <a href="/SPEC.html#baseline">Baseline</a> are all such
routes. The <a href="/SPEC.html#detector">Detector</a> MAY ship them. For each one it MUST do at least one of two things, and either
alone satisfies this clause: make the route disableable by configuration; or make the route
detectable, by a <a href="/SPEC.html#rule">Rule</a> the project can write in the <a href="/SPEC.html#detector">Detector</a> itself or by a mechanical check
the <a href="/SPEC.html#detector">Detector</a> documents. Either lets a project which decides to forbid the route enforce that
decision. A route that can be neither switched off nor seen does not <a href="/SPEC.html#conform">Conform</a>.</p>

<p><strong>Why</strong>: the method specification’s position is that <a href="/SPEC.html#suppression">Suppression</a> is an <a href="/SPEC.html#owner">Owner</a>
decision under its clause 3.4 and section 4, and an <a href="/SPEC.html#owner">Owner</a> cannot decide something they are
never shown. The <a href="/SPEC.html#detector">Detector</a> is not the <a href="/SPEC.html#owner">Owner</a> and does not know the project’s
governance, so it is not asked to enforce it; what it is asked is not to hide the route. Every
<a href="/SPEC.html#detector">Detector</a> in wide use ships an inline ignore, and a clause that forbade them would fail all of
them without governing any project better. Whether the route is forbidden is the project’s decision,
enforced through its <a href="/SPEC.html#toolchain">Toolchain</a> under clause 4.3 of the <a href="/TOOLING-SPEC.html">toolchain specification</a>.</p>

<h3 id="72-an-inline-suppression-route-should-require-a-written-reason">7.2 An inline suppression route SHOULD require a written reason</h3>

<p>The <a href="/SPEC.html#detector">Detector</a> SHOULD reject, or be configurable to reject, an inline <a href="/SPEC.html#suppression">Suppression</a>
that carries no reason, and SHOULD NOT supply a default one, including each entry of a generated
<a href="/SPEC.html#baseline">Baseline</a>.</p>

<p><strong>Why</strong>: a <a href="/SPEC.html#suppression">Suppression</a> without a reason is indistinguishable from one nobody would defend,
and the person who could tell them apart is usually gone. Requiring the sentence costs the author a
minute at the moment they have the reason in mind, and it is the only thing that makes the
<a href="/SPEC.html#suppression">Suppression</a> reviewable later. PHPStan’s <code class="language-plaintext highlighter-rouge">reportIgnoresWithoutComments</code> is the shape of it.</p>

<h2 id="8-conformance">8. Conformance</h2>

<p><strong>A <a href="/SPEC.html#conform">Detector</a></strong> if it satisfies every MUST in sections 4 to 7.</p>

<p>Partial <a href="/SPEC.html#conform">Conformance</a> MUST NOT be described as <a href="/SPEC.html#conform">Conformance</a>. A <a href="/SPEC.html#detector">Detector</a>
that satisfies most of this document is in a normal and respectable condition; it is not
<a href="/SPEC.html#conform">Conforming</a>, and describing it as such removes the only value the word has.</p>

<p>This document defines one level. The clauses of the method specification’s section 8 that reach
beyond what sections 4 to 7 here already secure, the listing of active <a href="/SPEC.html#defence">Defences</a> and the
summary for an <a href="/SPEC.html#agent">Agent</a>’s context, are obligations on a project’s assembled <a href="/SPEC.html#toolchain">Toolchain</a>
and are stated in the <a href="/TOOLING-SPEC.html">toolchain specification</a>, so there is no separate <a href="/SPEC.html#agent">Agent</a>-support level
for a <a href="/SPEC.html#detector">Detector</a>.</p>

<h3 id="81-a-maintainer-may-declare-the-version-of-this-document-the-detector-conforms-to-and-the-declaration-records-known-gaps">8.1 A maintainer MAY declare the version of this document the detector conforms to, and the declaration records known gaps</h3>

<p>The declaration is machine-readable, in whatever form the <a href="/SPEC.html#detector">Detector</a>’s ecosystem uses to
record dependencies. The same declaration is where a gap against this document is recorded once it is
known: a <a href="/SPEC.html#detector">Detector</a> that has learnt, from its own checks or from a <a href="/SPEC.html#practitioner">Practitioner</a>’s
report under the method’s clause 3.2, that it fails a MUST in sections 4 to 7 MUST record that gap
alongside the version it declares, in the same file or one it names. A declaration with a non-empty
gap record is a statement of where the <a href="/SPEC.html#detector">Detector</a> stands and is not a claim of
<a href="/SPEC.html#conform">Conformance</a>.</p>

<p>The declaration is optional. It is how a maintainer claims <a href="/SPEC.html#conform">Conformance</a>; it is not a
condition of it. A <a href="/SPEC.html#detector">Detector</a> that predates this document, or whose maintainer has never read
it, MAY be graded <a href="/SPEC.html#conform">Conforming</a> on evidence by anyone who exercises the clauses above against
it, and a verdict on any <a href="/SPEC.html#detector">Detector</a>, declared or not, rests on that exercise and not on the
claim. A declaration tells the reader what the maintainer believes and what they know to be missing;
the reader still runs the <a href="#harness">Harness</a>.</p>

<p>The shape is illustrative rather than prescribed. In a Composer manifest:</p>

<div class="language-json highlighter-rouge"><div class="highlight"><pre class="highlight"><code><span class="nl">"extra"</span><span class="p">:</span><span class="w"> </span><span class="p">{</span><span class="w">
  </span><span class="nl">"defence-before-fix"</span><span class="p">:</span><span class="w"> </span><span class="p">{</span><span class="w">
    </span><span class="nl">"method"</span><span class="p">:</span><span class="w"> </span><span class="s2">"1.0.1"</span><span class="p">,</span><span class="w">
    </span><span class="nl">"detector"</span><span class="p">:</span><span class="w"> </span><span class="s2">"1.0.0"</span><span class="p">,</span><span class="w">
    </span><span class="nl">"toolchain"</span><span class="p">:</span><span class="w"> </span><span class="kc">null</span><span class="p">,</span><span class="w">
    </span><span class="nl">"known-gaps"</span><span class="p">:</span><span class="w"> </span><span class="p">[]</span><span class="w">
  </span><span class="p">}</span><span class="w">
</span><span class="p">}</span><span class="w">
</span></code></pre></div></div>

<p>In a package.json, the equivalent is a top-level <code class="language-plaintext highlighter-rouge">defenceBeforeFix</code> object with the same keys. A
<a href="/SPEC.html#detector">Detector</a> that is not also shipped as a <a href="/SPEC.html#toolchain">Toolchain</a> leaves <code class="language-plaintext highlighter-rouge">toolchain</code> empty;
a project that ships both declares both. Each entry in <code class="language-plaintext highlighter-rouge">known-gaps</code> names the clause and states the
gap in a sentence.</p>

<p><strong>Why</strong>: a <a href="/SPEC.html#conform">Conformance</a> claim in a README is a sentence; a claim in a manifest is a fact about
a specific installed artefact, checkable by anyone, including mechanically, and it fixes what
“<a href="/SPEC.html#conform">Conforming</a>” meant at the point the claim was made. Making the claim a condition of
<a href="/SPEC.html#conform">Conformance</a>, though, would mean a <a href="/SPEC.html#detector">Detector</a> with every mechanism in place could
never <a href="/SPEC.html#conform">Conform</a> until its maintainer had heard of this document, which grades the
maintainer’s reading rather than the <a href="/SPEC.html#detector">Detector</a>.</p>

<h2 id="9-relationship-to-the-other-specifications">9. Relationship to the other specifications</h2>

<p>This document adds no obligations to a <a href="/SPEC.html#practitioner">Practitioner</a> and relaxes none. Every clause here
exists to make a clause of the method specification achievable with a <a href="/SPEC.html#detector">Detector</a> in hand.</p>

<p>Where this document and the method specification disagree, the method specification governs. It
describes the method, which is the thing being specified; this describes one piece of the equipment.</p>

<p>The <a href="/TOOLING-SPEC.html">toolchain specification</a> states what a project’s assembled <a href="/SPEC.html#toolchain">Toolchain</a> must offer beyond
what each <a href="/SPEC.html#detector">Detector</a> in it offers, and it requires every <a href="/SPEC.html#detector">Detector</a> a <a href="/SPEC.html#defence">Defence</a>
is routed through to <a href="/SPEC.html#conform">Conform</a> to this one. A <a href="/SPEC.html#detector">Detector</a> shipped inside a
<a href="/SPEC.html#toolchain">Toolchain</a> is measured here as a <a href="/SPEC.html#detector">Detector</a> and there as part of the
<a href="/SPEC.html#toolchain">Toolchain</a>; the two verdicts are separate and neither implies the other.</p>

<p>Nothing here requires a project to use a <a href="/SPEC.html#detector">Conforming</a>. A project can
<a href="/SPEC.html#conform">Conform</a> to the method specification on a <a href="/SPEC.html#detector">Detector</a> that <a href="/SPEC.html#conform">Conforms</a> to
none of this, at the cost of building the missing mechanisms itself. This document exists so that it
does not have to.</p>

<!-- Term link definitions -->


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
