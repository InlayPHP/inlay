#!/usr/bin/env bash
# Creates the read-only mirror repositories the split workflow pushes to.
#
# The "[READ ONLY]" marker is a repository description, not a GitHub feature — the
# same convention established Laravel ecosystems use. What keeps them read only is that the
# split force-pushes over anything committed there, and that nobody but the splitter
# has push access. Issues are disabled so a bug report lands upstream where the code
# is; GitHub offers no way to disable pull requests, so a template redirects them.
#
# Idempotent: an existing repository has its description and settings corrected
# rather than being recreated. Run it again after adding a package.
set -euo pipefail

ORG="${INLAY_SPLIT_ORG:-inlayphp}"

if ! command -v gh >/dev/null 2>&1; then
    echo "The GitHub CLI is required: https://cli.github.com" >&2
    exit 1
fi

create() {
    local repo="$1" description="$2"

    if gh repo view "$ORG/$repo" >/dev/null 2>&1; then
        echo "updating  $ORG/$repo"
    else
        echo "creating  $ORG/$repo"
        gh repo create "$ORG/$repo" --public --description "$description"
    fi

    gh repo edit "$ORG/$repo" \
        --description "$description" \
        --homepage "https://github.com/$ORG/inlay" \
        --enable-issues=false \
        --enable-projects=false \
        --enable-wiki=false >/dev/null
}

create actions "[READ ONLY] Subtree split of Reusable action contracts for Laravel and Inlay components (see inlayphp/inlay)"
create authorization-spatie "[READ ONLY] Subtree split of Spatie Laravel Permission synchronization and super-admin integration for Inlay (see inlayphp/inlay)"
create authorization "[READ ONLY] Subtree split of Vendor-neutral Laravel Gate and Policy authorization for Inlay resources and panels (see inlayphp/inlay)"
create core "[READ ONLY] Subtree split of Plugin, extension, asset, and render hook contracts for Inlay (see inlayphp/inlay)"
create design "[READ ONLY] Subtree split of Inlay design tokens, recipes, primitives, and theme integration (see inlayphp/inlay)"
create forms "[READ ONLY] Subtree split of Schema-driven forms for Laravel and Inertia (see inlayphp/inlay)"
create imports "[READ ONLY] Subtree split of Validated import previews and row processing for Laravel and Inlay (see inlayphp/inlay)"
create infolists "[READ ONLY] Subtree split of Renderer-neutral read-only infolist schemas for Laravel and Inertia (see inlayphp/inlay)"
create media-manager "[READ ONLY] Subtree split of Authorized media browser and picker backend plugin for Inlay panels (see inlayphp/inlay)"
create media-spatie "[READ ONLY] Subtree split of Zero-copy Spatie Media Library bridge for the Inlay media catalog (see inlayphp/inlay)"
create media "[READ ONLY] Subtree split of Secure, framework-grade media catalog and upload domain for Inlay (see inlayphp/inlay)"
create notifications "[READ ONLY] Subtree split of Session-backed Inertia notifications for Inlay applications (see inlayphp/inlay)"
create panels "[READ ONLY] Subtree split of PHP-first administration panels, authentication, dashboards, navigation, and routing for Laravel and Inertia (see inlayphp/inlay)"
create permission-manager "[READ ONLY] Subtree split of Dcat-inspired role and permission administration plugin for Inlay panels (see inlayphp/inlay)"
create resources "[READ ONLY] Subtree split of Fluent Laravel resource orchestration for Inlay forms, tables, infolists, and panels (see inlayphp/inlay)"
create schemas "[READ ONLY] Subtree split of Renderer-neutral schema components and layout primitives for Inlay (see inlayphp/inlay)"
create support "[READ ONLY] Subtree split of Shared serializable contracts for Inlay packages (see inlayphp/inlay)"
create tables "[READ ONLY] Subtree split of Schema-driven tables for Laravel and Inertia (see inlayphp/inlay)"
create tables-xlsx "[READ ONLY] Subtree split of the optional PhpSpreadsheet XLSX export driver for Inlay tables (see inlayphp/inlay)"
create theme "[READ ONLY] Subtree split of Shared semantic theme contracts and presets for Inlay applications (see inlayphp/inlay)"
create two-factor-authentication "[READ ONLY] Subtree split of Optional TOTP, recovery-code, and panel challenge flows for Inlay (see inlayphp/inlay)"
create validation "[READ ONLY] Subtree split of Reusable Laravel validation classes for forms, requests, imports, APIs, and actions (see inlayphp/inlay)"
create widgets "[READ ONLY] Subtree split of PHP-first dashboard widgets for Inlay panels and Inertia applications (see inlayphp/inlay)"

echo
echo "Done. Two things the CLI cannot do:"
echo "  - Pull requests cannot be disabled on GitHub. Commit a PULL_REQUEST_TEMPLATE"
echo "    to each mirror, or accept that they arrive and close them with a pointer."
echo "  - Packagist needs each mirror submitted once, then set to auto-update."
