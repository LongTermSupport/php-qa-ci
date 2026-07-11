#!/usr/bin/env bash
#
# ci-push-ssh-deploy-bundle.bash — provision GitHub Actions with a project's
# private first-party deploy keys, for the QA CI workflow (qa-autofix.yml).
#
# WHY: composer.lock pins private first-party packages (e.g. ballicom/*) to SSH
# host aliases (github_deploy_*) that exist only in a developer's ~/.ssh/config.
# CI's GITHUB_TOKEN cannot read sibling private repos, so `composer install` fails
# there. Rather than mint a PAT, reuse the existing per-repo READ-ONLY deploy keys:
# bundle them into ONE repository secret (CI_SSH_DEPLOY_BUNDLE) that the workflow's
# "Install SSH deploy keys" step unpacks.
#
# Run it from a dev container that already holds the deploy keys. RE-RUNNABLE: it
# re-discovers the aliases from composer.lock each time, so after adding a new
# private first-party dep just run it again — no workflow edits needed (the
# workflow loops over whatever keys are in the bundle).
#
# Requires: gh (authenticated, admin on the repo), jq, the deploy keys in ~/.ssh.

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

# Resolve the GitHub OWNER/REPO this checkout publishes to. origin may use an SSH
# host alias (github_deploy_*:OWNER/REPO.git) or plain https — take the last two
# path segments via awk, dropping any ".git" suffix.
originUrl="$(git config --get remote.origin.url)"
repo="$(printf '%s\n' "$originUrl" | awk '{ sub(/\.git$/, ""); n = split($0, p, /[:\/]/); print p[n-1] "/" p[n] }')"
echo "Target repo: $repo"

# Discover the github_deploy_* aliases this project's lock depends on.
# SSoT: this discovery expression is mirrored INLINE (in BOTH jobs) by the
# "Verify deploy keys cover every private dep" step in
# templates/github-actions/qa-autofix.yml — that verifier must run before
# `composer install` (so it cannot call this vendored script). Keep the jq +
# grep in sync across all three copies if you ever change the alias grammar.
mapfile -t aliases < <(
  jq -r '(.packages + (.["packages-dev"] // []))[].source.url // empty' composer.lock \
    | grep -oE '^github_deploy_[a-z0-9_]+' | sort -u
)
if [ "${#aliases[@]}" -eq 0 ]; then
  echo "No github_deploy_* aliases found in composer.lock — nothing to provision."
  echo "(All dependencies are public; the workflow's SSH step will be a no-op.)"
  exit 0
fi

# Bundle each alias's PRIVATE key. Keys must exist locally and be passphrase-less
# (CI cannot enter a passphrase). These are READ-ONLY deploy keys.
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
for alias in "${aliases[@]}"; do
  key="$HOME/.ssh/$alias"
  if [ ! -f "$key" ]; then
    echo "ERROR: missing key $key — expected from the ~/.ssh/config alias '$alias'." >&2
    exit 1
  fi
  if grep -q "ENCRYPTED" "$key"; then
    echo "ERROR: $key is passphrase-protected; CI needs a passphrase-less deploy key." >&2
    exit 1
  fi
  cp "$key" "$tmp/$alias"
  echo "  bundling $alias"
done

# One secret = base64(tar of the key files). The workflow unpacks it into ~/.ssh
# and recreates the matching host-alias config.
tar -czf - -C "$tmp" . | base64 -w0 | gh secret set CI_SSH_DEPLOY_BUNDLE --repo "$repo"

echo "Pushed CI_SSH_DEPLOY_BUNDLE to $repo (${#aliases[@]} key(s): ${aliases[*]})"
