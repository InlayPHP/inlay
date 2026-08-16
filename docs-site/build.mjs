import { cp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import hljs from 'highlight.js/lib/common'
import { marked } from 'marked'

const siteDir = path.dirname(fileURLToPath(import.meta.url))
const rootDir = path.resolve(siteDir, '..')
const docsDir = path.join(rootDir, 'docs')
const guideDir = path.join(docsDir, 'guide')
const packageDir = path.join(rootDir, 'packages')
const outputDir = path.join(siteDir, 'dist')
const assetsDir = path.join(siteDir, 'static')
const excludedPackageNames = new Set(['visual-editing'])

const basePath = normalizeBasePath(process.env.DOCS_BASE_PATH ?? '')
const siteTitle = 'Inlay documentation'
const siteDescription = 'PHP-first administration interfaces for Laravel and Inertia.'

const referenceFiles = [
  ['docs/installation.md', 'Installation'],
  ['docs/architecture.md', 'Architecture'],
  ['docs/components.md', 'Component catalog'],
  ['docs/examples.md', 'Examples'],
  ['docs/release.md', 'Release workflow'],
  ['docs/RESPONSIVE_LAYOUT.md', 'Responsive layout'],
]

function normalizeBasePath(value) {
  const trimmed = value.trim()
  if (!trimmed || trimmed === '/') return ''
  return `/${trimmed.replace(/^\/+|\/+$/g, '')}`
}

function siteUrl(relativePath = '') {
  const normalized = relativePath.replace(/^\/+/, '')
  if (!normalized) return basePath ? `${basePath}/` : '/'
  return `${basePath}/${normalized}`
}

function htmlEscape(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;')
}

function stripMarkdown(value) {
  return String(value)
    .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
    .replace(/[`*_~>#]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
}

function slugify(value) {
  const slug = stripMarkdown(value)
    .toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '')
    .trim()
    .replace(/\s+/g, '-')
  return slug || 'section'
}

function titleFromMarkdown(markdown, fallback) {
  const match = markdown.match(/^#\s+(.+)$/m)
  return match ? stripMarkdown(match[1]) : fallback
}

function descriptionSourceFromMarkdown(markdown) {
  const withoutTitle = markdown.replace(/^#\s+.+$/m, '').trim()
  const paragraph = withoutTitle.split(/\n\s*\n/).find((block) => {
    const clean = block.trim()
    return clean && !clean.startsWith('#') && !clean.startsWith('```') && !clean.startsWith('|')
  })
  return paragraph ? stripMarkdown(paragraph) : siteDescription
}

function descriptionFromMarkdown(markdown) {
  const source = descriptionSourceFromMarkdown(markdown)
  if (source.length <= 180) return source

  return `${source.slice(0, 177).trimEnd()}…`
}

function outputPathFor(sourcePath, kind, stem = '') {
  if (kind === 'guide-index') return 'index.html'
  if (kind === 'guide') return `guide/${stem.replace(/^\d+[-_]/, '')}.html`
  if (kind === 'reference') return `reference/${stem.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.html`
  return `reference/packages/${stem.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.html`
}

function navTitleFromPackage(name, markdown) {
  const title = titleFromMarkdown(markdown, name)
  return title.replace(/^Inlay\s+/i, '').replace(/\s+for Laravel and Inertia$/i, '')
}

async function collectDocuments() {
  const documents = []
  const sourceToDocument = new Map()
  const add = async (sourcePath, kind, titleOverride = null, section = 'Guide', order = 0) => {
    const markdown = await readFile(sourcePath, 'utf8')
    const relative = path.relative(rootDir, sourcePath)
    const stem = kind === 'package'
      ? path.basename(path.dirname(sourcePath))
      : path.basename(sourcePath, '.md')
    const outputPath = outputPathFor(sourcePath, kind, stem)
    const document = {
      sourcePath,
      sourceRelative: relative,
      markdown,
      kind,
      section,
      order,
      title: titleOverride ?? titleFromMarkdown(markdown, stem),
      description: descriptionFromMarkdown(markdown),
      descriptionSource: descriptionSourceFromMarkdown(markdown),
      outputPath,
      url: siteUrl(outputPath),
    }
    documents.push(document)
    sourceToDocument.set(path.resolve(sourcePath), document)
  }

  const guideFiles = (await readdir(guideDir))
    .filter((name) => name.endsWith('.md'))
    .sort((a, b) => a.localeCompare(b, undefined, { numeric: true }))
  for (const [index, name] of guideFiles.entries()) {
    await add(
      path.join(guideDir, name),
      name === 'README.md' ? 'guide-index' : 'guide',
      null,
      'Guide',
      index,
    )
  }

  for (const [index, [relative, title]] of referenceFiles.entries()) {
    const sourcePath = path.join(rootDir, relative)
    if (existsSync(sourcePath)) await add(sourcePath, 'reference', title, 'Reference', index)
  }

  const packageNames = (await readdir(packageDir, { withFileTypes: true }))
    .filter((entry) => entry.isDirectory() && !entry.name.startsWith('cms') && !excludedPackageNames.has(entry.name))
    .map((entry) => entry.name)
    .sort()
  for (const [index, name] of packageNames.entries()) {
    const sourcePath = path.join(packageDir, name, 'README.md')
    if (!existsSync(sourcePath)) continue
    const markdown = await readFile(sourcePath, 'utf8')
    const title = navTitleFromPackage(name, markdown)
    await add(sourcePath, 'package', title, 'Packages', index)
  }

  return {
    documents,
    sourceToDocument,
  }
}

function linkForSource(sourcePath, href, sourceToDocument) {
  if (!href || href.startsWith('#') || href.startsWith('http://') || href.startsWith('https://') || href.startsWith('mailto:') || href.startsWith('tel:')) {
    return href
  }
  const [target, hash] = href.split('#', 2)
  if (!target) return hash ? `#${hash}` : href
  const resolved = path.resolve(path.dirname(sourcePath), target)
  const document = sourceToDocument.get(resolved)
  if (!document) {
    if (resolved.startsWith(`${rootDir}${path.sep}`) || resolved === rootDir) {
      const repositoryPath = path.relative(rootDir, resolved).split(path.sep).join('/')
      return `https://github.com/InlayPHP/inlay/blob/main/${repositoryPath}${hash ? `#${hash}` : ''}`
    }
    return href
  }
  return `${document.url}${hash ? `#${slugify(decodeURIComponent(hash))}` : ''}`
}

function parseHeadings(markdown) {
  const headings = []
  for (const token of marked.lexer(markdown)) {
    if (token.type !== 'heading' || token.depth < 2 || token.depth > 3) continue
    headings.push({
      depth: token.depth,
      text: stripMarkdown(token.text),
      id: slugify(token.text),
    })
  }
  return headings
}

function renderMarkdown(document, sourceToDocument) {
  let firstHeading = true
  let introRemoved = false
  const usedIds = new Map()
  const renderer = new marked.Renderer()

  renderer.heading = ({ tokens, depth }) => {
    const content = renderer.parser.parseInline(tokens)
    const text = stripMarkdown(content.replace(/<[^>]+>/g, ''))
    if (depth === 1 && firstHeading) {
      firstHeading = false
      return ''
    }
    const baseId = slugify(text)
    const count = usedIds.get(baseId) ?? 0
    usedIds.set(baseId, count + 1)
    const id = count ? `${baseId}-${count + 1}` : baseId
    return `<h${depth} id="${htmlEscape(id)}">${content}</h${depth}>`
  }

  renderer.link = ({ href, title, tokens }) => {
    const target = linkForSource(document.sourcePath, href, sourceToDocument)
    const label = renderer.parser.parseInline(tokens)
    const external = /^https?:\/\//.test(target ?? '')
    const titleAttribute = title ? ` title="${htmlEscape(title)}"` : ''
    const externalAttributes = external ? ' target="_blank" rel="noreferrer"' : ''
    return `<a href="${htmlEscape(target ?? '')}"${titleAttribute}${externalAttributes}>${label}</a>`
  }

  renderer.paragraph = ({ tokens }) => {
    const content = renderer.parser.parseInline(tokens)
    const text = stripMarkdown(content.replace(/<[^>]+>/g, ''))
    if (!introRemoved && text === document.descriptionSource) {
      introRemoved = true
      return ''
    }

    return `<p>${content}</p>\n`
  }

  renderer.image = ({ href, title, text }) => {
    const source = linkForSource(document.sourcePath, href, sourceToDocument)
    const titleAttribute = title ? ` title="${htmlEscape(title)}"` : ''
    return `<img src="${htmlEscape(source ?? '')}" alt="${htmlEscape(text ?? '')}"${titleAttribute} loading="lazy">`
  }

  renderer.code = ({ text, lang }) => {
    const language = (lang ?? '').split(/\s+/)[0].toLowerCase()
    let highlighted
    if (language && hljs.getLanguage(language)) {
      highlighted = hljs.highlight(text, { language }).value
    } else {
      highlighted = htmlEscape(text)
    }
    const className = language ? ` class="language-${htmlEscape(language)}"` : ''
    return `<pre><code${className}>${highlighted}</code></pre>`
  }

  return marked.parse(document.markdown, {
    gfm: true,
    breaks: false,
    renderer,
  })
}

function navLink(document, current) {
  const active = document.outputPath === current.outputPath ? ' aria-current="page" class="is-active"' : ''
  return `<a href="${document.url}"${active}><span>${htmlEscape(document.title)}</span></a>`
}

function navigationMarkup(documents, current, mobile = false) {
  const groups = [
    ['Guide', documents.filter((document) => document.section === 'Guide' && document.kind !== 'guide-index')],
    ['Reference', documents.filter((document) => document.section === 'Reference')],
    ['Packages', documents.filter((document) => document.section === 'Packages')],
  ]
  return groups.map(([label, items]) => `
    <section class="nav-group"${mobile ? '' : ' aria-label="'+htmlEscape(label)+'"'}>
      <h2>${htmlEscape(label)}</h2>
      <nav>${items.map((document) => navLink(document, current)).join('')}</nav>
    </section>
  `).join('')
}

function searchIndex(documents) {
  return documents.map((document) => ({
    title: document.title,
    description: document.description,
    section: document.section,
    url: document.url,
    headings: parseHeadings(document.markdown).slice(0, 14).map((heading) => heading.text),
  }))
}

function pageTemplate(document, content, headings, documents) {
  const visibleDocuments = documents.filter((item) => item.outputPath !== 'index.html')
  const currentIndex = visibleDocuments.findIndex((item) => item.outputPath === document.outputPath)
  const previous = currentIndex > 0 ? visibleDocuments[currentIndex - 1] : null
  const next = currentIndex >= 0 && currentIndex < visibleDocuments.length - 1 ? visibleDocuments[currentIndex + 1] : null
  const toc = headings.length
    ? `<aside class="toc" aria-label="On this page"><p class="toc-label">On this page</p><nav>${headings.map((heading) => `<a class="toc-depth-${heading.depth}" href="#${htmlEscape(heading.id)}">${htmlEscape(heading.text)}</a>`).join('')}</nav></aside>`
    : '<aside class="toc" aria-hidden="true"></aside>'

  const themeIcon = '<span class="theme-icon theme-icon-sun" aria-hidden="true">☼</span><span class="theme-icon theme-icon-moon" aria-hidden="true">☾</span>'
  return `<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="${htmlEscape(document.description)}">
    <meta name="theme-color" content="#f6f7f5">
    <title>${htmlEscape(document.title)} · Inlay documentation</title>
    <script>try{const t=localStorage.getItem('inlay-docs-theme');document.documentElement.dataset.theme=t||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light')}catch(e){document.documentElement.dataset.theme='light'}</script>
    <link rel="preconnect" href="https://rsms.me">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="${siteUrl('assets/styles.css')}">
  </head>
  <body>
    <div class="site-shell">
      <header class="site-header">
        <div class="header-inner">
          <a class="brand" href="${siteUrl('')}" aria-label="Inlay documentation home">
            <span class="brand-mark"><img class="logo-light" src="${siteUrl('assets/inlayphp-logo.svg')}" alt=""><img class="logo-dark" src="${siteUrl('assets/inlayphp-logo-dark.svg')}" alt=""></span>
            <span class="brand-copy"><strong>Inlay</strong><span>documentation</span></span>
          </a>
          <div class="header-actions">
            <button class="search-trigger" id="search-open" type="button" aria-label="Search documentation"><span class="search-glyph" aria-hidden="true">⌕</span><span>Search</span><kbd>⌘ K</kbd></button>
            <a class="github-link" href="https://github.com/InlayPHP/inlay" target="_blank" rel="noreferrer">GitHub <span aria-hidden="true">↗</span></a>
            <button class="theme-toggle" id="theme-toggle" type="button" aria-label="Toggle color theme">${themeIcon}</button>
            <button class="mobile-menu-toggle" id="mobile-menu-toggle" type="button" aria-expanded="false" aria-controls="mobile-nav" aria-label="Open navigation">☰</button>
          </div>
        </div>
      </header>
      <div class="mobile-nav" id="mobile-nav" hidden>
        <div class="mobile-nav-inner">
          <a class="mobile-home" href="${siteUrl('')}">Documentation home</a>
          ${navigationMarkup(documents, document, true)}
        </div>
      </div>
      <div class="layout">
        <aside class="sidebar" aria-label="Documentation navigation">
          <div class="sidebar-scroll">
            <a class="sidebar-home ${document.outputPath === 'index.html' ? 'is-active' : ''}" href="${siteUrl('')}"><span class="home-dot" aria-hidden="true"></span><span>Overview</span></a>
            ${navigationMarkup(documents, document)}
            <div class="sidebar-note"><p>Built for Laravel and Inertia.</p><a href="${siteUrl('reference/installation.html')}">Start building <span aria-hidden="true">→</span></a></div>
          </div>
        </aside>
        <main class="main-content" id="main-content">
          <div class="content-grid">
            <article class="doc-page">
              <div class="breadcrumb"><span>${htmlEscape(document.section)}</span><span aria-hidden="true">/</span><span>${htmlEscape(document.title)}</span></div>
              <header class="page-intro">
                <p class="eyebrow">${document.kind === 'package' ? 'Package reference' : document.kind === 'reference' ? 'Project reference' : 'User guide'}</p>
                <h1>${htmlEscape(document.title)}</h1>
                <p class="page-description">${htmlEscape(document.description)}</p>
              </header>
              <div class="prose-wrap"><div class="prose">${content}</div></div>
              <nav class="page-nav" aria-label="Page navigation">
                ${previous ? `<a class="page-nav-link previous" href="${previous.url}"><span class="page-nav-label">Previous</span><strong>← ${htmlEscape(previous.title)}</strong></a>` : '<span></span>'}
                ${next ? `<a class="page-nav-link next" href="${next.url}"><span class="page-nav-label">Next</span><strong>${htmlEscape(next.title)} →</strong></a>` : '<span></span>'}
              </nav>
            </article>
            ${toc}
          </div>
        </main>
      </div>
      <footer class="site-footer"><div><span>Inlay documentation</span><span class="footer-separator">·</span><span>PHP-first interfaces for Laravel and Inertia.</span></div><a href="https://github.com/InlayPHP/inlay" target="_blank" rel="noreferrer">Source on GitHub ↗</a></footer>
    </div>
    <dialog class="search-dialog" id="search-dialog" aria-labelledby="search-title">
      <div class="search-dialog-inner">
        <div class="search-dialog-head"><div><p class="eyebrow">Quick navigation</p><h2 id="search-title">Search the docs</h2></div><button id="search-close" type="button" aria-label="Close search">×</button></div>
        <label class="search-input-wrap" for="search-input"><span class="search-glyph" aria-hidden="true">⌕</span><input id="search-input" name="q" type="search" autocomplete="off" placeholder="Search guides, packages, and examples…" aria-label="Search guides, packages, and examples"></label>
        <div class="search-results" id="search-results" role="listbox" aria-label="Search results"></div>
        <p class="search-hint"><kbd>↑</kbd><kbd>↓</kbd> to move <kbd>Enter</kbd> to open <kbd>Esc</kbd> to close</p>
      </div>
    </dialog>
    <script>window.INLAY_DOCS_BASE=${JSON.stringify(basePath)};</script>
    <script src="${siteUrl('assets/app.js')}" defer></script>
  </body>
</html>`
}

async function buildSite() {
  const { documents, sourceToDocument } = await collectDocuments()
  await rm(outputDir, { recursive: true, force: true })
  await mkdir(path.join(outputDir, 'assets'), { recursive: true })

  for (const document of documents) {
    const headings = parseHeadings(document.markdown)
    const content = renderMarkdown(document, sourceToDocument)
    const output = path.join(outputDir, document.outputPath)
    await mkdir(path.dirname(output), { recursive: true })
    await writeFile(output, pageTemplate(document, content, headings, documents))
  }

  await cp(path.join(assetsDir, 'styles.css'), path.join(outputDir, 'assets/styles.css'))
  await cp(path.join(assetsDir, 'app.js'), path.join(outputDir, 'assets/app.js'))
  await cp(path.join(assetsDir, 'inlayphp-logo.svg'), path.join(outputDir, 'assets/inlayphp-logo.svg'))
  await cp(path.join(assetsDir, 'inlayphp-logo-dark.svg'), path.join(outputDir, 'assets/inlayphp-logo-dark.svg'))
  await writeFile(path.join(outputDir, 'search.json'), JSON.stringify(searchIndex(documents), null, 2))
  await writeFile(path.join(outputDir, '.nojekyll'), '')
  await cp(path.join(outputDir, 'index.html'), path.join(outputDir, '404.html'))
  console.log(`Built ${documents.length} pages in ${path.relative(rootDir, outputDir)}${basePath ? ` (base path ${basePath})` : ''}.`)
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
  await buildSite()
}

export { buildSite }
