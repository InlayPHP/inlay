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

# actions/checkout persists a bot Authorization extraheader in the clone. It
# takes precedence over credentials in remote_url, so remove it before using
# the mirror PAT. This is separate from credential.helper and must be cleared
# explicitly.
git config --local --unset-all http.https://github.com/.extraheader 2>/dev/null || true

split_sha="$(git subtree split --prefix="$PACKAGE_DIRECTORY")"
remote_url="https://x-access-token:${SPLIT_TOKEN}@github.com/InlayPHP/${SPLIT_REPOSITORY}.git"

echo "Split ${PACKAGE_DIRECTORY} at ${split_sha} into InlayPHP/${SPLIT_REPOSITORY}.git"

if [[ "${SPLIT_DRY_RUN:-0}" == '1' ]]; then
    exit 0
fi

# Mirrors are generated, read-only repositories. Force-updating main keeps them
# deterministic while preserving the full package-only history produced by
# `git subtree split`. Release tags are mirrored explicitly for Composer.
# checkout persists the workflow bot credential in git's helper. Clear it for
# these commands so the mirror PAT embedded in remote_url is authoritative.
git -c credential.helper= push --force "$remote_url" "${split_sha}:refs/heads/main"

if [[ -n "${SPLIT_TAG:-}" ]]; then
    # Packagist indexes annotated release tags reliably. Create the tag object
    # locally, push that object to the mirror, then remove the temporary ref.
    temporary_tag="inlay-split-${SPLIT_REPOSITORY}-${SPLIT_TAG}"
    git tag --force --annotate "$temporary_tag" "$split_sha" --message "$SPLIT_TAG"
    tag_sha="$(git rev-parse "$temporary_tag")"
    git -c credential.helper= push --force "$remote_url" "${tag_sha}:refs/tags/${SPLIT_TAG}"
    git tag --delete "$temporary_tag" >/dev/null
fi
