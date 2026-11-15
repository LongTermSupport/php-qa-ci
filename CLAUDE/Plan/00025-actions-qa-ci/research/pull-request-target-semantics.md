# `pull_request_target`: what GitHub documents, for the up-to-date-with-base gate

Step 0 of the stand-in's ruling on [#467](https://github.com/LongTermSupport/php-qa-ci/issues/467):
the event's semantics, taken from GitHub's own pages, captured verbatim-sourced into
`remote-docs/` with provenance. Each point names the page it comes from.

## What the event runs against

From [events that trigger
workflows](../../../../remote-docs/docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows.md):

- The event "runs in the context of the default branch of the base repository, rather than in the
  context of the merge commit, as the `pull_request` event does"; its `GITHUB_SHA` is the last
  commit on the default branch and its `GITHUB_REF` is the default branch.
- Its webhook payload is the `pull_request` payload, so `github.event.pull_request.base.ref`,
  `.head.sha` and `.number` are populated as they are for `pull_request`. The same page says that
  payload "is empty for merged pull requests and pull requests that come from forked
  repositories" under its `pull_request` section; nothing on it says that applies to
  `pull_request_target`, which is the event that exists to run for a fork, and the table gives both
  events the same payload.
- By default it runs for `opened`, `synchronize` and `reopened`, and it runs even when the pull
  request has a merge conflict, which `pull_request` does not.
- The page advises against the event "if you need to build or run code from the pull request".

From [securely using
`pull_request_target`](../../../../remote-docs/docs.github.com/en/actions/reference/security/securely-using-pull_request_target.md):

- The workflow, "and any subsequent `actions/checkout` call that does not specify a `ref`, is taken
  from the base repository's default branch, not from the pull request".
- The hazard is running pull request code with the base repository's token and secrets: checking
  out the head (`ref: ${{ github.event.pull_request.head.sha }}`) or fetching it with `git fetch`
  "and then running it". Reading the fetched commit as data, never executing it, is the safe use.

## How the head commit is made present without a checkout

From [checking out pull requests
locally](../../../../remote-docs/docs.github.com/en/pull-requests/collaborating-with-pull-requests/reviewing-changes-in-pull-requests/checking-out-pull-requests-locally.md):
`git fetch origin pull/ID/head:BRANCH_NAME` fetches a pull request's head from the base repository,
whether it comes from a branch or a fork. A fetch without a destination ref leaves the commit in
`FETCH_HEAD`, and a checkout is not needed to ask whether one commit is an ancestor of another.

## What follows for the gate

- The default checkout of a `pull_request_target` run is the base branch, so `HEAD_SHA` is not in
  the clone unless it is fetched; for a fork it is never there.
- The shipped gate step's `if: github.event_name == 'pull_request'` is false on this event, so a
  copy of that step into such a workflow never runs.
- The construction for this event is: fetch the base branch and the pull request head, do not check
  out the head, and fail when `origin/<base>` is not an ancestor of the fetched head.
