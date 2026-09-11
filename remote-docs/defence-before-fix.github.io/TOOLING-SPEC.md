---
source_url: https://defence-before-fix.github.io/TOOLING-SPEC.html
fetched_at: 2026-09-11T16:15:15.940317+00:00
fidelity: verbatim
source_sha256: 45800db86cd77e4afc4e868f3c0e332768fc404d8b9aee89e1dc5ab863b877df
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
<title>Defence Before Fix: Toolchain Specification | Defence Before Fix (DBF)</title>
<meta name="generator" content="Jekyll v3.10.0" />
<meta property="og:title" content="Defence Before Fix: Toolchain Specification" />
<meta name="author" content="Joseph Edmonds" />
<meta property="og:locale" content="en_GB" />
<meta name="description" content="A phase that runs before a defect is fixed. The method, detector and toolchain specifications." />
<meta property="og:description" content="A phase that runs before a defect is fixed. The method, detector and toolchain specifications." />
<link rel="canonical" href="https://defence-before-fix.github.io/TOOLING-SPEC.html" />
<meta property="og:url" content="https://defence-before-fix.github.io/TOOLING-SPEC.html" />
<meta property="og:site_name" content="Defence Before Fix (DBF)" />
<meta property="og:type" content="website" />
<meta name="twitter:card" content="summary" />
<meta property="twitter:title" content="Defence Before Fix: Toolchain Specification" />
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"WebPage","author":{"@type":"Person","name":"Joseph Edmonds"},"description":"A phase that runs before a defect is fixed. The method, detector and toolchain specifications.","headline":"Defence Before Fix: Toolchain Specification","url":"https://defence-before-fix.github.io/TOOLING-SPEC.html"}</script>
<!-- End Jekyll SEO tag -->

  
<link rel="canonical" href="https://defence-before-fix.github.io/TOOLING-SPEC.html">
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
  <link rel="alternate" type="text/markdown" href="/raw/TOOLING-SPEC.md" title="Raw markdown">
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
    This page as <a href="/raw/TOOLING-SPEC.md">raw markdown</a>.</p>
  <p class="site-byline">By <a href="https://ltscommerce.dev">Joseph Edmonds</a> of
    <a href="https://edmondscommerce.co.uk">Edmonds Commerce</a>. First published 22 February 2026.</p>
</header>

  <main>
    <h1 id="defence-before-fix-toolchain-specification">Defence Before Fix: Toolchain Specification</h1>

<p><strong>Version</strong>: 0.2.0, published 2026-09-08
<strong>Companion to</strong>: <a href="/SPEC.html">the method specification</a>, version 1.0.1, and <a href="/DETECTOR-SPEC.html">the detector specification</a>, version 1.0.0
<strong>Author</strong>: <a href="https://ltscommerce.dev">Joseph Edmonds</a>, <a href="https://edmondscommerce.co.uk">Edmonds Commerce</a>
<strong>Coined</strong>: 22 February 2026, in <a href="https://ltscommerce.dev/articles/defence-before-fix-static-analysis">the original article</a></p>

<h2 id="1-what-this-document-is-for">1. What this document is for</h2>

<p>The key words MUST, MUST NOT, REQUIRED, SHOULD, SHOULD NOT and MAY are to be interpreted as
described in RFC 2119.</p>

<p><a href="/SPEC.html">The method specification</a> states what a <a href="/SPEC.html#practitioner">Practitioner</a> does when a <a href="/SPEC.html#defect">Defect</a> is found.
<a href="/DETECTOR-SPEC.html">The detector specification</a> states what each <a href="/SPEC.html#detector">Detector</a> must offer so that they
can write, prove, run and resolve a <a href="/SPEC.html#rule">Rule</a> in it. This document states what a project’s
<a href="/SPEC.html#toolchain">Toolchain</a> must offer beyond that, so that the <a href="/SPEC.html#rule">Rules</a> become <a href="/SPEC.html#defence">Defences</a>
the project governs.</p>

<p><strong>A <a href="/SPEC.html#toolchain">Toolchain</a> is measured at the project level.</strong> It is whatever the project assembles to
run its checks through: the <a href="/SPEC.html#detector">Detectors</a> and <a href="/SPEC.html#runner">Runners</a>, and the parts around them
that route, list, record and resolve. Those parts may be third-party, first-party or project-level,
in any combination. A third-party <a href="/SPEC.html#toolchain">Toolchain</a> such as <code class="language-plaintext highlighter-rouge">php-qa-ci</code> can supply all of it. A project
can also meet this document with its own scripts around a bare <a href="/SPEC.html#detector">Detector</a>. <a href="/SPEC.html#conform">Conformance</a>
is a property of the assembled whole, because that is where it counts: a <a href="/SPEC.html#practitioner">Practitioner</a>
arriving at the project cannot tell which package a mechanism came from, and does not need to.</p>

<p>The three documents are separable because they fail separately, and all three failures have been
observed. A project can follow the method faithfully on a <a href="/SPEC.html#toolchain">Toolchain</a> that gives it nowhere to
record what it decided. A <a href="/SPEC.html#toolchain">Toolchain</a> can offer every mechanism the method needs whilst the
project using it writes no <a href="/SPEC.html#rule">Rules</a> at all. A <a href="/SPEC.html#detector">Detector</a> can be the best host for
a <a href="/SPEC.html#rule">Rule</a> in its language whilst shipping an inline ignore that the project has never decided
whether to allow. Conflating them produces a specification that blames a project for a gap in its
<a href="/SPEC.html#toolchain">Toolchain</a>, credits a <a href="/SPEC.html#toolchain">Toolchain</a> for discipline the project supplied itself, or
fails a <a href="/SPEC.html#detector">Detector</a> for governance that was never its to decide.</p>

<p><strong>The governing principle</strong>: where the method specification requires a <a href="/SPEC.html#practitioner">Practitioner</a> to do
something, a <a href="/SPEC.html#toolchain">Conforming</a> MUST make that possible without the project
building the mechanism first. A <a href="/SPEC.html#toolchain">Toolchain</a> that leaves the project to construct the means has
moved the obligation rather than met it. Where the project builds the means itself, those scripts are
part of its <a href="/SPEC.html#toolchain">Toolchain</a> and are judged as such.</p>

