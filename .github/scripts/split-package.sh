#!/usr/bin/env bash

set -euo pipefail

: "${SPLIT_TOKEN:?SPLIT_TOKEN secret is required to publish package mirrors}"
: "${PACKAGE_DIRECTORY:?PACKAGE_DIRECTORY is required}"
: "${SPLIT_REPOSITORY:?SPLIT_REPOSITORY is required}"

if [[ "$PACKAGE_DIRECTORY" != packages/* || "$PACKAGE_DIRECTORY" == */ || ! -d "$PACKAGE_DIRECTORY" ]]; then
    echo "Invalid package directory: ${PACKAGE_DIRECTORY}" >&2
    exit 64
fi

if [[ ! "$SPLIT_REPOSITORY" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo "Invalid split repository: ${SPLIT_REPOSITORY}" >&2
    exit 64
fi

git config user.name "solutionforestteam"
git config user.email "solutionforestteam@users.noreply.github.com"

token_login="$(curl --fail --silent --show-error \
    --header "Authorization: Bearer ${SPLIT_TOKEN}" \
    --header 'Accept: application/vnd.github+json' \
    https://api.github.com/user | sed -n 's/.*"login"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)"

if [[ -z "$token_login" ]]; then
    echo 'SPLIT_TOKEN did not authenticate as a GitHub user.' >&2
    exit 78
fi

echo "Mirror token identity: ${token_login}"

split_sha="$(git subtree split --prefix="$PACKAGE_DIRECTORY")"
remote_url="https://x-access-token:${SPLIT_TOKEN}@github.com/InlayPHP/${SPLIT_REPOSITORY}.git"

echo "Split ${PACKAGE_DIRECTORY} at ${split_sha} into InlayPHP/${SPLIT_REPOSITORY}.git"

if [[ "${SPLIT_DRY_RUN:-0}" == '1' ]]; then
    exit 0
fi

# Mirrors are generated, read-only repositories. Force-updating main keeps them
# deterministic while preserving the full package-only history produced by
# `git subtree split`. Release tags are mirrored explicitly for Composer.
git push --force "$remote_url" "${split_sha}:refs/heads/main"

if [[ -n "${SPLIT_TAG:-}" ]]; then
    git push --force "$remote_url" "${split_sha}:refs/tags/${SPLIT_TAG}"
fi
