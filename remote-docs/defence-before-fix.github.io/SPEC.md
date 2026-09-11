---
source_url: https://defence-before-fix.github.io/SPEC.html
fetched_at: 2026-09-11T16:15:14.587203+00:00
fidelity: verbatim
source_sha256: 93281bb8f760d97e3112ff1e4e39e16571cff4f470185251f63572e6b8c0f91b
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
<title>Defence Before Fix: Method Specification | Defence Before Fix (DBF)</title>
<meta name="generator" content="Jekyll v3.10.0" />
<meta property="og:title" content="Defence Before Fix: Method Specification" />
<meta name="author" content="Joseph Edmonds" />
<meta property="og:locale" content="en_GB" />
<meta name="description" content="A phase that runs before a defect is fixed. The method, detector and toolchain specifications." />
<meta property="og:description" content="A phase that runs before a defect is fixed. The method, detector and toolchain specifications." />
<link rel="canonical" href="https://defence-before-fix.github.io/SPEC.html" />
<meta property="og:url" content="https://defence-before-fix.github.io/SPEC.html" />
<meta property="og:site_name" content="Defence Before Fix (DBF)" />
<meta property="og:type" content="website" />
<meta name="twitter:card" content="summary" />
<meta property="twitter:title" content="Defence Before Fix: Method Specification" />
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"WebPage","author":{"@type":"Person","name":"Joseph Edmonds"},"description":"A phase that runs before a defect is fixed. The method, detector and toolchain specifications.","headline":"Defence Before Fix: Method Specification","url":"https://defence-before-fix.github.io/SPEC.html"}</script>
<!-- End Jekyll SEO tag -->

  
<link rel="canonical" href="https://defence-before-fix.github.io/SPEC.html">
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
  <link rel="alternate" type="text/markdown" href="/raw/SPEC.md" title="Raw markdown">
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
    This page as <a href="/raw/SPEC.md">raw markdown</a>.</p>
  <p class="site-byline">By <a href="https://ltscommerce.dev">Joseph Edmonds</a> of
    <a href="https://edmondscommerce.co.uk">Edmonds Commerce</a>. First published 22 February 2026.</p>
</header>

  <main>
    <h1 id="defence-before-fix-method-specification">Defence Before Fix: Method Specification</h1>

<p><strong>Version</strong>: 1.0.1, published 2026-09-08
<strong>Companion to</strong>: <a href="/DETECTOR-SPEC.html">the detector specification</a>, version 1.0.0, and <a href="/TOOLING-SPEC.html">the toolchain specification</a>, version 0.2.0
<strong>Author</strong>: <a href="https://ltscommerce.dev">Joseph Edmonds</a>, <a href="https://edmondscommerce.co.uk">Edmonds Commerce</a>
<strong>Coined</strong>: 22 February 2026, in <a href="https://ltscommerce.dev/articles/defence-before-fix-static-analysis">the original article</a></p>

<blockquote>
  <p><strong>Defence Before Fix</strong> (<a href="#dbf">DBF</a>) is a phase that runs <em>before</em> a <a href="#defect">Defect</a> is fixed. Rather than dropping
straight into remediating the specific <a href="#instance">Instance</a> in front of you, you first treat that <a href="#instance">Instance</a>
as evidence of a <a href="#class">Class</a>, and you build the automated <a href="#defence">Defence</a> that detects every occurrence of
that <a href="#class">Class</a> across the whole codebase. The <a href="#defence">Defence</a> is only trusted once it has been seen to
fire.</p>
</blockquote>

<p>The <a href="#defect">Defect</a> is what makes this possible. It is a real, confirmed, impactful example of a harmful
pattern, which is precisely the raw material a good custom <a href="#rule">Rule</a> needs and which speculative
<a href="#rule">Rules</a> never have. Every <a href="#defect">Defect</a> is therefore an opportunity to extend the codebase’s permanent
defensive <a href="#coverage">Coverage</a>, and that opportunity exists only in the window before the fix.</p>

<p>Defence Before Fix does not replace test-driven development. The specific <a href="#defect">Defect</a> is still
reproduced with a test and proven fixed, exactly as normal. The two operate at different levels:
TDD addresses the <a href="#instance">Instance</a>, Defence Before Fix addresses the <a href="#class">Class</a>.</p>

<p>Not to be confused with <em>Defence in depth</em>, which is a security term meaning something else
entirely. The <a href="#defence">Defence</a> here comes before the fix in time, not in layers.</p>

<p>US spelling: <strong>Defense Before Fix</strong>. Abbreviated <a href="#dbf">DBF</a> throughout.</p>

<hr />

<h2 id="status-of-this-document">Status of this document</h2>

<p>This is version 1.0.1 of the specification. It is normative: section 3 defines the method,
section 4 states who decides what, and section 7 defines what <a href="#conform">Conformance</a> means and who may claim
it.</p>

<p><strong>This document is the source of truth for what the method is.</strong> Where anything else describing
Defence Before Fix disagrees with it, including the article in which the term was first published,
this document is correct.</p>

<p>Who coined the term, when it was first published, and what is and is not being claimed are recorded
separately in <a href="/PROVENANCE.html">provenance</a>, which is meta information about this specification
rather than part of it. A short introduction to the method is in <a href="/PRIMER.html">the primer</a>.</p>

<p>Changes are recorded in the changelog at the end.</p>

<h2 id="1-terminology">1. Terminology</h2>

<p>The key words MUST, MUST NOT, SHOULD, SHOULD NOT and MAY are to be interpreted as described in
RFC 2119.</p>

<h4 id="dbf">DBF</h4>

<p>The acronym for Defence Before Fix, used as shorthand for this method and for anything built under it. A <a href="#rule">DBF</a> is a <a href="#rule">Rule</a> as this specification defines it; a <a href="#toolchain">DBF</a> is one that <a href="#conform">Conforms</a> to the <a href="#toolchain">Toolchain</a> specification; a <a href="#dbf">DBF</a> project is one that <a href="#conform">Conforms</a> to section 7.</p>

<h4 id="defect">Defect</h4>

<p>Any observed problem worth acting on: a bug, a code review finding, a performance observation, an incident, an inconsistency. The method does not care which.</p>

<h4 id="class">Class</h4>

<p>The pattern, style, idiom or configuration that permitted the <a href="#defect">Defect</a>, expressed generally enough that other occurrences of it are also <a href="#defect">Defects</a> or latent <a href="#defect">Defects</a>.</p>

<h4 id="instance">Instance</h4>

<p>One occurrence of a <a href="#class">Class</a>. The reported <a href="#defect">Defect</a> is one <a href="#instance">Instance</a>; there are usually others.</p>

<h4 id="hazard">Hazard</h4>

<p>The harm a <a href="#class">Class</a> causes, which is <strong>not necessarily a failure</strong>. It may be a failure, but it may equally be error hiding, or something merely sloppy that makes the code harder to reason about or to change safely.</p>

<h4 id="detector">Detector</h4>

<p>A tool that reads code without executing it and reports occurrences of a pattern. A <a href="#detector">Detector</a> is not a <a href="#runner">Runner</a>. What a <a href="#detector">Detector</a> must offer so that a <a href="#practitioner">Practitioner</a> can write, prove, run and resolve a <a href="#rule">Rule</a> in it is specified in the <a href="/DETECTOR-SPEC.html">detector specification</a>.</p>

<h4 id="runner">Runner</h4>

<p>A tool that <strong>executes</strong> code and reports what happened: a test <a href="#runner">Runner</a>, a compilation, a benchmark, a smoke check. A <a href="#runner">Runner</a> answers questions about behaviour at one moment, on the paths it happened to exercise; a <a href="#detector">Detector</a> answers questions about the text of the code, everywhere it exists. Detection under this method MUST come from a <a href="#detector">Detector</a>, for that reason.</p>

<h4 id="rule">Rule</h4>

<p>One pattern definition within a <a href="#detector">Detector</a>.</p>

<h4 id="defence">Defence</h4>

<p>A <strong><a href="#blocking">Blocking</a></strong> <a href="#rule">Rule</a> together with the supporting documentation that clarifies it and clearly signposts best practice. A <a href="#rule">Rule</a> without its documentation is not a <a href="#defence">Defence</a>, and neither is a <a href="#rule">Rule</a> that only warns.</p>

<h4 id="coverage">Coverage</h4>

<p>The accumulated set of <a href="#defence">Defences</a> a project has built. Every <a href="#defect">Defect</a> handled under this method extends it.</p>

<h4 id="remediation-docs">Remediation docs</h4>

<p>The fuller documentation a failure <a href="#message">Message</a> points to, explaining what a <a href="#rule">Rule</a> is about, why it exists and how to fix a violation correctly.</p>

<h4 id="blocking">Blocking</h4>

<p>A <a href="#rule">Rule</a> is <a href="#blocking">Blocking</a> when its firing prevents a change from being accepted by whatever the project uses to accept changes. A report that does not block is a <strong><a href="#warning">Warning</a></strong>, and a <a href="#rule">Rule</a> that only warns is not a <a href="#defence">Defence</a>.</p>

<h4 id="warning">Warning</h4>

<p>A report that does not block. A <a href="#rule">Rule</a> that only warns is not a <a href="#defence">Defence</a>.</p>

<h4 id="message">Message</h4>

<p>The text a <a href="#rule">Rule</a> prints when it fires. Terse, and carries the <a href="#rule">Rule</a>’s <a href="#identifier">Identifier</a>.</p>

<h4 id="identifier">Identifier</h4>

<p>The stable string a <a href="#rule">Rule</a> reports with, by which its <a href="#remediation-docs">Remediation docs</a> are found. Survives renames of the <a href="#rule">Rule</a>.</p>

<h4 id="false-positive">False positive</h4>

<p>A report on code that does not carry the <a href="#hazard">Hazard</a>.</p>

<h4 id="narrowing">Narrowing</h4>

<p>Reducing a <a href="#rule">Rule</a>’s scope so it stops reporting code that does not carry the <a href="#hazard">Hazard</a>. <a href="#narrowing">Narrowing</a> that excludes code which does carry the <a href="#hazard">Hazard</a> is a <a href="#suppression">Suppression</a>.</p>

<h4 id="suppression">Suppression</h4>

<p>Any means of preventing a <a href="#rule">Rule</a> from reporting an <a href="#instance">Instance</a> that carries the <a href="#hazard">Hazard</a>: an ignore comment, an ignore entry, a <a href="#baseline">Baseline</a>, or a <a href="#narrowing">Narrowing</a> that excludes it.</p>

<h4 id="baseline">Baseline</h4>

<p>A recorded set of pre-existing <a href="#instance">Instances</a> a <a href="#rule">Rule</a> is configured not to report. A <a href="#suppression">Suppression</a> in bulk.</p>

<h4 id="exception">Exception</h4>

<p>A recorded <a href="#owner">Owner</a> decision that a specific <a href="#instance">Instance</a> or scope is excluded from a <a href="#defence">Defence</a>. The only legitimate form of <a href="#suppression">Suppression</a>. This document never uses the word in its programming-language sense.</p>

<h4 id="sweep">Sweep</h4>

<p>Running a <a href="#rule">Rule</a> across the whole codebase to enumerate every <a href="#instance">Instance</a>.</p>

<h4 id="fixture">Fixture</h4>

<p>Code written to carry the <a href="#hazard">Hazard</a> deliberately, so a <a href="#rule">Rule</a> can be proven to fire when the codebase has no <a href="#instance">Instance</a> to prove it on.</p>

<h4 id="calibration">Calibration</h4>

<p>A project-level ruling on a threshold or parameter this document leaves open.</p>

<h4 id="practitioner">Practitioner</h4>

<p>Whoever is doing the work of this method on a given <a href="#defect">Defect</a>, human or <a href="#agent">Agent</a>. Decides how the <a href="#defence">Defence</a> is built. Has no authority over <a href="#exception">Exceptions</a>.</p>

<h4 id="agent">Agent</h4>