<p><strong>How to read this document.</strong> It is self-contained for grading a <a href="/SPEC.html#toolchain">Toolchain</a>. The
table in section 2 glosses every term it borrows. Each clause states its obligation in its heading
and first sentence, and the <strong>Why</strong> paragraph beneath is reasoning, not a further requirement. A
link is there for depth and is not a prerequisite for the sentence that carries it.</p>

<h2 id="2-terminology">2. Terminology</h2>

<p>Terms from the method specification and the <a href="/DETECTOR-SPEC.html">detector specification</a> carry over unchanged. Every
capitalised term links to its definition; the ones this document leans on most are, in short:</p>

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
      <td><a href="/SPEC.html#runner">Runner</a></td>
      <td>A tool that executes code, a test suite or a compilation, and reports what happened</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#rule">Rule</a></td>
      <td>One check a <a href="/SPEC.html#detector">Detector</a> evaluates</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#defence">Defence</a></td>
      <td>A <a href="/SPEC.html#rule">Rule</a> with its documentation, in force and <a href="/SPEC.html#blocking">Blocking</a></td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#identifier">Identifier</a></td>
      <td>The stable name printed with a finding that leads to its documentation</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#blocking">Blocking</a></td>
      <td>Fails the run rather than issuing a <a href="/SPEC.html#warning">Warning</a></td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#exception">Exception</a></td>
      <td>A recorded, justified decision to leave an <a href="/SPEC.html#instance">Instance</a> unfixed or a <a href="/SPEC.html#rule">Rule</a> unapplied</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#practitioner">Practitioner</a></td>
      <td>Whoever is doing the work, a person or an <a href="/SPEC.html#agent">Agent</a></td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#owner">Owner</a></td>
      <td>The human who decides what the codebase may keep</td>
    </tr>
    <tr>
      <td><a href="/DETECTOR-SPEC.html#bundled-rule">Bundled rule</a></td>
      <td>A <a href="/SPEC.html#rule">Rule</a> a <a href="/SPEC.html#detector">Detector</a> ships rather than one the project wrote</td>
    </tr>
    <tr>
      <td><a href="/DETECTOR-SPEC.html#harness">Harness</a></td>
      <td>The route by which one <a href="/SPEC.html#rule">Rule</a> is run against supplied code on its own</td>
    </tr>
  </tbody>
</table>

<p>These are additional.</p>

<h4 id="consuming-project">Consuming project</h4>

<p>A codebase that installs a <a href="/SPEC.html#detector">Detector</a> or a <a href="/SPEC.html#toolchain">Toolchain</a> shipped by somebody else. The maintainer of what is installed does not control it and cannot inspect it.</p>

<h4 id="bundled-defence">Bundled defence</h4>

<p>A <a href="/SPEC.html#defence">Defence</a> a shipped <a href="/SPEC.html#toolchain">Toolchain</a> carries and enables by default in every <a href="#consuming-project">Consuming project</a>. A <a href="/DETECTOR-SPEC.html#bundled-rule">Bundled rule</a> is the <a href="/SPEC.html#detector">Detector</a>-level counterpart; a <a href="#bundled-defence">Bundled defence</a> is one with its documentation, <a href="/SPEC.html#blocking">Blocking</a>, and routed through the <a href="/SPEC.html#toolchain">Toolchain</a>’s own entry point.</p>

<h4 id="project-record">Project record</h4>

<p>The place a project writes down the judgements the method specification delegates to it: <a href="/SPEC.html#calibration">Calibrations</a>, <a href="/SPEC.html#exception">Exceptions</a> and conventions.</p>

<p>The distinction between a <a href="#bundled-defence">Bundled defence</a> and a project’s own is significant throughout. A bundled
<a href="/SPEC.html#defence">Defence</a> is authored once and runs in codebases its author will never see, so everything a
<a href="/SPEC.html#practitioner">Practitioner</a> needs in order to act on it has to travel with it.</p>

<h2 id="3-the-division-of-responsibility">3. The division of responsibility</h2>

<p>Almost every requirement in section 8 of the method specification has two halves. Stating only one
of them is what produces a <a href="/SPEC.html#toolchain">Toolchain</a> that is <em>nearly</em> usable. The middle column says which
document states the mechanism half; the assembled <a href="/SPEC.html#toolchain">Toolchain</a> MUST provide every row, whichever
part of it does so.</p>

<table>
  <thead>
    <tr>
      <th>Requirement</th>
      <th>The mechanism, and where it is stated</th>
      <th>The project supplies</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Bespoke <a href="/SPEC.html#defence">Defences</a></td>
      <td>The authoring and registration route, <a href="/DETECTOR-SPEC.html">detector specification</a> 4.1</td>
      <td>The <a href="/SPEC.html#rule">Rules</a></td>
    </tr>
    <tr>
      <td>The red proof</td>
      <td>A <a href="/DETECTOR-SPEC.html#harness">Harness</a>, <a href="/DETECTOR-SPEC.html">detector specification</a> 4.2</td>
      <td>The <a href="/SPEC.html#fixture">Fixture</a> and the red run</td>
    </tr>
    <tr>
      <td>Running the <a href="/SPEC.html#defence">Defence</a></td>
      <td>A local invocation, including subsets, <a href="/DETECTOR-SPEC.html">detector specification</a> 5</td>
      <td>Running it</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#identifier">Identifier</a> resolution</td>
      <td>The lookup, <a href="/DETECTOR-SPEC.html">detector specification</a> 6 for <a href="/DETECTOR-SPEC.html#bundled-rule">Bundled rules</a>, clause 4.2 here for the rest</td>
      <td>The documentation content</td>
    </tr>
    <tr>
      <td>Correct construction</td>
      <td>A place for it to live, clause 4.2 here</td>
      <td>The remediation text</td>
    </tr>
    <tr>
      <td>Enumeration</td>
      <td>The listing, section 5 here</td>
      <td>The <a href="/SPEC.html#defence">Defences</a> listed</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#agent">Agent</a>-context summary</td>
      <td>Generation and delivery, section 7 here</td>
      <td>The terse lines</td>
    </tr>
    <tr>
      <td>Recorded decisions</td>
      <td>A location it reads itself, section 6 here</td>
      <td>The decisions</td>
    </tr>
    <tr>
      <td><a href="/SPEC.html#suppression">Suppression</a></td>
      <td>A route that can be forbidden, <a href="/DETECTOR-SPEC.html">detector specification</a> 7; forbidding it, clause 4.3 here</td>
      <td>The decision, under method section 4</td>
    </tr>
  </tbody>
