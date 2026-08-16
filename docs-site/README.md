# Inlay documentation site

This folder contains the static documentation site for Inlay. The site is
generated from the Markdown files in the repository; the HTML is never edited
by hand.

## Local development

From the repository root:

```bash
cd docs-site
npm install
npm run dev
```

Open <http://localhost:4173>. The development server rebuilds when a Markdown
file under `docs/` or a package README changes.

To create a production build without the server:

```bash
npm run build
```

The generated site is written to `docs-site/dist/`. It is intentionally ignored
by Git and can be uploaded to any static host.

## Markdown sources

The build includes:

- the complete `docs/guide/` user guide;
- selected top-level references: installation, architecture, components,
  examples, release, and responsive layout;
- non-CMS package READMEs under `packages/*/README.md` (including the
  framework-agnostic core packages, but not CMS add-ons or Visual Editing).

Edit those Markdown sources and rebuild. Internal `.md` links are rewritten to
the corresponding site URLs, headings receive stable anchors, code blocks are
highlighted, and the search index is regenerated.

CMS documentation is deliberately excluded from this core documentation site.
It can be added later as a separate documentation section without changing the
site renderer.

## GitHub Pages

The repository workflow at `.github/workflows/docs-site.yml` builds this folder
and deploys `docs-site/dist/` to the `github-pages` environment whenever the
documentation sources change. Because `InlayPHP/inlay` is a project Pages
site, the workflow sets `/inlay` as the base path. The build also accepts
`DOCS_BASE_PATH` for local verification:

```bash
DOCS_BASE_PATH=/inlay npm run build
```

For an organization or custom-domain site, leave the base path empty. Set the
repository's Pages source to GitHub Actions the first time the workflow runs.