<p>A <a href="#practitioner">Practitioner</a> that is an automated system. Everything said of a <a href="#practitioner">Practitioner</a> applies to an <a href="#agent">Agent</a>; section 8 exists because of what is additionally true of one.</p>

<h4 id="owner">Owner</h4>

<p>Whoever holds authority over a project’s <a href="#exception">Exceptions</a> and <a href="#calibration">Calibrations</a>. Always a human. The project decides who, and clause 8.7 requires the answer to be findable. Where the project has recorded nobody, the <a href="#owner">Owner</a> is whoever instructed the <a href="#practitioner">Practitioner</a>, and <a href="#escalation">Escalation</a> goes there. One limit applies to that default. The default <a href="#owner">Owner</a> is usually the person under the same deadline as the <a href="#practitioner">Practitioner</a>, so a decision they take under section 4 to keep an <a href="#instance">Instance</a> unfixed, <a href="#suppression">Suppress</a>, <a href="#baseline">Baseline</a> or remove a <a href="#rule">Rule</a> MUST be recorded in the project record with its justification. That record MUST mark the decision as taken by a default <a href="#owner">Owner</a> and state why nobody better placed was reachable, where the next reader can see who decided and why. Such a decision SHOULD be revisited by someone not under that deadline before the next release. A project that wants the separation section 4 describes names an <a href="#owner">Owner</a>; the default exists so work is not blocked, not to certify that a second judgement was applied.</p>

<h4 id="escalation">Escalation</h4>

<p>Referring a decision to the <a href="#owner">Owner</a>, whilst continuing with everything that does not depend on it.</p>

<h4 id="toolchain">Toolchain</h4>

<p>Whatever a project assembles to run its checks through: its <a href="#detector">Detectors</a> and <a href="#runner">Runners</a>, and the parts around them that route, list, record and resolve, from third-party, first-party and project-level parts in any combination. It is measured at the project level. What a <a href="#toolchain">Toolchain</a> must offer beyond what each <a href="#detector">Detector</a> in it offers is specified in the <a href="/TOOLING-SPEC.html">toolchain specification</a>.</p>

<h4 id="conform">Conform</h4>

<p>To satisfy every MUST of the relevant section 7 level, or of the companion specification being claimed. Partial satisfaction is not <a href="#conform">Conformance</a>.</p>

<h2 id="2-when-the-method-applies">2. When the method applies</h2>

<p>Defence Before Fix applies to a <a href="#defect">Defect</a> when that <a href="#defect">Defect</a> can be attributed to a pattern, style,
idiom or configuration that a <a href="#detector">Detector</a> can be made to recognise.</p>

<p>When it can, building the <a href="#defence">Defence</a> is not optional and MUST be attempted before the <a href="#defect">Defect</a> is
fixed. This is the substance of the method rather than a preliminary to it.</p>

<p>When it cannot, the <a href="#defect">Defect</a> is outside the method’s scope and is fixed conventionally. Not every
<a href="#defect">Defect</a> is an <a href="#instance">Instance</a> of a detectable pattern, and forcing a <a href="#rule">Rule</a> where no pattern exists
produces <a href="#detector">Detectors</a> that fire on innocent code, which is worse than having no <a href="#detector">Detector</a>.</p>

<p><strong>Attempt rather than pre-judge.</strong> The <a href="#practitioner">Practitioner</a> MUST attempt to express the <a href="#class">Class</a> as a <a href="#rule">Rule</a>
rather than deciding in advance whether it is expressible. Failing to write a <a href="#rule">Rule</a> that satisfies
clause 3.1’s bounds is itself the evidence that the <a href="#defect">Defect</a> is out of scope, and it is cheaper and
more reliable than a judgement made before trying. The conclusion that no pattern exists MUST be
stated in one sentence, naming at least two independent techniques tried at expressing the
<a href="#rule">Rule</a>, in the vocabulary clause 3.1 uses for a search, and recorded where the project’s
other decisions are enumerable under clause 8.7, not only with the fix. It is the cheapest way
out of the method and the only one that would otherwise leave no trace; enumerating it is how an
<a href="#owner">Owner</a> sees an <a href="#agent">Agent</a> routing around the method, and naming the techniques is what lets
them judge whether the attempt was one.</p>

<p>There are three ways out of the method and they are recorded differently. No pattern exists: the
<a href="#defect">Defect</a> is out of scope under this section, and only the sentence above is recorded. A pattern exists but the
language has no extensible <a href="#detector">Detector</a> and a bespoke one is not practical: a <a href="#toolchain">Toolchain</a> gap
under clause 3.2. A pattern exists, a
<a href="#detector">Detector</a> exists, but the <a href="#toolchain">Toolchain</a> lacks a mechanism the <a href="/TOOLING-SPEC.html">toolchain specification</a> requires: a
mechanism gap under clause 3.2, and the <a href="#rule">Rule</a> is still built. The attempt that separates the first
from the second is complete when the <a href="#practitioner">Practitioner</a> has checked the <a href="#detector">Detectors</a> the project already
runs and the language’s own <a href="#detector">Detector</a> ecosystem for an extension point, and found none.</p>

<h2 id="3-the-method">3. The method</h2>

<p>Six clauses, in order. In brief, before the detail:</p>

<table>
  <thead>
    <tr>
      <th>Clause</th>
      <th>The <a href="#practitioner">Practitioner</a> MUST</th>
      <th>The record shows</th>
      <th>Goes to the <a href="#owner">Owner</a> under section 4</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>3.1</td>
      <td>Name the <a href="#class">Class</a> the <a href="#defect">Defect</a> belongs to, bounded both ways, after an independent search</td>
      <td>The <a href="#class">Class</a>, the <a href="#hazard">Hazard</a> sentence, the two search techniques, the next wider <a href="#rule">Rule</a> not built, whether a <a href="#runner">Runner</a> check pins the reported behaviour</td>
      <td>Whether the next wider <a href="#rule">Rule</a> is built, and whether the <a href="#rule">Rule</a> stays narrower than the search showed</td>
    </tr>
    <tr>
      <td>3.2</td>
      <td>Express the <a href="#class">Class</a> as a <a href="#rule">Rule</a> in a <a href="#detector">Detector</a>, never a test</td>
      <td>The <a href="#rule">Rule</a>, and any <a href="#toolchain">Toolchain</a> gap that stopped a bespoke one</td>
      <td>Whether a <a href="#class">Class</a> that could be defended is left undefended</td>
    </tr>
    <tr>
      <td>3.3</td>
      <td>Make the <a href="#rule">Rule</a> fire, on the <a href="#instance">Instance</a> or a <a href="#fixture">Fixture</a>, in a commit of its own</td>
      <td>The red run, and the sentence behind every <a href="#narrowing">Narrowing</a></td>
      <td>Any exclusion whose sentence cannot be written: that is <a href="#suppression">Suppression</a></td>
    </tr>
    <tr>
      <td>3.4</td>
      <td><a href="#sweep">Sweep</a> the whole codebase, record the count, then fix every <a href="#instance">Instance</a></td>
      <td>The count, corroborated, and what was fixed by hand or by pattern</td>
      <td>Any <a href="#instance">Instance</a> left unfixed, and any <a href="#baseline">Baseline</a></td>
    </tr>
    <tr>
      <td>3.5</td>
      <td>Make the <a href="#rule">Rule</a> permanent and <a href="#blocking">Blocking</a> in the project’s own checks</td>
      <td>The green run through the project’s entry point, and the recorded decision behind any <a href="#suppression">Suppression</a></td>
      <td>Removing or disabling the <a href="#rule">Rule</a>, and any <a href="#suppression">Suppression</a> added to it</td>
    </tr>
    <tr>
      <td>3.6</td>
      <td>Print a terse <a href="#message">Message</a> with a stable <a href="#identifier">Identifier</a> that resolves to documentation versioned with the <a href="#rule">Rule</a></td>
      <td>The <a href="#remediation-docs">Remediation docs</a></td>
      <td>Nothing</td>
    </tr>
  </tbody>
</table>

<p>The fourth column is what an <a href="#agent">Agent</a> or other <a href="#practitioner">Practitioner</a> MUST NOT decide alone, clause by
clause; section 4 states the full list and governs where the two differ.</p>

<p><strong>Three of them turn on a judgement this specification deliberately does not close</strong>: whether code
carries the <a href="#hazard">Hazard</a> (3.1, 3.3), whether a search was comprehensive (3.1, 3.4), and how broadly to
draw the <a href="#class">Class</a> (3.1). Any threshold given here would be calibrated to one codebase, one team and
one generation of <a href="#toolchain">Toolchain</a>, and would be wrong everywhere else.</p>

<p><strong>Those judgements are settled at project level, not in this document.</strong> A project fixes its own
thresholds and parameters, in its configuration or in its <a href="#toolchain">Toolchain</a>’s defaults, and where a case is
genuinely ambiguous it is raised, discussed and written down so the next person inherits the answer
rather than the argument. A project’s accumulated decisions become part of its <a href="#coverage">Coverage</a> in the same
way its <a href="#rule">Rules</a> do.</p>

<p><strong>A project MUST make those decisions discoverable</strong>, by the same means as its <a href="#defence">Defences</a>, so that a
<a href="#practitioner">Practitioner</a> arriving cold can find what has already been settled instead of guessing at it or
re-opening it. The burden is the project’s, not the reader’s: a decision that cannot be found by the
means clause 8.7 requires is, for the <a href="#practitioner">Practitioner</a>’s purposes, a decision the project has not
recorded, and the paragraph below applies. The search is therefore bounded: enumerate the project’s
<a href="#defence">Defences</a> as clause 8.5 requires them to be enumerable, and whatever is reachable from there is the
record. Nothing further is owed before concluding that nothing is recorded.</p>

<p><strong>Where the project has recorded nothing, the <a href="#practitioner">Practitioner</a> is not blocked.</strong> Proceed on the
examples below, <strong>state the <a href="#calibration">Calibration</a> assumed</strong>, and record it with the <a href="#remediation-docs">Remediation docs</a>
for the <a href="#defence">Defence</a> being built, which clause 3.6 already requires to exist, to ship with the project
and to be reachable by a stable <a href="#identifier">Identifier</a>. That statement becomes the project’s first recorded
decision on the point, and the next <a href="#practitioner">Practitioner</a> inherits it.</p>

<p>A project MAY of course keep these decisions somewhere else and say so. <strong>The default above exists
so that a <a href="#practitioner">Practitioner</a> who finds no project convention still has one</strong>, because “the project
decides” and “the project has decided nothing” would otherwise combine into a deadlock. What is
never acceptable is assuming silently, since a <a href="#calibration">Calibration</a> nobody knows was chosen cannot be
corrected.</p>

<p>Worked examples of the three, to calibrate against:</p>

<table>
  <thead>
    <tr>
      <th>Judgement</th>
      <th>Too little</th>
      <th>Too much</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><strong><a href="#hazard">Hazard</a></strong></td>
      <td>Only counting things that crash. Error hiding and code nobody can safely change are <a href="#hazard">Hazards</a> too.</td>
      <td>Counting every stylistic preference, so the <a href="#rule">Rule</a> defends taste rather than the codebase.</td>
    </tr>
    <tr>
      <td><strong>Comprehensive</strong></td>
      <td>One text search for the exact token from the reported <a href="#defect">Defect</a>, then stopping.</td>
      <td>Auditing the whole system by hand before writing a <a href="#rule">Rule</a> at all.</td>
    </tr>
    <tr>
      <td><strong><a href="#class">Class</a></strong></td>
      <td>A <a href="#rule">Rule</a> matching the variable name in the original bug report.</td>
      <td>A <a href="#rule">Rule</a> matching every use of the language feature the bug happened to involve.</td>
    </tr>
  </tbody>
</table>

<h3 id="31-attribute-the-defect-to-a-class">3.1 Attribute the defect to a class</h3>