</table>

<p>Read down the middle column and the shape of a <a href="/SPEC.html#toolchain">Conforming</a> is already visible. Read across
any row and the failure mode is visible too: either half alone leaves the <a href="/SPEC.html#practitioner">Practitioner</a> stuck.</p>

<h2 id="4-the-detectors-a-toolchain-routes-defences-through">4. The detectors a toolchain routes defences through</h2>

<h3 id="41-every-detector-the-toolchain-routes-a-defence-through-must-conform-to-the-detector-specification">4.1 Every detector the toolchain routes a defence through MUST conform to the <a href="/DETECTOR-SPEC.html">detector specification</a></h3>

<p>A <a href="/SPEC.html#toolchain">Toolchain</a> MUST NOT route a <a href="/SPEC.html#defence">Defence</a> through a <a href="/SPEC.html#detector">Detector</a> that does not
<a href="/SPEC.html#conform">Conform</a> to <a href="/DETECTOR-SPEC.html">the detector specification</a>. That document’s MUSTs, in brief,
are six. The <a href="/SPEC.html#detector">Detector</a> hosts bespoke <a href="/SPEC.html#rule">Rules</a>. It runs one <a href="/SPEC.html#rule">Rule</a> on its own against supplied
code. It prints a stable <a href="/SPEC.html#identifier">Identifier</a> with every finding. It runs locally, down to one file, with
the result in the command’s own output and nothing reportable only through a hosted service. It
resolves every <a href="/SPEC.html#identifier">Identifier</a> a <a href="/DETECTOR-SPEC.html#bundled-rule">Bundled rule</a> can print to documentation shipped with it, offline.
It makes any inline <a href="/SPEC.html#suppression">Suppression</a> route detectable or disableable. A <a href="/SPEC.html#detector">Detector</a> that fails any
one of those, after wrapping, fails this clause. Three points govern how that is met.</p>

<ol>
  <li>
    <p><strong>Wrapping is permitted.</strong> The <a href="/SPEC.html#toolchain">Toolchain</a> MAY supply a mechanism the
<a href="/SPEC.html#detector">Detector</a> lacks by wrapping it, with a <a href="/DETECTOR-SPEC.html#harness">Harness</a> script or a
resolver of its own. The <a href="/SPEC.html#detector">Detector</a> together with that wrapping is then what the
<a href="/SPEC.html#practitioner">Practitioner</a> uses and what is judged.</p>
  </li>
  <li>
    <p><strong>How the wrapped pair is judged.</strong> Start from the gap, not from what the <a href="/SPEC.html#detector">Detector</a> does
well:</p>

    <table>
      <thead>
        <tr>
          <th>The <a href="/SPEC.html#detector">Detector</a>’s gap</th>
          <th>What the <a href="/SPEC.html#toolchain">Toolchain</a> adds</th>
          <th>This clause</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Resolves none of its <a href="/SPEC.html#identifier">Identifiers</a> offline</td>
          <td>A resolver keyed on the <a href="/SPEC.html#identifier">Identifier</a>, covering all of them</td>
          <td>Holds</td>
        </tr>
        <tr>
          <td>Two <a href="/DETECTOR-SPEC.html#bundled-rule">Bundled rules</a> have no documentation</td>
          <td>Nothing for those two</td>
          <td>Fails</td>
        </tr>
        <tr>
          <td>Two <a href="/DETECTOR-SPEC.html#bundled-rule">Bundled rules</a> have no documentation</td>
          <td>Its own documentation for those two, resolved by <a href="/SPEC.html#identifier">Identifier</a></td>
          <td>Holds</td>
        </tr>
      </tbody>
    </table>

    <p>Behind the table is one question. With the wrapping in place, does the <a href="/SPEC.html#detector">Detector</a>
meet every MUST of sections 4 to 7 of the <a href="/DETECTOR-SPEC.html">detector specification</a>,
exercised as its clause 8.1 describes? If yes, this clause holds. If no, it fails, however
much else the <a href="/SPEC.html#detector">Detector</a> does well, and whether or not a clause below names the same gap
again. Wrapping can add a mechanism; it cannot excuse a gap. The <a href="/SPEC.html#detector">Detector</a>’s own verdict
under that document is unchanged by the wrapping. Satisfying most of that document is not
<a href="/SPEC.html#conform">Conformance</a> to it, any more than satisfying most of this one is.</p>
  </li>
  <li>
    <p><strong>What cannot be wrapped.</strong> A <a href="/SPEC.html#detector">Detector</a> that cannot host a bespoke <a href="/SPEC.html#rule">Rule</a> at all
cannot be wrapped into <a href="/SPEC.html#conform">Conformance</a>, and no <a href="/SPEC.html#defence">Defence</a> is routed through it.
It MAY still run as one of the <a href="/SPEC.html#toolchain">Toolchain</a>’s checks, as a formatter or a
<a href="/SPEC.html#runner">Runner</a> does.</p>
  </li>
</ol>

<p><strong>Why</strong>: the six clauses of the method are carried out in a <a href="/SPEC.html#detector">Detector</a>, and everything this
document adds presumes those clauses can be followed there. A <a href="/SPEC.html#toolchain">Toolchain</a> that lists, records
and resolves beautifully around a <a href="/SPEC.html#detector">Detector</a> in which no <a href="/SPEC.html#rule">Rule</a> can be written or proven
has governed nothing. The wrapping is permitted because a <a href="/SPEC.html#practitioner">Practitioner</a> cannot tell where a
mechanism lives, and the method does not care. It is bounded because a mechanism the <a href="/SPEC.html#detector">Detector</a>
does not offer and the <a href="/SPEC.html#toolchain">Toolchain</a> does not add is a gap the project writes down under method
clause 3.2. A gap recorded is not a gap closed.</p>

