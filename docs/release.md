# Inlay release checklist

Inlay is released from the monorepo. PHP packages are mirrored into the
`InlayPHP` organization by the split workflow, while frontend packages remain
published from this repository under the `@inlayphp/*` scope. Keep a PHP tag,
the Composer packages, and the matching frontend packages on the same release
line so the server and renderer contracts cannot drift.

## Before a tag

Run the same gates used by CI from the repository root:

```bash
composer validate --no-check-publish
for manifest in packages/*/composer.json; do
  composer validate --no-check-publish "$manifest"
done
composer lint
composer test
pnpm install --frozen-lockfile
pnpm build
pnpm typecheck
pnpm test:frontend
```

The lint script covers the root installer (`src/`) as well as every package and
test file, so changes to `InlayServiceProvider` and `inlay:install` fail the
same release gate as component changes.

The root `typecheck` and `test:frontend` scripts intentionally run workspace
projects one at a time. Several frontend packages consume declaration files
from sibling builds; serial execution keeps a clean checkout from observing a
partially regenerated `dist/` directory and makes the release gate
deterministic.

The `tests.yml` workflow also runs `playground/laravel-react` as a separate PHP
8.4 / Node 22 integration job. Keep that job green when changing panel routes,
demo migrations, frontend resolver wiring, or any package contract exercised by
the playground; package-level tests alone do not prove the hosted Inertia pages
still boot and build.

The package export test also verifies that every Composer package is listed in
`.github/workflows/split.yml` and that every frontend export points to a built
file. The migration suite also asserts the PHP 8.3 and Laravel 12 platform
floor for the root and every package. Add a package to the workflow in the same
change that adds its Composer manifest; do not rely on a manually maintained
list outside the test.

## PHP package mirrors

`.github/workflows/split.yml` runs for a tag or by manual dispatch. It mirrors
all Composer directories under `packages/` into repositories in the `inlayphp`
organization. Configure the repository secret `SPLIT_TOKEN` with permission to
create commits in those mirrors. The mirrors are read-only release artifacts;
changes belong in this monorepo.

Create or repair the mirrors with `bin/create-split-repos.sh` after authenticating
with `gh`. The package coverage test keeps this bootstrap script and the split
workflow synchronized.

The workflow uses these repository-name aliases to preserve the public API:

| Directory | Repository |
| --- | --- |
| `form` | `forms` |
| `import` | `imports` |
| `infolist` | `infolists` |
| `panel` | `panels` |
| `table` | `tables` |
| `table-xlsx` | `tables-xlsx` |
| every other Composer directory | the same directory name |

The root package `inlayphp/inlay` is intentionally not split: its repository
is the monorepo itself.

## Versioning and publishing

Before the first public tag, internal Composer constraints may use `dev-main`.
For a tagged pre-release, replace those constraints with the coordinated
pre-release version (currently `^0.3 || dev-main`) in one lockstep change, regenerate
the root and playground locks, and then tag the monorepo. Publish frontend
packages only after their PHP contract version has been tagged and the full
build has passed.

Do not publish a package directly from a split mirror. The source of truth is
the tagged monorepo commit, and the package README, generated declarations,
and migration tests must all come from that same commit.

Frontend publishing is intentionally a root command so private examples cannot
be selected by accident:

```bash
pnpm publish:npm:dry-run
pnpm publish:npm
```

Run the dry-run after `pnpm build` (the generated `dist/` directories are not
committed). The command filters the workspace to `@inlayphp/*`, so a future
public example or tooling package cannot enter the release by becoming
non-private accidentally.

`.github/workflows/publish-npm.yml` runs on `v*` tags (or a manual dispatch),
builds the workspace before typechecking or testing (the declaration entry
points are generated artifacts on a clean runner), repeats the release gates,
and then publishes the public workspace packages with npm provenance. Configure the `npm` environment with
an `NPM_TOKEN` secret until every package has an npm trusted-publisher entry.
Every public manifest now carries the repository, package directory, issue
tracker, public access, and Node 20 metadata npm expects. Package versions and
peer ranges still must be coordinated before the first tag; the workflow
deliberately does not rewrite versions for a release.

## Post-tag checks

After the workflows finish:

1. Verify every expected PHP mirror contains the tag and its package README.
2. Verify the matching `@inlayphp/*` package versions and declaration entry
   points on npm.
3. Install `inlayphp/inlay` and one optional plugin in a fresh Laravel 12
   application, then run the panel smoke route.
4. Add upgrade notes for any contract or migration change before starting the
   next release line.