<p>The <a href="#practitioner">Practitioner</a> MUST first establish what <a href="#class">Class</a> the <a href="#defect">Defect</a> belongs to. The question is not
“what went wrong here” but “what kind of thing is this an <a href="#instance">Instance</a> of”.</p>

<p><strong>In order, this clause is five steps</strong>, each detailed below:</p>

<ol>
  <li>Name the <a href="#class">Class</a> as a pattern, and write the one sentence that says what <a href="#hazard">Hazard</a> it carries.</li>
  <li>Search the codebase for other <a href="#instance">Instances</a> by at least two independent techniques, chosen and
run <strong>before</strong> the <a href="#rule">Rule</a> is run against the codebase, until the last technique adds nothing.</li>
  <li>Widen the <a href="#class">Class</a> if the search found <a href="#instance">Instances</a> the <a href="#rule">Rule</a> would miss; narrow it if the
<a href="#rule">Rule</a> would match code that does not carry the <a href="#hazard">Hazard</a>.</li>
  <li>Where the <a href="#class">Class</a> stays at the reported <a href="#instance">Instance</a>, name the next wider <a href="#rule">Rule</a> and why it
was not built.</li>
  <li>Record all of that, the five things listed at the end of this clause, before clause 3.2.</li>
</ol>

<p>A worked example. The <a href="#defect">Defect</a>: a currency conversion’s error was caught and its empty result
used as a total. Step 1 names the <a href="#class">Class</a> “a caught error whose call’s result is then used as if
the call had succeeded”, with the <a href="#hazard">Hazard</a> “silent wrong output”. Step 2 searches twice: a text
search for every catch in the codebase, read for whether the result is used afterwards, and a
reading of every caller of the fallible conversion and payment functions; the second finds two
<a href="#instance">Instances</a> the first missed, so a third technique, asking the team which other calls fail
quietly, is run and finds nothing new. Step 3 keeps the <a href="#class">Class</a> as named because the <a href="#rule">Rule</a>
would catch all four. Step 4 names the wider <a href="#rule">Rule</a>, “any caught error that is not rethrown or
reported”, and records that it was not built because most of the codebase’s catches are
legitimate and the <a href="#owner">Owner</a> has not decided whether that is a <a href="#hazard">Hazard</a>. Step 5 writes the four
things down.</p>

<p>A <a href="#defect">Defect</a> usually belongs to several overlapping <a href="#class">Classes</a> at different levels of abstraction, and
choosing between them determines the <a href="#rule">Rule</a>, the <a href="#instance">Instance</a> count and the scope of the remediation.
Two bounds apply, and both are checkable during the work rather than matters of taste:</p>

<ul>
  <li><strong>Lower bound.</strong> If the <a href="#rule">Rule</a> catches only the originating <a href="#instance">Instance</a>, the <a href="#class">Class</a> is probably drawn
too narrowly and MUST be widened, unless the independent search below has found no other
<a href="#instance">Instance</a> and the <a href="#practitioner">Practitioner</a> records that the <a href="#class">Class</a> is genuinely singular today. A
<a href="#rule">Rule</a> that matches exactly one thing is an <a href="#detector">Instance</a> wearing a <a href="#rule">Rule</a>’s clothes.
A <a href="#rule">Rule</a> drawn to the exact value or name found in the <a href="#defect">Defect</a> is the same <a href="#narrowing">Narrowing</a> however
it is spelt, and where it stays because the search found nothing wider, the record MUST also name the next
wider <a href="#rule">Rule</a>, the one with the <a href="#instance">Instance</a>’s distinguishing detail dropped, and state why it was
rejected. That rejection is tested as a <a href="#narrowing">Narrowing</a> is under clause 3.3: a sentence stating why the
<a href="#hazard">Hazard</a> cannot arise in the code the wider <a href="#rule">Rule</a> would add, confirmed by search, with uncertainty
routed upwards under section 4.</li>
  <li><strong>Upper bound.</strong> If the <a href="#rule">Rule</a> matches code that does not carry the <a href="#hazard">Hazard</a>, the <a href="#class">Class</a> is drawn
too broadly and MUST be narrowed. <a href="#false-positive">False positives</a> destroy a <a href="#defence">Defence</a>’s credibility faster than a
missing <a href="#rule">Rule</a> does. One report on code that does not carry the <a href="#hazard">Hazard</a> is enough; there is no
tolerated rate.</li>
</ul>

<p><strong>Both bounds measure breadth within one level; the level itself is a separate question, answered
against the report.</strong>
Where the <a href="#defect">Defect</a> was reported as a behaviour, a check that reported clean having run nothing, a
process that stopped before its work, the <a href="#class">Class</a> a <a href="#detector">Detector</a> can read usually sits one level
below that <a href="#hazard">Hazard</a>: the mechanism of this <a href="#instance">Instance</a>, not the failure the report opens with.
That <a href="#class">Class</a> is the right one to build, because no <a href="#detector">Detector</a> reads “this check still produces
its signal”. It is not the whole answer. The record MUST state whether the reported behaviour is also
pinned by a check a <a href="#runner">Runner</a> executes, under section 6, either one added with this remediation or
an existing one named, and where neither is offered, why not. A <a href="#class">Class</a> drawn at the mechanism with
the behaviour left unpinned defends the <a href="#instance">Instance</a> thoroughly and the report not at all.</p>

<p><strong>Resolve the lower bound by searching independently, not by judgement.</strong> The <a href="#rule">Rule</a> is not the only
way to find <a href="#instance">Instances</a>, and it is the least trustworthy one whilst it is still unproven. Search for
other <a href="#instance">Instances</a> by other means, whether that is a text search, reading the code, or asking someone
who knows the system, and, once the <a href="#rule">Rule</a> exists under clause 3.2, confirm it catches what those
searches found. That confirmation is not one of the techniques.</p>

<p>That search MUST be a comprehensive one, carried out by a person, model or <a href="#agent">Agent</a> competent to
carry it out. No fixed technique is prescribed, because what is comprehensive depends entirely on
the <a href="#class">Class</a> and the codebase. What is not acceptable is a cursory look that exists to discharge the
requirement, since this search is what the single-<a href="#instance">Instance</a> conclusion and the <a href="#sweep">Sweep</a> count both
rest on, and a bad search validates a bad <a href="#rule">Rule</a> silently.</p>

<p><strong>The stopping criterion is saturation, not effort.</strong> Use at least two independent techniques, and
the search is complete when the last technique added found nothing the earlier ones had missed. A
second technique that turns up new <a href="#instance">Instances</a> means a third is owed; a second that turns up nothing
new means the search is done. That is a test the <a href="#practitioner">Practitioner</a> can apply and record without a
threshold, and it is the standard clause 3.4 refers back to.</p>

<p>Two techniques are independent when they would miss different things. A text search for the token
and a reading of the code paths that consume the value are independent; two text searches for two
spellings of the same token are one technique; asking someone who knows the system is a third. For a
<a href="#class">Class</a> defined by how code is spelt, writing the idiom the other ways it is commonly spelt and searching
the codebase for each of them is a technique, because a search for one spelling misses every other;
whether the <a href="#rule">Rule</a> catches those spellings is the confirmation step, not the technique. The
<a href="#rule">Rule</a> itself is never one of the techniques, because the search exists to check the <a href="#rule">Rule</a>.
A clean run of the <a href="#rule">Rule</a> is therefore not evidence of saturation either: “the <a href="#rule">Rule</a> found nothing
the reading had missed” is one technique and a <a href="#rule">Rule</a> run, not two techniques, and the stopping
criterion cannot be applied to it.
The record MUST name both techniques, chosen before the <a href="#rule">Rule</a> is run against the codebase, and
MUST state what each found that the other could not have checked. A technique counts only if it does
not invoke the <a href="#detector">Detector</a> under proof in any configuration and would exist unchanged had the
<a href="#rule">Rule</a> never been written; a <a href="#sweep">Sweep</a>-shaped run of the <a href="#rule">Rule</a>, however comprehensive it looks, is
never one of the two. Where a later technique shows that two earlier checks shared a blind spot, they
were one technique, and a third is owed.</p>

<p>That turns the single-<a href="#instance">Instance</a> case into something checkable rather than a matter of taste. If an
independent search finds <a href="#instance">Instances</a> the <a href="#rule">Rule</a> missed, the <a href="#class">Class</a> was drawn too narrowly and the <a href="#rule">Rule</a>
MUST be widened until it catches them. Fixing those <a href="#instance">Instances</a> by hand does not discharge the
widening: they are still <a href="#instance">Instances</a> the <a href="#rule">Rule</a> missed, and where the <a href="#practitioner">Practitioner</a> leaves the
<a href="#rule">Rule</a> unwidened because the wider check is harder to build without <a href="#false-positive">False positives</a>, that is
the <a href="#owner">Owner</a>’s decision under section 4 and is recorded as one. If an independent search finds nothing
the <a href="#rule">Rule</a> did not already have, then one <a href="#instance">Instance</a> is a reasonable conclusion rather than an
assumption, and the <a href="#rule">Rule</a> is correct as written.</p>

<p><strong>What this clause leaves on the record</strong>, before clause 3.2 begins: the <a href="#class">Class</a>, spelt as a
pattern; the <a href="#hazard">Hazard</a> sentence; the two search techniques and what each found; the next wider
<a href="#rule">Rule</a> that was not built, with the reason; and, where the <a href="#defect">Defect</a> was reported as a behaviour,
whether a check a <a href="#runner">Runner</a> executes pins that behaviour, or why not. A record missing any of the
five has not finished this clause.</p>

<p><strong>Why</strong>: the <a href="#class">Class</a> is the unit of work. Everything downstream operates on it, so an error here
wastes all the effort that follows.</p>

<h3 id="32-build-the-net">3.2 Build the net</h3>

<p>The <a href="#practitioner">Practitioner</a> MUST express the <a href="#class">Class</a> as a <a href="#rule">Rule</a> in a <a href="#detector">Detector</a>, and the <a href="#detector">Detector</a> MUST read code
rather than execute it.</p>

<p>A test MUST NOT serve as the <a href="#detector">Detector</a>. A test proves that one input produces one wrong output; a
<a href="#rule">Rule</a> finds the pattern wherever it occurs, including in code nobody thought to test.</p>

<p>Where an off-the-shelf <a href="#rule">Rule</a> or a tightening of existing configuration genuinely detects the
<a href="#class">Class</a>, using it <a href="#conform">Conforms</a>. However, a <a href="#toolchain">Toolchain</a> that supports only its own built-in <a href="#rule">Rules</a> cannot
support the method in general, so <strong>a <a href="#toolchain">Conforming</a> MUST allow bespoke custom <a href="#rule">Rules</a></strong>.
The <a href="#rule">Rules</a> that matter most are tightly coupled to the project and carry project-specific
knowledge, both in the pattern they match and, importantly, in the <a href="#message">Message</a> they emit.</p>

<p>Any tool that reads code and reports pattern matches qualifies, whatever its category. Static
<a href="#detector">Detectors</a> such as PHPStan, ESLint, mypy and Clippy are the usual instruments; so are custom AST
walkers, architectural fitness checks, and <a href="#detector">Detectors</a> that inspect a change before it lands. These
are examples rather than a permitted list, and a <a href="#detector">Detector</a> is not disqualified for being unfamiliar.</p>

<p>Where the affected language has no extensible <a href="#detector">Detector</a> available, the <a href="#practitioner">Practitioner</a> MAY build a
bespoke one: a program of the project’s own that reads code and reports matches of the <a href="#class">Class</a>, run
through the project’s own entry point like any other <a href="#detector">Detector</a>. This is a last resort rather than
a default, because a <a href="#detector">Detector</a> the ecosystem maintains is cheaper to keep and easier for the next
<a href="#practitioner">Practitioner</a> to find, but a bespoke <a href="#detector">Detector</a> is far better than none: the <a href="#class">Class</a> is defended,
and every clause of this method applies to the bespoke <a href="#detector">Detector</a> unchanged, including the proof in
3.3, the enforcement in 3.5 and the <a href="#identifier">Identifier</a> and documentation in 3.6.</p>