<h3 id="42-the-toolchain-must-resolve-every-identifier-a-defence-it-routes-can-print-from-the-installed-copy-without-network-access">4.2 The toolchain MUST resolve every identifier a defence it routes can print, from the installed copy, without network access</h3>

<p>This clause widens the <a href="/DETECTOR-SPEC.html">detector specification</a>’s reach: that document resolves
<a href="/DETECTOR-SPEC.html#bundled-rule">Bundled rules</a> only, and this one covers every <a href="/SPEC.html#identifier">Identifier</a> the project can see, the project’s
own <a href="/SPEC.html#rule">Rules</a> included. The <a href="/SPEC.html#toolchain">Toolchain</a> MUST resolve every <a href="/SPEC.html#identifier">Identifier</a> its <a href="/SPEC.html#defence">Defences</a>
print to <a href="/SPEC.html#remediation-docs">Remediation docs</a> on disk. The mechanism is keyed on the
<a href="/SPEC.html#identifier">Identifier</a> exactly as printed, and it covers the project’s own <a href="/SPEC.html#rule">Rules</a> and
every <a href="#bundled-defence">Bundled defence</a>. Those <a href="/SPEC.html#remediation-docs">Remediation docs</a> MUST ship
with the project or with the <a href="/SPEC.html#toolchain">Toolchain</a>, at a version tracked together. The
<a href="/SPEC.html#toolchain">Toolchain</a> MUST also give that documentation a place to live, so that a
<a href="/DETECTOR-SPEC.html#rule-author">Rule author</a> adding a <a href="/SPEC.html#rule">Rule</a> knows where its documentation goes.
The documentation an <a href="/SPEC.html#identifier">Identifier</a> resolves to MUST state the correct construction, not
only the prohibition, specifically enough to act on, as method clause 8.4 requires. The
<a href="/SPEC.html#toolchain">Toolchain</a> supplies the place and the <a href="/DETECTOR-SPEC.html#rule-author">Rule author</a> the text.</p>

<p>This clause closes what the <a href="/DETECTOR-SPEC.html">detector specification</a> leaves open. That document holds a
<a href="/SPEC.html#detector">Detector</a> to on-disk resolution of its own <a href="/DETECTOR-SPEC.html#bundled-rule">Bundled rules</a> and
no further, because it cannot know a project’s documentation. A third-party <a href="/SPEC.html#detector">Detector</a>’s
native catalogue, which the <a href="/SPEC.html#toolchain">Toolchain</a> orchestrates without claiming as its own, is
resolved under that document’s clause 6 and is not re-shipped here.</p>

<p><strong>Why</strong>: method specification clauses 3.6 and 8.3. The <a href="/SPEC.html#identifier">Identifier</a> has to resolve for the
reader, which for an <a href="/SPEC.html#agent">Agent</a> means mechanically and on disk, and the project’s own
<a href="/SPEC.html#rule">Rules</a> are the ones that carry its knowledge. A <a href="/SPEC.html#detector">Detector</a> that prints the
<a href="/SPEC.html#identifier">Identifier</a> faithfully has done its half; a project in which that string then leads nowhere
has a <a href="/SPEC.html#message">Message</a> that names a pattern without leading anywhere, which method clause 3.6 says
does not <a href="/SPEC.html#conform">Conform</a>.</p>

<h3 id="43-the-toolchain-must-forbid-through-a-defence-of-its-own-every-suppression-route-that-bypasses-the-project-record">4.3 The toolchain MUST forbid, through a defence of its own, every suppression route that bypasses the project record</h3>

<p>A <a href="/SPEC.html#toolchain">Conforming</a> MUST disable every <a href="/SPEC.html#suppression">Suppression</a>
route that bypasses the <a href="#project-record">Project record</a>, or MUST run a <a href="/SPEC.html#defence">Blocking</a> that fails on its use. A route bypasses the <a href="#project-record">Project record</a> when a finding can be
silenced through it without an entry appearing in the <a href="#project-record">Project record</a>: an inline
<a href="/SPEC.html#suppression">Suppression</a> comment, a per-line ignore and a silently generated <a href="/SPEC.html#baseline">Baseline</a> all do. The
obligation covers every such route in every <a href="/SPEC.html#detector">Detector</a> the <a href="/SPEC.html#toolchain">Toolchain</a> routes a
<a href="/SPEC.html#defence">Defence</a> through. Irreducible cases MUST be directed to the <a href="#project-record">Project record</a>,
where clause 6.2 requires a justification.</p>

<p>The <a href="/DETECTOR-SPEC.html">detector specification</a> permits a <a href="/SPEC.html#detector">Detector</a> to ship such a route provided
it can be detected or disabled. This clause is where the project’s decision is made and enforced,
and the decision is no. A <a href="/SPEC.html#baseline">Baseline</a> an <a href="/SPEC.html#owner">Owner</a> has adopted under method section
4 is therefore kept in the <a href="#project-record">Project record</a>, or read from a file the record names. It is
never a file the <a href="/SPEC.html#detector">Detector</a> generates unseen.</p>

<p><strong>Why</strong>: a governance mechanism whose escape hatch is an unreviewed comment is not a governance
mechanism. This is the one place this document is stricter than the <a href="/SPEC.html#detector">Detectors</a> it assembles
are by default, and it is deliberate. The method specification’s position is that
<a href="/SPEC.html#suppression">Suppression</a> is an <a href="/SPEC.html#owner">Owner</a> decision, and an <a href="/SPEC.html#owner">Owner</a> cannot decide something
they are never shown. The <a href="/SPEC.html#detector">Detector</a> is not asked to forbid the route because the
<a href="/SPEC.html#detector">Detector</a> is not the project; the <a href="/SPEC.html#toolchain">Toolchain</a> is the project’s, so it is.</p>