<p>Only where a bespoke <a href="#detector">Detector</a> is not practical either is the <a href="#class">Class</a> undefended. That is a
<strong><a href="#toolchain">Toolchain</a> gap and MUST be recorded as one</strong>, with the reason a bespoke <a href="#detector">Detector</a> was not
practical, rather than treated as the <a href="#defect">Defect</a> being out of scope. The distinction matters, because
the first is a decision somebody can revisit and the second quietly disappears.</p>

<p>A gap is recorded in the project record where the <a href="#toolchain">Toolchain</a> defines one, as the
<a href="/TOOLING-SPEC.html">toolchain specification</a> requires it to; only where it does not is the location the project’s choice, and
then the only requirement is that somebody deciding what to invest in the <a href="#toolchain">Toolchain</a> would find
it. A gap recorded where nobody looks has been forgotten with extra steps.</p>

<p>There are two kinds of gap and they lead to different work. The language having no extensible
<a href="#detector">Detector</a> and no practical bespoke one is the gap above, and the <a href="#class">Class</a> waits for one. The project’s <a href="#toolchain">Toolchain</a>
lacking a mechanism the <a href="/TOOLING-SPEC.html">toolchain specification</a> requires, such as a proving harness or an
<a href="#identifier">Identifier</a> resolver, is a mechanism gap, and it does not put the <a href="#class">Class</a> out of reach: the
<a href="#practitioner">Practitioner</a> builds the <a href="#rule">Rule</a>, uses the substitutes clauses 3.3 and 3.6 already allow, and records
the mechanism gap alongside it. A mechanism gap that is also a breach of the <a href="/TOOLING-SPEC.html">toolchain specification</a>’s
own obligations, such as a <a href="#toolchain">Toolchain</a> that cannot run its <a href="#defence">Defences</a> on its own source, is recorded
against the <a href="#toolchain">Toolchain</a>’s claim under section 7 as well, because that is the fact its <a href="#owner">Owner</a> needs.
Where that record lives is the <a href="#toolchain">Toolchain</a>’s own <a href="#conform">Conformance</a> declaration, under the
<a href="/TOOLING-SPEC.html">toolchain specification</a>’s clause 9.2, or the <a href="/DETECTOR-SPEC.html">detector specification</a>’s clause 8.1 for a <a href="#detector">Detector</a>; a <a href="#practitioner">Practitioner</a> who cannot write there reports it by the channel
section 4 names, as a blocked decision.</p>

<p><strong>What happens next is not waiting.</strong> Once the gap is recorded, the <a href="#defect">Defect</a> is fixed conventionally
under section 2, with its reproduction test, and the work moves on. What the record changes is the
<a href="#class">Class</a>, not the <a href="#defect">Defect</a>: the <a href="#class">Class</a> is known to be undefended, and the next person to see an
<a href="#instance">Instance</a> of it finds a decision to revisit rather than nothing.</p>

<p><strong>Why</strong>: this clause is what distinguishes the method. Detection happens at the level of the
<a href="#class">Class</a>, using an artefact that can be pointed at the whole codebase, and it happens before
anything is fixed.</p>

<h3 id="33-prove-the-net-by-making-the-rule-fire">3.3 Prove the net by making the rule fire</h3>

<p>A new <a href="#rule">Rule</a> MUST be proven to fire before it is trusted. It is never the goal to write a <a href="#rule">Rule</a> and
be instantly green.</p>

<p><strong>This clause has three parts.</strong> Part A: the proof, a red run kept as a commit of its own. Part B:
<a href="#narrowing">Narrowing</a>, which is the <a href="#practitioner">Practitioner</a>’s to decide only when they can write down why the
<a href="#hazard">Hazard</a> cannot arise in what is excluded, and is <a href="#suppression">Suppression</a> for the <a href="#owner">Owner</a> otherwise. Part C:
proving a <a href="#rule">Rule</a> when the pattern is absent from the codebase, and what a <a href="#rule">Rule</a>’s own code owes.</p>

<p><strong>Part A: the proof.</strong></p>

<p><strong>Proving and sweeping are two questions, not necessarily two runs.</strong> Proving asks “does this <a href="#rule">Rule</a>
work at all”, and the answer is pass or fail. Sweeping, in clause 3.4, asks “how much of this <a href="#class">Class</a>
is present”, and the answer is a count. A single execution of the <a href="#rule">Rule</a> answers both, and no second
run is required. What matters is that the two answers are not confused with one another: a large
count does not make a <a href="#rule">Rule</a> more proven, and a <a href="#rule">Rule</a> that fired does not tell you the <a href="#sweep">Sweep</a> is
complete.</p>

<p><strong>The proof MUST survive as a commit of its own.</strong> The <a href="#defence">Defence</a> is committed with the originating
<a href="#instance">Instance</a> still present, and the fix is committed after it. That first commit is the red run: it is
what a reviewer under section 7 checks out to reproduce the proof, and it is the only record that the
<a href="#rule">Rule</a> fired on real code rather than on a <a href="#fixture">Fixture</a> alone. Where the <a href="#defence">Defence</a> and the fix share a
commit, the red run can only be reconstructed by hand-reverting lines the reviewer has to guess at,
and the proof rests on that guess.
Surviving as a commit means a fresh checkout of that commit, followed by the project’s own declared
setup, reproduces the proof. Dependency installation and generation driven by files the commit does
carry, a manifest, a lockfile, are that setup. Anything else the proof depends on that version control
does not carry, an empty directory, an ignored file, an artefact placed by hand, is state the commit
does not contain, and a reviewer under section 7
reproduces from a fresh checkout rather than from the <a href="#practitioner">Practitioner</a>’s working tree, so a
proof that passes only there does not survive.
Both commits MUST remain individually reachable in the history the reviewer inspects. A merge that
flattens them into one destroys the proof, so a project whose merge policy does that MUST keep the
<a href="#defence">Defence</a> commit reachable by another recorded reference, a tag or the retained branch, or MUST NOT
claim the remediation <a href="#conform">Conforms</a>. How the project merges is its own business under section 8;
what must survive the merge is not.</p>

<p><strong>Part B: <a href="#narrowing">Narrowing</a>.</strong></p>

<p><strong>The decision comes first, and it turns on one sentence.</strong> Can the <a href="#practitioner">Practitioner</a> write down why
the <a href="#hazard">Hazard</a> cannot arise in the code being excluded? If they can, the exclusion is a <a href="#narrowing">Narrowing</a>,
it is theirs to make, and they record that sentence. If they cannot, or are not sure, the exclusion
is a <a href="#suppression">Suppression</a> and belongs to the <a href="#owner">Owner</a> under section 4. Doubt is <a href="#suppression">Suppression</a>. The test is
the <a href="#hazard">Hazard</a>, never the count. Two exclusions of the same <a href="#rule">Rule</a> show the difference:</p>

<table>
  <thead>
    <tr>
      <th>Excluded code</th>
      <th>The sentence</th>
      <th>Which it is</th>
      <th>Who decides</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>A catch in test helpers that asserts on the caught error and returns it</td>
      <td>“The result is the error itself, so it cannot be used as if the call had succeeded”</td>
      <td><a href="#narrowing">Narrowing</a></td>
      <td><a href="#practitioner">Practitioner</a></td>
    </tr>
    <tr>
      <td>A catch in a nightly export, excluded because the export “hardly matters”</td>
      <td>Cannot be written: the caught result is still used, so the <a href="#hazard">Hazard</a> is present</td>
      <td><a href="#suppression">Suppression</a>, referred under section 4</td>
      <td><a href="#owner">Owner</a></td>
    </tr>
  </tbody>
</table>

<p>The sequence is: write the sentence; confirm it by search, the way clause 3.1’s search confirms
the <a href="#class">Class</a>, so that the excluded code is looked at and not assumed; record both. A sentence
that cannot be confirmed is a sentence that cannot be written, and the exclusion goes to the
<a href="#owner">Owner</a>. Ask, for every exclusion, which row it sits in:</p>

<ul>
  <li>If the excluded code <strong>carries the <a href="#hazard">Hazard</a></strong>, the exclusion is <a href="#suppression">Suppression</a> and is forbidden to
the <a href="#practitioner">Practitioner</a>, however many or few <a href="#instance">Instances</a> it removes.</li>
  <li>If the excluded code <strong>does not carry the <a href="#hazard">Hazard</a></strong>, the exclusion is precision and is required
by clause 3.1’s upper bound.</li>
  <li>If the <a href="#practitioner">Practitioner</a> <strong>is not sure which</strong>, the exclusion is <a href="#suppression">Suppression</a>, and it goes to the
<a href="#owner">Owner</a> with the doubt stated.</li>
</ul>

<p>A <a href="#rule">Rule</a> MUST NOT be narrowed because its <a href="#instance">Instance</a> count is uncomfortably high. A high count is a
finding about the codebase, not a <a href="#defect">Defect</a> in the <a href="#rule">Rule</a>. <strong>Firing on more than the originating
<a href="#defect">Defect</a> is success.</strong> A <a href="#rule">Rule</a> that catches the reported <a href="#instance">Instance</a> and forty-nine others has done
exactly what it was built to do, and the forty-nine are the reason the method exists.</p>

<p><strong>Then the proof, which runs the other way round, and both ways.</strong> Where the change under proof is
that the <a href="#rule">Rule</a> should stop firing on code that does not carry the <a href="#hazard">Hazard</a>, three things are shown:
the <a href="#rule">Rule</a> firing on that code before the change, from a commit still reachable in history and to the
same standard as a new <a href="#rule">Rule</a>’s red run; the <a href="#rule">Rule</a> not firing on it afterwards; and a retained
<a href="#fixture">Fixture</a> on which it still fires afterwards, the case that motivated the <a href="#rule">Rule</a>. Where the narrower
shape was chosen from the outset and no wider <a href="#rule">Rule</a> was ever built, the before state is a <a href="#fixture">Fixture</a>
of the wider pattern the <a href="#rule">Rule</a> does not catch, retained as the record of what was left out.</p>

<p>A <a href="#narrowing">Narrowing</a> reduces the <a href="#practitioner">Practitioner</a>’s own work, which is why it is not left to self-report
alone. The sentence is recorded with the <a href="#narrowing">Narrowing</a> as part of the <a href="#rule">Rule</a>’s <a href="#remediation-docs">Remediation docs</a>;
the search confirms it. Code a <a href="#narrowing">Narrowing</a> excludes MUST be searched to the standard of clause
3.1, and an <a href="#instance">Instance</a> that search finds there disproves the sentence and reverses the
<a href="#narrowing">Narrowing</a>. Every <a href="#narrowing">Narrowing</a> MUST be enumerable by the
same means as the project’s <a href="#exception">Exceptions</a>, so the <a href="#owner">Owner</a> sees them in one place, and a
<a href="#narrowing">Narrowing</a> that excludes more code than the <a href="#rule">Rule</a> still covers MUST be reported to the <a href="#owner">Owner</a>
as if it were a <a href="#suppression">Suppression</a>. The sentence remains the test of whether an exclusion is honest;
the search and the listing are what make a fluent dishonest one visible.
Where the <a href="#toolchain">Toolchain</a>’s own listing shows the <a href="#narrowing">Narrowing</a> with its sentence, that listing is
the record, and no separate prose is owed for it.</p>

<p>The sentence may turn out to be wrong. The method does not require the <a href="#practitioner">Practitioner</a> to be
infallible; it requires the reasoning to be written down where the next reader, or the next
<a href="#defect">Defect</a>, can test it. A <a href="#narrowing">Narrowing</a> with a recorded reason is correctable. One without is
indistinguishable from a <a href="#suppression">Suppression</a>, which is why the sentence is the test and a percentage is
not.</p>

<p><strong>Part C: proving when the pattern is absent, and the <a href="#rule">Rule</a>’s own code.</strong></p>

<p><strong>Where the pattern is present, prove the <a href="#rule">Rule</a> against it.</strong> At minimum the <a href="#rule">Rule</a> MUST detect the
originating <a href="#defect">Defect</a>. Where the <a href="#defect">Defect</a> was found in code review rather than in production, the
pattern exists in the branch under review, so the <a href="#rule">Rule</a> is written and proven there before the fix
lands.</p>

<p><strong>Where the pattern is genuinely absent, prove the <a href="#rule">Rule</a> another way.</strong> A <a href="#rule">Rule</a> may legitimately be
green against the codebase: the branch that carried the pattern may have been rejected and
orphaned, the <a href="#instance">Instance</a> may already have been fixed, or the <a href="#rule">Rule</a> may be a purely proactive <a href="#defence">Defence</a>
against a pattern that might occur but does not yet. In each of these cases the <a href="#rule">Rule</a> MUST still be
proven, using <a href="#fixture">Fixture</a> code that demonstrates the pattern the <a href="#rule">Rule</a> is meant to catch. That <a href="#fixture">Fixture</a>
SHOULD be kept as the <a href="#rule">Rule</a>’s own test rather than deleted, so the proof re-runs whenever the <a href="#rule">Rule</a> runs
instead of expiring the moment it succeeds.</p>

<p><strong>A green run proves nothing unless the <a href="#rule">Rule</a> was loaded.</strong> Before treating any run as evidence,
confirm the <a href="#rule">Rule</a> was active in it, by enumerating the <a href="#defence">Defences</a> that run was configured with or by
seeing it fire on a <a href="#fixture">Fixture</a> in the same run. A <a href="#toolchain">Toolchain</a> analysing its own source with a
configuration that omits its own bundled <a href="#rule">Rules</a> reports clean on every one of them, and both the
<a href="#practitioner">Practitioner</a> and a reviewer have taken that for a pass.</p>

<p><strong>A <a href="#rule">Rule</a> about how code is written may match its own source.</strong> A <a href="#rule">Rule</a> that forbids a construct in
<a href="#rule">Rule</a> code will contain that construct, because it has to look for it. That match is not an
<a href="#instance">Instance</a>: the construct there is the <a href="#rule">Rule</a>’s subject, not its use, so the <a href="#hazard">Hazard</a> is absent and
clause 3.1’s upper bound requires the <a href="#narrowing">Narrowing</a>. Exclude it as precisely as possible, write the
sentence, and keep a test that proves the exclusion reaches nothing else.</p>

<p><strong><a href="#rule">Rules</a> are software, and SHOULD be built test-first like any other software.</strong> How practical
that is varies considerably between <a href="#detector">Detectors</a>, some of which offer purpose-built <a href="#rule">Rule</a>-testing harnesses
and some of which offer nothing, so this is a best-effort requirement rather than an absolute one.
Where a <a href="#detector">Detector</a> makes <a href="#fixture">Fixture</a>-based testing impractical, an acceptance or smoke check that
demonstrates the <a href="#rule">Rule</a> firing is an acceptable substitute.</p>

<p><a href="#fixture">Fixtures</a> are part of the <a href="#rule">Rule</a> and are versioned with it, under the co-location requirement in
clause 3.6. A <a href="#fixture">Fixture</a> is input to the <a href="#rule">Rule</a>’s test, not part of the code the <a href="#defence">Defence</a> enforces:
the <a href="#sweep">Sweep</a> excludes it under clause 3.4, and the enforced run does not report it, so there is no
circularity in a <a href="#fixture">Fixture</a> that deliberately fails the <a href="#rule">Rule</a> it proves. Writing the <a href="#fixture">Fixture</a> is the <a href="#practitioner">Practitioner</a>’s own decision under section 4, not something
to refer upwards.</p>

<p>Where they sit within the repository is the project’s business. <strong>Absent a convention, put them
where the project’s existing tests live, and where there are none, alongside the <a href="#rule">Rule</a></strong> - then say
which was chosen, as with any other <a href="#calibration">Calibration</a> under section 3.</p>

<p><strong>Why</strong>: proving is the <a href="#rule">Rule</a>’s own validation. A <a href="#rule">Rule</a> that does not catch the <a href="#instance">Instance</a> that
prompted it does not detect the <a href="#class">Class</a> it claims to, and shipping it would add a <a href="#defence">Defence</a> that defends nothing
whilst defending nothing. This is the clause most easily skipped, because a green run feels like
success and looks like a clean codebase.</p>

<h3 id="34-sweep-the-codebase-then-fix-every-instance">3.4 Sweep the codebase, then fix every instance</h3>

<p>The <a href="#practitioner">Practitioner</a> MUST run the <a href="#rule">Rule</a> across the entire codebase and record the total <a href="#instance">Instance</a>
count before fixing anything, and MUST then fix every <a href="#instance">Instance</a> found rather than only the one
that was reported.</p>

<p><strong>“Entire codebase” means everywhere the pattern can occur, and nowhere it cannot.</strong> For most
<a href="#class">Classes</a> that is a single language, because the pattern is a feature of that language, and sweeping
unrelated languages would be theatre. What the clause forbids is stopping at the package,
component or service that happened to report the <a href="#defect">Defect</a> when the pattern plainly reaches further.
The question is where the pattern can exist, never where the bug was found.</p>

<p>Whether vendored dependencies, generated code and test <a href="#fixture">Fixtures</a> are in scope is the project’s
decision, and it SHOULD be a recorded one rather than an implicit one, because a <a href="#class">Class</a> that is
excluded silently is indistinguishable from a <a href="#class">Class</a> that was never swept. <strong>Where a project has
recorded no such decision, <a href="#sweep">Sweep</a> all first-party source in every language where the pattern can
occur, and exclude generated code and vendored dependencies</strong>, then record that as the decision.
First-party source is code the project maintains in its own repository; generated code is what a
build step produces from other source; vendored code is a dependency copied in rather than
installed. Modified vendored code is first-party, because the project now maintains it. The two
exclusions are not a judgement that such code carries no <a href="#hazard">Hazard</a>. Unmodified vendored code is
somebody else’s project, and an <a href="#instance">Instance</a> found in it is reported upstream rather than fixed
in place; generated code carries an <a href="#instance">Instance</a> only because its generator does, and the generator
is the first-party source the <a href="#sweep">Sweep</a> covers. An <a href="#instance">Instance</a> the <a href="#practitioner">Practitioner</a> notices in either
is recorded as a known <a href="#instance">Instance</a> under section 4, not ignored.
A <a href="#rule">Rule</a>’s own <a href="#fixture">Fixtures</a> under clause 3.3 are never <a href="#instance">Instances</a>: they carry the pattern
deliberately, as the proof, and the <a href="#sweep">Sweep</a> count excludes them.</p>

<p><strong>The count MUST be corroborated independently</strong>, to the standard set out in clause 3.1: a
comprehensive search by someone competent to make it. A <a href="#rule">Rule</a> that is too narrow produces a small
count and a clean-looking <a href="#sweep">Sweep</a>, and nothing inside the <a href="#rule">Rule</a> can reveal that.</p>

<p>Where the independent search finds <a href="#instance">Instances</a> the <a href="#rule">Rule</a> did not, <strong>clause 3.1 governs: the <a href="#rule">Rule</a> was
drawn too narrowly and MUST be widened until it catches them.</strong> The two are not permitted to
disagree, and the search is the authority, not the <a href="#rule">Rule</a>.</p>

<p><strong>Each fix MUST address the <a href="#hazard">Hazard</a> rather than the <a href="#rule">Rule</a>.</strong> Throw on the absent case, or propagate
the absence explicitly so the caller decides: whatever the correct behaviour turns out to be. What is
forbidden is a change that turns the <a href="#rule">Rule</a> green whilst leaving the failure mode intact, and
suppressing the <a href="#rule">Rule</a> at the call site, which is not a fix at all.</p>

<p><strong>Supplying a default is usually the <a href="#hazard">Hazard</a> in another form</strong>, not a fix. A default makes the absent
case look present, and the failure moves downstream to where nobody expects it and nothing names it.
A default is a fix only where absence is a legitimate state whose meaning the code defines, and then
the default states that meaning rather than filling the gap with a placeholder. This is the same
test as clause 3.3’s <a href="#narrowing">Narrowing</a> sentence and carries the same discipline: the <a href="#practitioner">Practitioner</a>
MUST write down, with the fix, what the absence means and why the default is that meaning, and
where the sentence cannot be written the absence is an error and MUST be treated as one.</p>

<p><strong>Repetition is not the problem.</strong> Where every <a href="#instance">Instance</a> genuinely has the same correct answer,
applying that answer to all of them is right, and the fact that it looks mechanical is not an
objection. The requirement is that each <a href="#instance">Instance</a> was actually examined and the same answer was
genuinely correct for it, rather than assumed correct because it was correct elsewhere. Where one
change is applied across many <a href="#instance">Instances</a>, the <a href="#practitioner">Practitioner</a> MUST record which were examined
individually and which received the change by pattern, and MUST examine a sample of the latter
large enough, given how much the surrounding code varies, that a wrong answer there would have
been seen. “All examined” with nothing behind it is a claim, not a record.</p>

<p><strong>Fix them all.</strong> There is no count at which a <a href="#practitioner">Practitioner</a> stops fixing. A <a href="#practitioner">Practitioner</a>
stops only when the next <a href="#instance">Instance</a> needs a decision they lack the authority to make, and section 4
says which decisions those are; a hundred <a href="#instance">Instances</a>, or a thousand, is work rather than a
decision. A <a href="#baseline">Baseline</a> of the existing <a href="#instance">Instances</a>, so that the <a href="#rule">Rule</a> blocks only new ones, MAY
be adopted where fixing every <a href="#instance">Instance</a> is genuinely unfeasible, and the scale at which that
becomes true is tremendous. <strong>No threshold is given here deliberately.</strong> Any figure would be
calibrated to the <a href="#toolchain">Toolchain</a> of the moment rather than to anything durable, and the honest measure is
the size of the change rather than the hours it would take, since the hours depend entirely on what
is doing the work.</p>

<p><strong>Under AI-assisted development a <a href="#baseline">Baseline</a> is almost never the right answer.</strong> The historical case
for baselining was the cost of human hours, and that cost has largely collapsed: an <a href="#agent">Agent</a> can work
through hundreds of <a href="#instance">Instances</a> at a price that made a <a href="#baseline">Baseline</a> unavoidable a few years ago. Reaching
for one now is usually a habit rather than a judgement. This specification stops short of
forbidding <a href="#baseline">Baselines</a> outright, and only just.</p>

<p>Adopting one is a decision for whoever owns the codebase, taken after fixing has genuinely been
attempted rather than on the strength of the <a href="#instance">Instance</a> count alone. “Attempted” is the
<a href="#owner">Owner</a>’s judgement on the <a href="#practitioner">Practitioner</a>’s report, not a threshold the <a href="#practitioner">Practitioner</a> applies:
the <a href="#practitioner">Practitioner</a> fixes until stopped by a decision they cannot make, and reports what was fixed and
what remains. That report MUST state the count fixed, the count remaining, what stopped the fixing,
and what would have to be tried next, so the <a href="#owner">Owner</a> judges a record and not an adjective. <strong>A
<a href="#practitioner">Practitioner</a> executing this method does not have that authority</strong> - see section 4. A baselined project SHOULD reduce the
<a href="#baseline">Baseline</a> over time and MUST NOT allow it to grow.</p>

<p><strong>Why</strong>: consistency across a codebase is fundamental, and the <a href="#sweep">Sweep</a> is where this method
delivers most of its value. In the published worked example the reported <a href="#defect">Defect</a> was one of
twenty-three; the other twenty-two were bugs waiting to surface in different contexts, reported
by different customers, at different times. A project that knows about twenty-three <a href="#instance">Instances</a>
and fixes one has produced a documented list of <a href="#defect">Defects</a> it has chosen to keep.</p>