<p>The reference implementations both do this. <code class="language-plaintext highlighter-rouge">ts-qa-ci</code> bans every <code class="language-plaintext highlighter-rouge">eslint-disable</code> and
<code class="language-plaintext highlighter-rouge">@ts-expect-error</code> form outright; <code class="language-plaintext highlighter-rouge">php-qa-ci</code>’s <code class="language-plaintext highlighter-rouge">ForbidInlinePhpstanIgnoreRule</code> bans inline
<code class="language-plaintext highlighter-rouge">@phpstan-ignore</code> and directs irreducible cases to the configuration file, where they are visible.</p>

<h3 id="44-the-toolchains-own-invocation-must-satisfy-the-detector-specifications-reporting-clauses-for-every-defence-it-routes">4.4 The toolchain’s own invocation MUST satisfy the detector specification’s reporting clauses for every defence it routes</h3>

<p>The <a href="/SPEC.html#toolchain">Toolchain</a>’s own entry point MUST meet clauses 5.1 to 5.4 of the
<a href="/DETECTOR-SPEC.html">detector specification</a> for every <a href="/SPEC.html#defence">Defence</a> it routes. That means invocable
locally with no infrastructure, over a subset down to one file, and with the result in the output of
the command the <a href="/SPEC.html#practitioner">Practitioner</a> ran. No <a href="/SPEC.html#defence">Defence</a> may be reportable only
through a mode they cannot run. The entry point MUST also print every <a href="/SPEC.html#identifier">Identifier</a> its
<a href="/SPEC.html#detector">Detectors</a> print, unaltered, so that clause 4.3 of the
<a href="/DETECTOR-SPEC.html">detector specification</a> holds through the wrapping. The <a href="/SPEC.html#detector">Detector</a>’s verdict
under that clause is on its own output. The <a href="/SPEC.html#toolchain">Toolchain</a>’s is on what reaches the
<a href="/SPEC.html#practitioner">Practitioner</a>.</p>

<p><strong>Why</strong>: method clause 3.5 asks the <a href="/SPEC.html#practitioner">Practitioner</a> to demonstrate enforcement through the
project’s own entry point, not through the <a href="/SPEC.html#detector">Detector</a> directly. A <a href="/SPEC.html#detector">Detector</a> that
<a href="/SPEC.html#conform">Conforms</a> on its own, wrapped in a command that runs only the whole codebase or only
elsewhere, has had its reporting clauses undone by the wrapping. The <a href="/SPEC.html#practitioner">Practitioner</a>
is then back to a loop that does not close.</p>

<h3 id="45-the-toolchains-entry-point-must-run-detectors-before-runners-and-must-stop-on-a-detector-failure">4.5 The toolchain’s entry point MUST run detectors before runners, and MUST stop on a detector failure</h3>

<p>The invocation the project uses to accept changes MUST evaluate its <a href="/SPEC.html#detector">Detectors</a> before its
<a href="/SPEC.html#runner">Runners</a>. A <a href="/SPEC.html#blocking">Blocking</a> failure at the <a href="/SPEC.html#detector">Detector</a> level MUST stop the
levels below it from being treated as meaningful, in the order method section 5 states. How the
project expresses that sequence, and where it runs, remain out of scope under method section 8.</p>

<p><strong>Why</strong>: method section 5. A <a href="/SPEC.html#detector">Detector</a> is preventive and a test is diagnostic, and a failure
at the <a href="/SPEC.html#detector">Detector</a> level produces confusing results at every level above it. The method makes
the ordering a property of the project rather than of any one remediation, which is exactly the
kind of property that lives in the <a href="/SPEC.html#toolchain">Toolchain</a> and nowhere else.</p>

<h2 id="5-enumeration">5. Enumeration</h2>

<h3 id="51-the-toolchain-must-be-able-to-list-the-defences-active-in-a-project-without-triggering-them">5.1 The toolchain MUST be able to list the defences active in a project, without triggering them</h3>

<p>The <a href="/SPEC.html#toolchain">Toolchain</a> MUST list every <a href="/SPEC.html#defence">Defence</a> active in the project without
running it. The listing MUST include each <a href="/SPEC.html#defence">Defence</a>’s <a href="/SPEC.html#identifier">Identifier</a> and a terse
statement of what it forbids or requires. It MUST provide the route to each one’s full documentation.</p>

<p><strong>Why</strong>: method specification clause 8.5. An <a href="/SPEC.html#agent">Agent</a> arriving at a codebase has no colleague to ask.
Without a listing, a project’s standards can only be learned by violating them one at a time.</p>

<h3 id="52-the-listing-must-be-derived-from-the-active-configuration">5.2 The listing MUST be derived from the active configuration</h3>

<p>The listing MUST be generated from the configuration the <a href="/SPEC.html#toolchain">Toolchain</a> actually loads. It
MUST NOT be a hand-maintained document that happens to describe the configuration. The test is
what happens when a <a href="/SPEC.html#rule">Rule</a> is added to the configuration and nobody edits anything else: a
derived listing shows it on the next run, and a hand-maintained one does not.</p>

<p><strong>Why</strong>: a hand-maintained list drifts, and it drifts silently and in the dangerous direction. The
observed failure is a <a href="/SPEC.html#rule">Rule</a> that is registered, active, <a href="/SPEC.html#blocking">Blocking</a>, and absent from
the list of <a href="/SPEC.html#rule">Rules</a>, whilst the list states its own count with confidence. A derived listing cannot
diverge from what is enforced, because the thing enforced is what produced it.</p>

<h3 id="53-a-projects-own-defences-must-appear-in-the-listing-alongside-bundled-ones">5.3 A project’s own defences MUST appear in the listing alongside bundled ones</h3>

<p>Every <a href="/SPEC.html#defence">Defence</a> the project has written itself MUST appear in the listing clause 5.1
requires, meeting its content requirements in full, alongside every <a href="#bundled-defence">Bundled defence</a>.</p>

<p>For a shipped <a href="/SPEC.html#toolchain">Toolchain</a>, the case is the same and worth spelling out. Its
<a href="/SPEC.html#defence">Defences</a> against its own source, the ones only its contributors can trigger, are that
project’s own <a href="/SPEC.html#defence">Defences</a> for this purpose. They MUST appear in the same listing clause 5.1
requires, meeting its content requirements in full, when the <a href="/SPEC.html#toolchain">Toolchain</a> is run on itself,
however they are enabled. Where such a <a href="/SPEC.html#defence">Defence</a> is not expressible in the <a href="/SPEC.html#toolchain">Toolchain</a>’s own
<a href="/SPEC.html#detector">Detectors</a>, its entry MAY be sourced from wherever it is enabled, provided the listing stays derived
under clause 5.2 rather than hand-maintained.</p>

<p><strong>Why</strong>: the <a href="/SPEC.html#practitioner">Practitioner</a> does not care which package a <a href="/SPEC.html#rule">Rule</a> came from. They care what defends the
code in front of them, and a listing that covers only what a shipped <a href="/SPEC.html#toolchain">Toolchain</a> carries describes somebody
else’s project.</p>

<h2 id="6-the-project-record">6. The project record</h2>

<p>This section exists because of a gap found by cold readers of the method specification, repeatedly
and independently. The method delegates several judgements to project level, and a <a href="/SPEC.html#practitioner">Practitioner</a>
arriving at a project that has recorded none of them has no legal move. That is a <a href="/SPEC.html#toolchain">Toolchain</a>
obligation. The method specification cannot fix it, because the method specification does not own a
file in the project.</p>

<h3 id="61-the-toolchain-must-define-a-location-for-the-project-record-and-must-read-it-itself">6.1 The toolchain MUST define a location for the project record, and MUST read it itself</h3>

<p>The <a href="/SPEC.html#toolchain">Toolchain</a> MUST define where the <a href="#project-record">Project record</a> lives and MUST load it.
Not a documentation convention. A path the <a href="/SPEC.html#toolchain">Toolchain</a> loads.</p>

<p><strong>Why</strong>: a <a href="#project-record">Project record</a> the <a href="/SPEC.html#toolchain">Toolchain</a> does not read can be wrong without anything noticing.
When the <a href="/SPEC.html#toolchain">Toolchain</a> reads it, the written decision and the enforced decision are the same object, and
neither can drift from the other.</p>

<h3 id="62-every-exception-in-the-project-record-must-carry-a-written-justification-that-names-the-hazard-and-the-scope-and-the-toolchain-must-reject-a-generic-one">6.2 Every exception in the project record MUST carry a written justification that names the hazard and the scope, and the toolchain MUST reject a generic one</h3>

<p>The <a href="/SPEC.html#toolchain">Toolchain</a> MUST require a written justification on every <a href="/SPEC.html#exception">Exception</a>.
It MUST NOT supply a default, and MUST reject an <a href="/SPEC.html#exception">Exception</a> that omits one. A field that is
merely present and non-empty does not satisfy this clause.</p>

<p><strong>Why</strong>: an <a href="/SPEC.html#exception">Exception</a> without a reason is indistinguishable from an <a href="/SPEC.html#exception">Exception</a> nobody would defend,
and the person who could tell them apart is usually gone. Requiring the sentence is the whole
mechanism: it costs the author a minute at the moment they have the reason in mind, and it is the
only thing that makes an <a href="/SPEC.html#exception">Exception</a> reviewable later.</p>

<p><code class="language-plaintext highlighter-rouge">ts-qa-ci</code>’s <code class="language-plaintext highlighter-rouge">tier-a-exemptions.json</code> is the reference implementation, and its own two entries
demonstrate the standard: both explain the scope limit as well as the reason.</p>

<p>The justification MUST name the <a href="/SPEC.html#hazard">Hazard</a> being accepted and the scope of the <a href="/SPEC.html#exception">Exception</a>.
The <a href="/SPEC.html#toolchain">Toolchain</a> MUST reject a justification that could be pasted onto any <a href="/SPEC.html#exception">Exception</a>
unchanged, by a check it documents: “needed for now”, “legacy”, “TODO” and their like. That check
cannot verify truth, and a <a href="/SPEC.html#toolchain">Toolchain</a>’s <a href="/SPEC.html#conform">Conformance</a> MUST NOT be read as having verified
it. Whether the sentence is true is the <a href="/SPEC.html#owner">Owner</a>’s judgement under section 4 of the method
specification. That is why clause 6.3 puts every justification in one listing, where a vacuous
one is seen next to its neighbours.</p>

<h3 id="63-the-project-record-must-be-enumerable-by-the-same-means-as-the-defences">6.3 The project record MUST be enumerable by the same means as the defences</h3>

<p>Listing the <a href="/SPEC.html#defence">Defences</a> and listing the <a href="#project-record">Project record</a> MUST be the same kind of operation.</p>

<p><strong>Why</strong>: method specification clause 8.7. A decision nobody can find will be re-opened by every
<a href="/SPEC.html#practitioner">Practitioner</a> who arrives after it, which converts a settled question into a recurring one.</p>

<h3 id="64-the-toolchain-should-state-its-own-defaults-for-anything-the-method-leaves-to-the-project">6.4 The toolchain SHOULD state its own defaults for anything the method leaves to the project</h3>

<p>Where the method specification delegates a judgement and the project has recorded nothing, a
documented <a href="/SPEC.html#toolchain">Toolchain</a> default is what the <a href="/SPEC.html#practitioner">Practitioner</a> falls back to.</p>

<p><strong>Why</strong>: this is the deadlock this section exists to break. “The project decides” combined with “the
project has decided nothing” leaves an <a href="/SPEC.html#agent">Agent</a> choosing between guessing and stopping. A default
turns the first project-level decision from a prerequisite into a refinement, and the project’s
first day is exactly when it has recorded least and can afford the interruption least.</p>

<h2 id="7-agent-context">7. Agent context</h2>