<h3 id="35-enforce-permanently-and-block">3.5 Enforce permanently, and block</h3>

<p>The <a href="#rule">Rule</a> MUST become a permanent part of the project’s quality checks, and it MUST <strong>fail</strong> rather
than warn. Three things must hold for this clause to be met: the <a href="#rule">Rule</a> is in the checks the
project runs to accept changes; it fails rather than warns; and every <a href="#suppression">Suppression</a> it carries is
covered by a recorded decision. A <a href="#rule">Rule</a> that reports a violation without failing does not
<a href="#conform">Conform</a>, and neither does a <a href="#rule">Rule</a> whose green run depends on a <a href="#suppression">Suppression</a> that no recorded
decision covers, since the checks are then passing around the <a href="#instance">Instance</a> rather than enforcing
against it.</p>

<p><strong>Where those checks run, and what the project does when they fail, is out of scope.</strong> Continuous
integration, git hooks, branch policy and release process are the project’s own business; see
section 8. What this clause requires is that the <a href="#defence">Defence</a> is permanent, applies to everyone rather
than to whoever remembers it, and produces a failure rather than a remark.</p>

<p>Removing a <a href="#rule">Rule</a>, or adding a <a href="#suppression">Suppression</a> for an <a href="#instance">Instance</a>, MUST be a recorded decision rather
than a silent edit, and belongs to the <a href="#owner">Owner</a> under section 4, which governs. Where a <a href="#rule">Rule</a>
carries a <a href="#suppression">Suppression</a> that no recorded decision covers, this clause is not followed until the
decision is recorded or the <a href="#suppression">Suppression</a> removed.</p>

<p><strong>Enforcement is demonstrated through the project’s own entry point.</strong> Running the <a href="#detector">Detector</a>
directly shows that the <a href="#rule">Rule</a> can fire; it does not show that the project’s checks will run it.
The <a href="#practitioner">Practitioner</a> MUST run the invocation the project uses to accept changes, over everything
that invocation covers, and see the <a href="#rule">Rule</a> reported there. A <a href="#toolchain">Toolchain</a> that wraps its
<a href="#detector">Detectors</a> in its own command is asking for that command to be used, and a <a href="#practitioner">Practitioner</a>
who bypasses it has proven the <a href="#rule">Rule</a> and not the <a href="#defence">Defence</a>.</p>

<p>For a <a href="/TOOLING-SPEC.html#bundled-defence">Bundled defence</a>, the project whose entry point demonstrates it is any project the
<a href="#toolchain">Toolchain</a> is genuinely installed into, the <a href="/TOOLING-SPEC.html#consuming-project">Consuming project</a> included, since that is
where the <a href="#rule">Rule</a> will be enforced. A mechanism gap that stops the <a href="#toolchain">Toolchain</a> running its own entry
point on its own source moves the demonstration to such a project; it does not weaken it. The
substitutes clause 3.2 allows, for proving under clause 3.3 and for resolution under clause 3.6, do
not extend to this clause: the demonstration here is through an entry point or it is not made. A
project created for the purpose, with the <a href="#toolchain">Toolchain</a> installed into it from the source under
test, is such a project, so a <a href="#toolchain">Toolchain</a> with no consumer yet is not excused.</p>

<p><strong>Why</strong>: the purpose is that the mistakes of the past become structurally impossible to repeat, and
a <a href="#warning">Warning</a> is not structure. A <a href="#warning">Warning</a> is a suggestion, and suggestions decay under deadline
pressure, which is the condition under which the original <a href="#defect">Defect</a> was written.</p>

<h3 id="36-make-the-failure-message-terse-and-point-it-at-real-documentation">3.6 Make the failure message terse, and point it at real documentation</h3>

<p>The failure <a href="#message">Message</a> MUST be terse, and it MUST carry a stable <a href="#identifier">Identifier</a> that resolves to
<a href="#remediation-docs">Remediation docs</a> shipped with the project.</p>

<p>That documentation MUST state three things: what the <a href="#rule">Rule</a> is about, why it exists, and how to
fix a violation correctly using the project’s preferred approach.</p>

<p>A <a href="#practitioner">Practitioner</a> who finds that an existing <a href="#rule">Rule</a>’s printed <a href="#identifier">Identifier</a> does not resolve records
it where clause 3.2’s gaps are recorded, naming the <a href="#rule">Rule</a> and the <a href="#identifier">Identifier</a>, with no <a href="#rule">Rule</a> build of
their own to attach it to. It does not block the remediation. Where the mechanism cannot resolve
<a href="#identifier">Identifiers</a> at all, it is a <a href="#toolchain">Toolchain</a> gap and blocks the <a href="#toolchain">Toolchain</a>’s claim under section 7
until fixed; where the mechanism resolves others and only this <a href="#rule">Rule</a>’s documentation is missing, it is a
fault in that <a href="#rule">Rule</a>’s <a href="#remediation-docs">Remediation docs</a>, owned by whoever wrote the <a href="#rule">Rule</a>, and bears on
the project’s claim rather than the <a href="#toolchain">Toolchain</a>’s.</p>

<p><strong>The split between the two is by job, not by length.</strong> The <a href="#message">Message</a> carries what was detected,
where, and the <a href="#identifier">Identifier</a>; it is read under interruption by somebody trying to get on with
something else. The documentation carries the reasoning and the remedy; it is read once, by
somebody who has decided to understand the <a href="#rule">Rule</a>, and it is maintained as the project’s thinking
develops. Anything that would grow over time belongs in the documentation.</p>

<p><strong>A <a href="#rule">Rule</a> and its documentation are one artefact and MUST be versioned as one.</strong> They live in the
same repository and are committed together, or in a library the project depends on at a clearly
tracked version. A <a href="#rule">Rule</a> carries knowledge specific to the project it defends, and its
documentation is where most of that knowledge actually sits, so anything that lets the two drift
apart destroys the value of both. The same applies to a <a href="#rule">Rule</a>’s <a href="#fixture">Fixtures</a> under clause 3.3.</p>

<p>An external URL <a href="#conform">Conforms</a> only where the project controls it and versions it alongside the <a href="#rule">Rule</a>. A
link into a third-party wiki or a general article does not, because neither can be relied upon to
still describe this <a href="#rule">Rule</a>.</p>

<p><strong>Repository structure is left to the project.</strong> Where these artefacts sit, how they are foldered,
whether <a href="#rule">Rules</a> are vendored or depended upon: none of that is this specification’s business. What
is required is that they move together.</p>

<p>The <a href="#identifier">Identifier</a> MAY be a <a href="#rule">Rule</a> ID resolved by a command, an anchor in a documentation file, or a
URL. It MUST continue to resolve for as long as any released version can emit it, which is a
stronger requirement than surviving the current release. Where a <a href="#rule">Rule</a> is redesigned such that its
meaning materially changes, a new <a href="#identifier">Identifier</a> SHOULD be issued and the old one SHOULD keep
resolving to an explanation of what became of it.</p>

<p>A <a href="#message">Message</a> that names a pattern without leading anywhere does not <a href="#conform">Conform</a>. “Pattern X detected”
teaches nothing.</p>

<p><strong>Why</strong>: the <a href="#message">Message</a> is read at the moment of failure, by someone who wants to get on with their
work, so it has to be short enough to read and specific enough to act on, whilst the reasoning
has to live somewhere it can be maintained and can grow. Splitting it this way keeps the <a href="#message">Message</a>
terse without losing the teaching, and the documentation becomes the project’s accumulated
engineering knowledge rather than a comment nobody revisits. The best custom <a href="#rule">Rules</a> are
opinionated documentation encoded as automation.</p>

<h2 id="4-authority-which-decisions-belong-to-whom">4. Authority: which decisions belong to whom</h2>

<p>The method is frequently executed by somebody who does not own the codebase, including an <a href="#agent">Agent</a>
acting under instruction. This section states which decisions that person takes alone and which
they do not.</p>

<p><strong>Decisions the <a href="#practitioner">Practitioner</a> takes alone</strong>, without seeking approval:</p>

<ul>
  <li>What <a href="#class">Class</a> the <a href="#defect">Defect</a> belongs to, and where its bounds sit (clause 3.1).</li>
  <li>Which <a href="#detector">Detector</a> to use and how to express the <a href="#rule">Rule</a> in it (clause 3.2).</li>
  <li>How to prove the <a href="#rule">Rule</a>, and what <a href="#fixture">Fixture</a> to prove it against (clause 3.3).</li>
  <li>What the correct behaviour is at each <a href="#instance">Instance</a>, and therefore what each fix should be (clause
3.4).</li>
  <li>The wording of the failure <a href="#message">Message</a> and the <a href="#remediation-docs">Remediation docs</a> (clause 3.6).</li>
</ul>

<p><strong>Decisions that MUST go to whoever owns the codebase:</strong></p>

<ul>
  <li>Adopting a <a href="#baseline">Baseline</a>, in whole or in part (clause 3.4).</li>
  <li>Suppressing an <a href="#instance">Instance</a>, or removing or disabling an existing <a href="#rule">Rule</a> (clauses 3.4 and 3.5).</li>
  <li>Accepting a known <a href="#instance">Instance</a> as unfixed for any reason.</li>
  <li>Deciding that a <a href="#class">Class</a> will not be defended at all, where a <a href="#rule">Rule</a> for it is achievable.</li>
  <li>Leaving unbuilt the next wider <a href="#rule">Rule</a> that clause 3.1 required the <a href="#practitioner">Practitioner</a> to name, where
it was rejected because no <a href="#instance">Instance</a> of it exists today rather than because the <a href="#hazard">Hazard</a> cannot
arise there. The absence of an <a href="#instance">Instance</a> is the <a href="#practitioner">Practitioner</a>’s reason not to widen unasked; it
is not authority to exclude the extension, and recording it as a known gap does not change whose
decision it is. This does not reopen a <a href="#class">Class</a> whose bounds a <a href="#hazard">Hazard</a> sentence has settled, and
it reaches no further than the one wider <a href="#rule">Rule</a> already on the record.</li>
  <li>Leaving a <a href="#rule">Rule</a> narrower than an independent search under clause 3.1 showed it should be, where
the reason is that the wider check is harder to build without <a href="#false-positive">False positives</a> rather
than that the <a href="#hazard">Hazard</a> is absent from what it would add. The <a href="#instance">Instances</a> the search found are
fixed regardless; what stays with the <a href="#owner">Owner</a> is the <a href="#class">Class</a> left partly undefended.</li>
</ul>

<p>The dividing line is that the <a href="#practitioner">Practitioner</a> decides <strong>how the <a href="#defence">Defence</a> is built</strong> and the <a href="#owner">Owner</a>
decides <strong>what the codebase is permitted to keep</strong>. An <a href="#agent">Agent</a> MUST NOT <a href="#baseline">Baseline</a>, suppress or
knowingly leave an <a href="#instance">Instance</a> unfixed on its own authority, whatever the <a href="#instance">Instance</a> count turns out to
be.</p>

<p><strong>The default position is that none of these are permitted at all.</strong> <a href="#suppression">Suppressions</a>, <a href="#baseline">Baselines</a> and
every other means of evading a <a href="#defence">Defence</a> are not techniques with a threshold governing their use; they are
<a href="#exception">Exceptions</a> to the method, and the standing answer is no. An <a href="#exception">Exception</a> exists only where a human has
discussed it, agreed it and documented it for that project. Nothing an <a href="#agent">Agent</a> concludes on its own
creates one.</p>

<p><strong>Uncertainty is itself an <a href="#escalation">Escalation</a> trigger.</strong> Where the <a href="#practitioner">Practitioner</a> is not confident that code
they are about to exclude is free of the <a href="#hazard">Hazard</a>, that exclusion is a <a href="#suppression">Suppression</a> and belongs to the
<a href="#owner">Owner</a>, whatever it is called. This is deliberately asymmetric: <a href="#narrowing">Narrowing</a> is the <a href="#practitioner">Practitioner</a>’s
decision only whilst they are sure, and doubt routes it upwards. Without this, the <a href="#hazard">Hazard</a> judgement
in clause 3.3 would let a <a href="#practitioner">Practitioner</a> suppress an <a href="#instance">Instance</a> whilst never touching the clause that
would have escalated it.</p>