<h3 id="71-the-toolchain-should-generate-a-summary-of-the-active-defences-suitable-for-an-agents-context">7.1 The toolchain SHOULD generate a summary of the active defences suitable for an agent’s context</h3>

<p>One terse line per <a href="/SPEC.html#defence">Defence</a>, phrased as a standing instruction rather than as a failure report, each
carrying its <a href="/SPEC.html#identifier">Identifier</a> and the route to its documentation. Generated from the active configuration,
per clause 5.2.</p>

<h3 id="72-the-toolchain-should-deliver-that-summary-into-the-project-automatically">7.2 The toolchain SHOULD deliver that summary into the project automatically</h3>

<p>Into the file the project’s <a href="/SPEC.html#agent">Agents</a> already load, refreshed on install and update, in a delimited
region marked as generated.</p>

<p><strong>Why</strong>: 7.1 and 7.2 are separate clauses because they are separately missed, and the two reference
implementations miss opposite halves. <code class="language-plaintext highlighter-rouge">php-qa-ci</code> writes an auto-generated, auto-refreshed block
into every <a href="#consuming-project">Consuming project</a>’s <a href="/SPEC.html#agent">Agent</a> instructions and does not put a <a href="/SPEC.html#rule">Rule</a> table in it; <code class="language-plaintext highlighter-rouge">ts-qa-ci</code>
maintains an excellent <a href="/SPEC.html#rule">Rule</a> catalogue and has no mechanism to deliver it. Each has built the half
the other lacks. A summary that exists but is never loaded and a delivery channel carrying
everything except the <a href="/SPEC.html#rule">Rules</a> are the same outcome from opposite directions.</p>

<p>Together these are the only clauses in any of the three specifications that operate <strong>before</strong> the
mistake rather than after it, which is why they are worth stating even as SHOULDs.</p>

<h2 id="8-self-audit">8. Self-audit</h2>

<h3 id="81-a-shipped-toolchain-must-fail-its-own-release-if-a-bundled-defence-lacks-resolvable-documentation">8.1 A shipped toolchain MUST fail its own release if a bundled defence lacks resolvable documentation</h3>

<p>The <a href="/SPEC.html#toolchain">Toolchain</a> MUST run an automated check that blocks its own release. The check covers
every <a href="/SPEC.html#identifier">Identifier</a> printed by a <a href="/SPEC.html#rule">Rule</a> the <a href="/SPEC.html#toolchain">Toolchain</a> authors or bundles
as its own <a href="/SPEC.html#defence">Defence</a>, whatever kind of <a href="/SPEC.html#detector">Detector</a> carries it. Where a documentation
page covers a family of <a href="/SPEC.html#identifier">Identifiers</a> by a pattern, as clause 6.5 of the
<a href="/DETECTOR-SPEC.html">detector specification</a> allows, this audit MUST apply the pattern to every
<a href="/SPEC.html#identifier">Identifier</a> printed and confirm it lands on that page. A member added to the
<a href="/SPEC.html#rule">Rule</a> and not to the page then fails the release. A third-party <a href="/SPEC.html#detector">Detector</a>’s
native catalogue, which the <a href="/SPEC.html#toolchain">Toolchain</a> orchestrates without claiming as its own, is outside
this audit; whether that catalogue resolves is judged under the
<a href="/DETECTOR-SPEC.html">detector specification</a>’s clause 6, as clause 4.2 says. The <a href="/DETECTOR-SPEC.html">detector specification</a>’s
clause 6.3 names the dangling reference as the failure to guard against above all others. This is the
guard, and a <a href="/SPEC.html#toolchain">Toolchain</a> is not held to less than it holds its
<a href="/SPEC.html#practitioner">Practitioners</a> to.</p>

<p><strong>Why</strong>: clause 4.2 is the clause most easily believed to be satisfied whilst being broken, because
the documentation is written by the same person who wrote the <a href="/SPEC.html#rule">Rule</a> and its absence is invisible from
the inside. A check that blocks the release is the difference between honouring the clause and asserting it.
It is also the method applied to the <a href="/SPEC.html#toolchain">Toolchain</a>: the <a href="/SPEC.html#class">Class</a> of <a href="/SPEC.html#defect">Defect</a> is “a <a href="/SPEC.html#rule">Rule</a> that blocks without
explaining”, and it is detectable mechanically.</p>

<h3 id="82-a-shipped-toolchain-must-run-its-own-bundled-defences-on-its-own-source">8.2 A shipped toolchain MUST run its own bundled defences on its own source</h3>

<p>Every <a href="/SPEC.html#rule">Rule</a> the <a href="/SPEC.html#toolchain">Toolchain</a> ships to <a href="#consuming-project">Consuming projects</a> MUST also be
active when the <a href="/SPEC.html#toolchain">Toolchain</a> analyses itself, and a <a href="/SPEC.html#toolchain">Toolchain</a> release MUST fail when
they are not.</p>

<p><strong>Why</strong>: a mechanism that delivers <a href="/SPEC.html#rule">Rules</a> to installed packages and not to the root package
leaves the <a href="/SPEC.html#toolchain">Toolchain</a> as the one project in which its own <a href="/SPEC.html#defence">Defences</a> never run. A
<a href="/SPEC.html#defect">Defect</a> in a <a href="/SPEC.html#rule">Rule</a>’s own code then goes unseen by every <a href="/SPEC.html#rule">Rule</a> built to see it. A
self-check that reports clean is believed, by the <a href="/DETECTOR-SPEC.html#rule-author">Rule author</a> and by anyone checking their
work, because nobody expects a clean run to have run nothing. This clause was found by an execution
test in which both the <a href="/SPEC.html#practitioner">Practitioner</a> and the reviewer cited exactly such a run as evidence.</p>

<p>A project that assembles its <a href="/SPEC.html#toolchain">Toolchain</a> without shipping it has no release and no
<a href="#consuming-project">Consuming project</a>, so this section does not bear on its <a href="/SPEC.html#conform">Conformance</a>. Its
own <a href="/SPEC.html#defence">Defences</a> already run on its own source, because that is the only source there is.</p>

<h2 id="9-conformance">9. Conformance</h2>

<p><strong>A project’s <a href="/SPEC.html#conform">Toolchain</a></strong> if every MUST in sections 4 to 6 holds across the
assembled parts, wherever each part came from, and, where the <a href="/SPEC.html#toolchain">Toolchain</a> is one the project
ships, every MUST in section 8 as well. The project-level verdict is a single grade; which part of the
<a href="/SPEC.html#toolchain">Toolchain</a> satisfied each clause is evidence for that grade, not a second grade.</p>

<p><strong>A <a href="/SPEC.html#conform">Toolchain</a> with <a href="/SPEC.html#agent">Agent</a> support</strong> if it additionally satisfies section 7.</p>

<p>Partial <a href="/SPEC.html#conform">Conformance</a> MUST NOT be described as <a href="/SPEC.html#conform">Conformance</a>. A <a href="/SPEC.html#toolchain">Toolchain</a> that satisfies most of this
document is in a normal and respectable condition; it is not <a href="/SPEC.html#conform">Conforming</a>, and describing it as such
removes the only value the word has.</p>

<h3 id="91-a-project-that-ships-a-detector-or-a-toolchain-has-two-levels-of-conformance-graded-separately">9.1 A project that ships a detector or a toolchain has two levels of conformance, graded separately</h3>

<p>As a project, it follows the method with its own assembled <a href="/SPEC.html#toolchain">Toolchain</a>, like any other
project, and is graded against this document and section 7 of the method specification on that
basis. As an artefact, what it ships is graded for its consumers: a <a href="/SPEC.html#detector">Detector</a> against
<a href="/DETECTOR-SPEC.html">the detector specification</a>, a <a href="/SPEC.html#toolchain">Toolchain</a> against this document as it stands
when installed into a <a href="#consuming-project">Consuming project</a> with nothing else built around it. The two
verdicts MUST be graded and declared separately, and neither implies the other. Section 8 bears on
the artefact grade only; clause 5.3 is its project-level counterpart.</p>

<p><strong>Why</strong>: the two questions have different readers. A contributor to the artefact wants to know
whether the project practises what it ships; a <a href="#consuming-project">Consuming project</a> wants to know what it
will get. A single grade answers neither, and the observed failure is a <a href="/SPEC.html#toolchain">Toolchain</a> whose own
source was the one place its <a href="#bundled-defence">Bundled defences</a> never ran, which a consumer-facing grade
alone would never have shown.</p>

<h3 id="92-the-declaration-is-the-claim-and-the-known-gap-record-not-a-condition-of-conformance">9.2 The declaration is the claim and the known-gap record, not a condition of conformance</h3>

<p>A project or a shipped artefact MAY declare, machine-readably in whatever form its ecosystem uses to
record dependencies, the version of the method specification it follows and the version of this
document or the <a href="/DETECTOR-SPEC.html">detector specification</a> it <a href="/SPEC.html#conform">Conforms</a> to. A project that ships an artefact
carries both levels of clause 9.1 in that declaration, each named separately.</p>

<p>The same declaration is where a gap is recorded once it is known. A project or artefact that has
learnt that it fails a MUST of the document it declares against MUST record that gap alongside the
version, in the same file or one it names. It may learn that from its own self-audit under section 8
or from a <a href="/SPEC.html#practitioner">Practitioner</a>’s report under the method’s clause 3.2. A declaration with a non-empty gap record is
a statement of where the project stands and is not a claim of <a href="/SPEC.html#conform">Conformance</a>. A mechanism gap
is by its nature one the <a href="/SPEC.html#toolchain">Toolchain</a> could not detect for itself, so the record is the only
place its <a href="/SPEC.html#owner">Owner</a> and its consumers can learn of it.</p>

<p>The declaration is optional. A <a href="/SPEC.html#toolchain">Toolchain</a> assembled before this document existed, or by a
project that has never read it, MAY be graded <a href="/SPEC.html#conform">Conforming</a> on evidence by anyone who exercises
the clauses above against it. A verdict on any <a href="/SPEC.html#toolchain">Toolchain</a>, declared or not, rests on that
exercise and not on the claim.</p>

<p><strong>Why</strong>: a <a href="/SPEC.html#conform">Conformance</a> claim in a README is a sentence; a <a href="/SPEC.html#conform">Conformance</a> claim in a manifest is a fact
about a specific installed artefact, checkable by anyone, including mechanically. It also fixes what
“<a href="/SPEC.html#conform">Conforming</a>” meant at the point the claim was made, which a claim against a moving document cannot.
Making the claim a condition, though, would grade the maintainer’s reading rather than the
<a href="/SPEC.html#toolchain">Toolchain</a>, and would leave every project that met the method before hearing of it unable
to say so.</p>

<h2 id="10-relationship-to-the-method-and-detector-specifications">10. Relationship to the method and detector specifications</h2>

<p>This document adds no obligations to a <a href="/SPEC.html#practitioner">Practitioner</a> and relaxes none. Every clause here exists to
make a clause of the method specification achievable.</p>

<p>Where this document and the method specification disagree, the method specification governs. It
describes the method, which is the thing being specified; this describes the equipment. Where this
document and the <a href="/DETECTOR-SPEC.html">detector specification</a> disagree about a <a href="/SPEC.html#detector">Detector</a>, the <a href="/DETECTOR-SPEC.html">detector specification</a>
governs, because it is the document a <a href="/SPEC.html#detector">Detector</a>’s maintainer works from; what this document
asks of a <a href="/SPEC.html#detector">Detector</a> is that it <a href="/SPEC.html#conform">Conform</a> there.</p>

<p>Nothing here requires a project to use a <a href="/SPEC.html#toolchain">Conforming</a> shipped by anyone. A project can <a href="/SPEC.html#conform">Conform</a> to the method
specification on a <a href="/SPEC.html#toolchain">Toolchain</a> that <a href="/SPEC.html#conform">Conforms</a> to none of this, at the cost of building the missing
mechanisms itself, and once built they are its <a href="/SPEC.html#toolchain">Toolchain</a> and are graded here. This document exists so that it
does not have to build them alone.</p>

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