<p><strong>Where the work is entirely <a href="#agent">Agent</a>-driven, do the work.</strong> Fixing <a href="#instance">Instances</a> is cheap now, and an
<a href="#agent">Agent</a> that reaches for an <a href="#exception">Exception</a> has almost always found a shortcut rather than a genuine
obstacle.</p>

<p><strong>What to do whilst waiting.</strong> A decision awaiting the <a href="#owner">Owner</a> MUST NOT stall the rest of the work.
The <a href="#practitioner">Practitioner</a> completes everything within their own authority, reports the blocked decision
with the <a href="#instance">Instance</a> count and what it would cost to fix, and leaves the <a href="#rule">Rule</a> unmerged rather than
merged in a weakened form. Where the only thing pending is whether one matched case carries the
<a href="#hazard">Hazard</a>, the <a href="#rule">Rule</a> MAY merge at full width with that case recorded as a known <a href="#instance">Instance</a>
awaiting the <a href="#owner">Owner</a>, enumerable under clause 8.7: the <a href="#rule">Rule</a> is not weakened, the case is not
hidden, and the <a href="#practitioner">Practitioner</a> is not pushed towards declaring that no pattern exists. Reporting a
<a href="#sweep">Sweep</a> of four hundred <a href="#instance">Instances</a> and stopping is a useful outcome; quietly baselining them is not.</p>

<p>Report by whatever channel carries the rest of the <a href="#practitioner">Practitioner</a>’s work: for an <a href="#agent">Agent</a>, its
output to whoever instructed it. An <a href="#instance">Instance</a> in code the <a href="#practitioner">Practitioner</a> is not permitted to
change is reported the same way, as a blocked decision with its count, and is never a reason to
narrow the <a href="#rule">Rule</a> so that it stops seeing it.</p>

<p><strong>The <a href="#instance">Instance</a> count alone never triggers <a href="#escalation">Escalation</a>.</strong> A large <a href="#sweep">Sweep</a> is work, not a blocked
decision. Stopping to report is what the <a href="#practitioner">Practitioner</a> does when an <a href="#owner">Owner</a> decision is genuinely
required, which means an <a href="#exception">Exception</a> is being contemplated, or when they are uncertain whether an
exclusion carries the <a href="#hazard">Hazard</a>. It is not what they do because the number is uncomfortable. Five
hundred <a href="#instance">Instances</a> with no <a href="#exception">Exception</a> needed is five hundred <a href="#instance">Instances</a> to fix.</p>

<p>A remediation MAY be delivered across several changes where the volume warrants it, <strong>provided the
<a href="#rule">Rule</a> does not become <a href="#blocking">Blocking</a> until every <a href="#instance">Instance</a> is fixed</strong>, since a <a href="#rule">Blocking</a> merged over a
codebase that still violates it either fails continuously or has been weakened to avoid doing so.
No <a href="#instance">Instance</a> may be knowingly left unfixed at the end without the <a href="#owner">Owner</a>’s decision.</p>

<p><strong>Why</strong>: without this, an executing <a href="#agent">Agent</a> either stalls at the first judgement call it cannot
authorise or takes the decision unilaterally, and the second failure is much harder to notice than
the first. Both were observed in cold readings of an earlier draft of this specification.</p>

<h2 id="5-ordering-the-quality-checks">5. Ordering the quality checks</h2>

<p>Defence Before Fix presumes quality checks ordered as follows, and the ordering is enforcing rather
than advisory:</p>

<ol>
  <li><a href="#detector">Detectors</a> - type checking, linting, custom <a href="#rule">Rules</a></li>
  <li>Automated tests - unit, integration, functional</li>
  <li>Build verification - services start, dependencies resolve</li>
  <li>Human acceptance testing - visual review, workflow validation</li>
</ol>

<p><a href="#detector">Detectors</a> MUST run first, and a failure at that level MUST stop the levels below it from
being treated as meaningful.</p>

<p>This constrains the <strong>sequence of checks</strong>, not the infrastructure that runs them. How the project
expresses that sequence is out of scope, per section 8. It is a property of the project, not a step
in any one remediation: a <a href="#practitioner">Practitioner</a> handling a <a href="#defect">Defect</a> is not required to reorder the
project’s checks, and a project whose checks run in another order is a project that does not
<a href="#conform">Conform</a>, which is a finding to record, not work to do on the way to a fix.</p>

<p><strong>Why</strong>: a <a href="#detector">Detector</a> is preventive and a test is diagnostic. A <a href="#detector">Detector</a> reads every file, every
time it runs, so it cannot miss a file merely because nobody thought to write a test for it.
Failures at a lower level also produce confusing results at the levels above, so any other order
costs time diagnosing symptoms of a problem the first level would have named directly.</p>

<h2 id="6-relationship-to-test-driven-development">6. Relationship to test-driven development</h2>

<p>Defence Before Fix neither replaces nor competes with TDD. They operate at different levels, and
a full remediation uses both: a test, executed by a <a href="#runner">Runner</a>, pins the <a href="#instance">Instance</a>; a <a href="#rule">Rule</a>, evaluated by
a <a href="#detector">Detector</a>, catches the <a href="#class">Class</a>.</p>

<table>
  <thead>
    <tr>
      <th>Concern</th>
      <th>TDD</th>
      <th>Defence Before Fix</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Operates on</td>
      <td>The specific <a href="#defect">Defect</a></td>
      <td>The <a href="#class">Class</a> the <a href="#defect">Defect</a> belongs to</td>
    </tr>
    <tr>
      <td>Artefact</td>
      <td>A test</td>
      <td>A <a href="#rule">Rule</a> in a <a href="#detector">Detector</a></td>
    </tr>
    <tr>
      <td>Proves correctness</td>
      <td>For one behaviour, by executing it</td>
      <td>For a pattern, by reading the code</td>
    </tr>
    <tr>
      <td>Answers</td>
      <td>“Is this bug fixed?”</td>
      <td>“Can this kind of bug still exist anywhere?”</td>
    </tr>
  </tbody>
</table>

<p>The specific <a href="#defect">Defect</a> is still reproduced with a test and proven fixed, exactly as normal. What
this method adds is the phase before that work begins, in which the codebase’s permanent <a href="#coverage">Defence</a> is extended using the evidence the <a href="#defect">Defect</a> has just supplied.</p>

<p>The name records the ordering. The <a href="#defence">Defence</a> comes first, because once the fix has landed the
evidence is gone and the opportunity closes with it.</p>

<h2 id="7-conformance">7. Conformance</h2>

<p><a href="#conform">Conformance</a> is claimed at one of four levels. Partial <a href="#conform">Conformance</a> MUST NOT be described as
<a href="#conform">Conformance</a>.</p>

<p><strong>A remediation <a href="#conform">Conforms</a></strong> if all six clauses of section 3 were followed for that <a href="#defect">Defect</a>.</p>

<p><strong>A <a href="#conform">Defence</a></strong> if it satisfies clauses 3.1, 3.2, 3.3, 3.5 and 3.6: it is drawn to a
<a href="#class">Class</a> within both bounds and not to the reported <a href="#instance">Instance</a>, it is evaluated by reading code, it
was proven to fire against either the originating <a href="#instance">Instance</a> or <a href="#fixture">Fixture</a> code, it fails rather
than warns, and its <a href="#message">Message</a> is terse and resolves to <a href="#remediation-docs">Remediation docs</a> versioned alongside
it. A <a href="#defence">Defence</a> can <a href="#conform">Conform</a> whilst the <a href="#sweep">Sweep</a> is incomplete; what that describes is a
<a href="#defence">Conforming</a> over a codebase with known <a href="#instance">Instances</a>, which section 4 requires to be
recorded, not a <a href="#conform">Conforming</a> remediation.</p>

<p><strong>A <a href="#conform">Toolchain</a></strong> if it permits bespoke custom <a href="#rule">Rules</a>, supports the ordering in section 5,
and satisfies clauses 8.1 to 8.5 and 8.7: the <a href="#practitioner">Practitioner</a> can run it, the result reaches them in
the output they are reading, <a href="#identifier">Identifiers</a> resolve mechanically, documentation states the correct
construction, and both the <a href="#defence">Defences</a> and the project’s recorded decisions can be enumerated. Clause
8.6 is a SHOULD and does not bear on <a href="#conform">Conformance</a>, deliberately: it is the one clause that acts
before a mistake rather than after, so no failing run can prove it absent, and <a href="#conform">Conformance</a> is
claimed only over what a run can prove. The <a href="/TOOLING-SPEC.html">toolchain specification</a> names a
<a href="#toolchain">Toolchain</a> that also satisfies it as <a href="#conform">Conforming</a> with <a href="#agent">Agent</a> support, which is the claim to
make when it is true. Those obligations fall in two documents: what each <a href="#detector">Detector</a> the
<a href="#toolchain">Toolchain</a> routes a <a href="#defence">Defence</a> through must offer is stated in the
<a href="/DETECTOR-SPEC.html">detector specification</a>, and what the assembled <a href="#toolchain">Toolchain</a> must add is stated
in the <a href="/TOOLING-SPEC.html">toolchain specification</a>, which requires the first as its own opening clause,
carries the ordering in section 5 and the correct construction of clause 8.4 as clauses of its own,
and states the listing and the project record that clauses 8.5 and 8.7 require.</p>

<p><strong>A project <a href="#conform">Conforms</a></strong> if its <a href="#defence">Defences</a> and <a href="#conform">Remediation docs</a>, and if its recorded
decisions under section 3 are discoverable. Nothing here constrains how the project runs its checks
or what it does when they fail.</p>

<p><a href="#conform">Toolchain</a> is stated in full in the <a href="/DETECTOR-SPEC.html">detector specification</a>
and the <a href="/TOOLING-SPEC.html">toolchain specification</a> together, which give each of the obligations above as a
clause with its own reasoning, and add what a <a href="#detector">Detector</a> and a <a href="#toolchain">Toolchain</a> must provide so
that a project can meet its own. The summary here is normative and sufficient to judge a
<a href="#toolchain">Toolchain</a> by; the companion documents are where a <a href="#detector">Detector</a> maintainer and a
<a href="#toolchain">Toolchain</a> author should work from.</p>

<p>A verdict on a remediation or a <a href="#defence">Defence</a> MUST rest on reproduction, not on the report: the reviewer
reruns the <a href="#defence">Defence</a> red at the commit that introduced it and green at the final commit, through the
project’s own entry point for accepting changes, and reruns the originating symptom against the fix.
Where the <a href="#rule">Rule</a> was proven against a <a href="#fixture">Fixture</a> under clause 3.3, the red run is against the
retained <a href="#fixture">Fixture</a>. Where the originating symptom cannot be reproduced through the entry point,
an incident or an observation at production scale, the reviewer says so and verifies the red and green
runs alone. A verdict on a <a href="#toolchain">Toolchain</a> or a project rests on the reviewer exercising the clauses
named above, not on the claimant’s report of having done so. A report that reads as <a href="#conform">Conforming</a>
has not been shown to be.</p>

<p>A <a href="#toolchain">Toolchain</a> MAY additionally audit its own <a href="#rule">Rules</a> against clause 3.6, failing its own release if any
<a href="#rule">Rule</a> fails something without resolving to <a href="#remediation-docs">Remediation docs</a>. This is the strongest
available demonstration that the clause is honoured rather than asserted.</p>

<h2 id="8-operating-a-defence-under-ai-assisted-development">8. Operating a defence under AI-assisted development</h2>

<p><em>This section is normative.</em></p>

<h3 id="the-argument-these-clauses-rest-on">The argument these clauses rest on</h3>

<p>For a human developer, a failure <a href="#message">Message</a> that teaches is good practice. They may read it, may
internalise it, may ignore it, and which of those happens depends on their seniority, their
workload and how many times they have seen the <a href="#message">Message</a> before.</p>

<p>For an <a href="#agent">Agent</a>, the failure <a href="#message">Message</a> is the entire remediation loop. It is consumed as instruction,
in the same turn, every time, with no fatigue and no seniority gradient. A <a href="#message">Message</a> that resolves
to documentation explaining the correct approach does not simply block the <a href="#agent">Agent</a>, it redirects it,
and it does so identically on the thousandth occurrence as on the first.</p>

<p>That reframes the usual complaint about AI-written code. The difficulty was never that <a href="#agent">Agents</a> make
mistakes, since people do too. The difficulty is that nobody built the channel to correct them at
the level of the <a href="#class">Class</a> rather than the <a href="#instance">Instance</a>.</p>

<p>If the value of the method depends on that loop closing, then what closing it requires has to be
stated rather than assumed. The clauses below are what a <a href="#defence">Defence</a> must do to be usable by an <a href="#agent">Agent</a>
at all.</p>

<h3 id="scope-this-specifies-a-defence-not-a-pipeline">Scope: this specifies a defence, not a pipeline</h3>

<p><strong>Continuous integration, git hooks, branch policy, review process and release management are out
of scope.</strong> How a project chooses to run its quality checks, and what it does when they fail, is
the project’s own business and no part of this specification.</p>

<p>What is in scope is the <a href="#defence">Defence</a> itself: a <a href="#rule">Rule</a> that reads code, the documentation that explains it,
and the requirement that the <a href="#practitioner">Practitioner</a> can run it and act on the result. A specification that
told projects how to run their checks would be overreaching, and would be ignored for it.</p>

<h3 id="81-the-practitioner-must-be-able-to-run-the-defence-themselves">8.1 The practitioner MUST be able to run the defence themselves</h3>

<p>A <a href="#rule">Rule</a> that only reports through infrastructure the <a href="#practitioner">Practitioner</a> cannot invoke is not usable by
them, whatever it does for anyone else.</p>

<p><strong>Why</strong>: an <a href="#agent">Agent</a> that cannot check its own work against a <a href="#defence">Defence</a> cannot iterate against it, so
the loop never closes in the turn where the mistake was made, which is the only moment it is cheap
to fix.</p>

<h3 id="82-the-result-must-reach-the-practitioner-in-the-output-they-are-already-reading">8.2 The result MUST reach the practitioner in the output they are already reading</h3>

<p>The <a href="#detector">Detector</a>’s own output, at the point of the work. Not exclusively a dashboard, a report artefact or
a summary elsewhere.</p>

<p><strong>Why</strong>: a <a href="#message">Message</a> that teaches nobody, because nobody sees it, is the same as no <a href="#message">Message</a>.</p>

<h3 id="83-the-identifier-must-resolve-without-a-human">8.3 The identifier MUST resolve without a human</h3>

<p>By a command the <a href="#practitioner">Practitioner</a> can run, a file they can read, or a URL they can fetch.</p>

<p><strong>Why</strong>: clause 3.6 requires the <a href="#identifier">Identifier</a> to resolve. This requires it to resolve <em>for the
reader</em>, which for an <a href="#agent">Agent</a> means mechanically. An explanation that lives in a colleague’s head
resolves for nobody at three in the morning either.</p>

<h3 id="84-documentation-must-state-the-correct-construction-not-only-the-prohibition">8.4 Documentation MUST state the correct construction, not only the prohibition</h3>

<p>Explaining why the pattern is dangerous is not sufficient. The documentation has to show what to do
instead, specifically enough to act on.</p>

<p><strong>Why</strong>: this is the difference between <a href="#blocking">Blocking</a> an <a href="#agent">Agent</a> and redirecting it. A prohibition alone
leaves it to guess at the replacement, and it will guess.</p>

<h3 id="85-a-projects-defences-must-be-enumerable">8.5 A project’s defences MUST be enumerable</h3>

<p>A <a href="#practitioner">Practitioner</a> MUST be able to list what defends this codebase, and read each <a href="#defence">Defence</a>’s
documentation, without triggering it first.</p>

<p><strong>Why</strong>: an <a href="#agent">Agent</a> arriving at a codebase has no colleague to ask and no memory of last time.
Without this, a project’s standards can only be learned by violating them one at a time.</p>

<h3 id="86-a-project-should-publish-a-summary-of-its-defences-suitable-for-an-agents-context">8.6 A project SHOULD publish a summary of its defences suitable for an agent’s context</h3>

<p>One terse line per <a href="#defence">Defence</a>, stating the <a href="#rule">Rule</a> as a standing instruction rather than as a failure
report, each linked to its full documentation. <em>“Error hiding is forbidden”</em> is the shape.</p>

<p><strong>Why</strong>: everything else in this method operates after the mistake. This operates before it. A
summary small enough to sit in an <a href="#agent">Agent</a>’s working context turns the accumulated <a href="#defence">Defences</a> from a
series of ambushes into a description of how this project expects code to be written, and it costs
one table.</p>

<h3 id="87-recorded-project-decisions-must-be-discoverable-by-the-same-means">8.7 Recorded project decisions MUST be discoverable by the same means</h3>

<p>The judgements section 3 delegates to project level, once agreed and written down, MUST be
reachable exactly as the <a href="#defence">Defences</a> are.</p>

<p><strong>Why</strong>: section 3 says a project’s accumulated decisions become part of its <a href="#coverage">Coverage</a>. <a href="#coverage">Coverage</a>
nobody can find is not <a href="#coverage">Coverage</a>, and every <a href="#practitioner">Practitioner</a> who arrives after a decision will otherwise
re-open it.</p>

<h2 id="9-citation">9. Citation</h2>

<blockquote>
  <p>Edmonds, Joseph. <em>Defence Before Fix</em>, version 1.0.1. First published 22 February 2026.
<a href="https://ltscommerce.dev">https://ltscommerce.dev</a></p>
</blockquote>

<h2 id="appendix-a-instructing-an-agent">Appendix A: Instructing an agent</h2>

<p>Where an <a href="#agent">Agent</a> is expected to follow this method, give it the clauses rather than the article.
This appendix restates sections 3 and 4; where the two differ, the sections govern.</p>

<blockquote>
  <p>When you find a <a href="#defect">Defect</a> of any kind, do not fix it yet.</p>

  <p>First work out what <a href="#class">Class</a> it belongs to: the pattern, style, idiom or configuration that allowed
it. Do not decide in advance whether that is possible, attempt it. If you cannot write a <a href="#rule">Rule</a>
for it, say so and fix the <a href="#defect">Defect</a> conventionally.</p>

  <p>Write a custom <a href="#rule">Rule</a> that detects the <a href="#class">Class</a>, in a tool that reads code rather than running it.
Draw it so that it catches more than the single <a href="#instance">Instance</a> you started from, but never so broadly
that it matches code which does not carry the <a href="#hazard">Hazard</a>.</p>

  <p>Do not trust the <a href="#rule">Rule</a> as your only way of finding <a href="#instance">Instances</a>. Search independently as well, by
text search and by reading the code, and check the <a href="#rule">Rule</a> catches what you found by hand. Make that
search a thorough one rather than a gesture, because everything downstream rests on it. If your
own search turns up <a href="#instance">Instances</a> the <a href="#rule">Rule</a> missed, widen the <a href="#rule">Rule</a> until it catches them; the search
wins, not the <a href="#rule">Rule</a>. If it turns up nothing new, then one <a href="#instance">Instance</a> is a supported conclusion rather
than a guess.</p>

  <p>The <a href="#hazard">Hazard</a> is whatever harm the <a href="#class">Class</a> does, and it need not be a failure. Error hiding counts.
So does something merely sloppy that makes the code harder to reason about safely.</p>

  <p>Prove the <a href="#rule">Rule</a> fires before you trust it. It must catch the originating <a href="#defect">Defect</a>. If the pattern
is not present in the codebase, because it was already fixed or because you are defending
against it pre-emptively, prove the <a href="#rule">Rule</a> against <a href="#fixture">Fixture</a> code that demonstrates the pattern and
keep that <a href="#fixture">Fixture</a> as the <a href="#rule">Rule</a>’s test.</p>

  <p>Then run the <a href="#rule">Rule</a> everywhere the pattern can occur, which usually means one language but never
just the component the bug was reported in, and report the <a href="#instance">Instance</a> count. Catching far more
<a href="#instance">Instances</a> than you expected is success, not evidence the <a href="#rule">Rule</a> is too broad. Only ever narrow a
<a href="#rule">Rule</a> to exclude code that does not carry the <a href="#hazard">Hazard</a>; never to make the number smaller.</p>

  <p>Fix every <a href="#instance">Instance</a>. Examine each one; where the same answer is genuinely right for all of them,
applying it to all of them is correct. Never satisfy the <a href="#rule">Rule</a> whilst leaving the <a href="#hazard">Hazard</a> in place,
and never suppress the <a href="#rule">Rule</a> at the call site.</p>

  <p>You do not have the authority to <a href="#baseline">Baseline</a>, to suppress an <a href="#instance">Instance</a>, or to leave a known <a href="#instance">Instance</a>
unfixed, however large the count turns out to be. Those belong to whoever owns the codebase, and
the standing answer is no unless a human has already agreed and documented an <a href="#exception">Exception</a> for this
project. If you hit one, finish everything else, then report the count and what fixing it would
take, and leave the <a href="#rule">Rule</a> unmerged rather than merging it weakened.</p>

  <p>If you are unsure whether code you want to exclude carries the <a href="#hazard">Hazard</a>, you are suppressing it, so
refer it upwards rather than deciding. Narrow only where you are confident.</p>

  <p>Otherwise, just do the work. Fixing <a href="#instance">Instances</a> is cheap for you, and reaching for an <a href="#exception">Exception</a> is
almost always a shortcut rather than a real obstacle.</p>

  <p>Make the <a href="#rule">Rule</a> a permanent part of the project’s quality checks, failing rather than <a href="#warning">Warning</a>. Write
its failure <a href="#message">Message</a> terse, carrying a stable <a href="#identifier">Identifier</a> that resolves to documentation shipped
with the project saying what the <a href="#rule">Rule</a> is about, why it exists and how to fix a violation
correctly. Check you can run the <a href="#rule">Rule</a> yourself and read its output, because if you cannot, nor can
the next <a href="#agent">Agent</a>.</p>

  <p>Only then fix the original <a href="#defect">Defect</a> in the normal way, with a test that reproduces it.</p>
</blockquote>

<h2 id="changelog">Changelog</h2>

<table>
  <thead>
    <tr>
      <th>Version</th>
      <th>Date</th>
      <th>Change</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>1.0.0</td>
      <td>2026-09-08</td>
      <td>Initial specification, formalising the method published on 22 February 2026. Revised before publication after three independent cold readers understood the method correctly and still could not execute its judgement calls.</td>
    </tr>
    <tr>
      <td>1.0.1</td>
      <td>2026-09-08</td>
      <td>Clarity, no obligation changed: section 3 opens with a map of the six clauses; clause 3.1 opens with its five steps and closes with what it leaves on the record; clause 3.3 is in three named parts and its <a href="#narrowing">Narrowing</a> part opens with the decision. The header and the terminology entries for <a href="#detector">Detector</a>, <a href="#toolchain">Toolchain</a> and <a href="#conform">Conform</a> name the <a href="/DETECTOR-SPEC.html">detector specification</a> 1.0.0 alongside the <a href="/TOOLING-SPEC.html">toolchain specification</a> 0.2.0, and <a href="#conform">Conform</a> extends to the companion specification being claimed. Accepted under <a href="/ACCEPTANCE.html">ACCEPTANCE.md</a>.</td>
    </tr>
  </tbody>
</table>

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
