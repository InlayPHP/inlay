(() => {
  const root = document.documentElement
  const base = window.INLAY_DOCS_BASE || ''
  const themeToggle = document.querySelector('#theme-toggle')
  const mobileToggle = document.querySelector('#mobile-menu-toggle')
  const mobileNav = document.querySelector('#mobile-nav')
  const searchDialog = document.querySelector('#search-dialog')
  const searchOpen = document.querySelector('#search-open')
  const searchClose = document.querySelector('#search-close')
  const searchInput = document.querySelector('#search-input')
  const searchResults = document.querySelector('#search-results')
  let searchItems = []
  let selectedResult = -1

  const currentTheme = () => root.dataset.theme === 'dark' ? 'dark' : 'light'

  const setTheme = (theme) => {
    root.dataset.theme = theme
    try {
      localStorage.setItem('inlay-docs-theme', theme)
    } catch {
      // Private browsing can disable storage. The current page still updates.
    }
    if (themeToggle) themeToggle.setAttribute('aria-label', `Use ${theme === 'dark' ? 'light' : 'dark'} theme`)
  }

  setTheme(currentTheme())
  themeToggle?.addEventListener('click', () => setTheme(currentTheme() === 'dark' ? 'light' : 'dark'))

  mobileToggle?.addEventListener('click', () => {
    const open = mobileToggle.getAttribute('aria-expanded') === 'true'
    mobileToggle.setAttribute('aria-expanded', String(!open))
    mobileToggle.setAttribute('aria-label', open ? 'Open navigation' : 'Close navigation')
    if (mobileNav) mobileNav.hidden = open
  })

  mobileNav?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
    if (mobileToggle) mobileToggle.setAttribute('aria-expanded', 'false')
    mobileNav.hidden = true
  }))

  document.querySelectorAll('.prose table').forEach((table) => {
    if (table.parentElement?.classList.contains('table-scroll')) return
    const wrapper = document.createElement('div')
    wrapper.className = 'table-scroll'
    table.parentNode?.insertBefore(wrapper, table)
    wrapper.appendChild(table)
  })

  const copyButtons = () => {
    document.querySelectorAll('.prose pre').forEach((pre) => {
      if (pre.querySelector('.copy-code')) return
      const button = document.createElement('button')
      button.className = 'copy-code'
      button.type = 'button'
      button.textContent = 'Copy'
      button.setAttribute('aria-label', 'Copy code block')
      button.addEventListener('click', async () => {
        const code = pre.querySelector('code')?.textContent ?? ''
        try {
          await navigator.clipboard.writeText(code)
          button.textContent = 'Copied'
          window.setTimeout(() => { button.textContent = 'Copy' }, 1400)
        } catch {
          button.textContent = 'Select code'
          window.setTimeout(() => { button.textContent = 'Copy' }, 1800)
        }
      })
      pre.appendChild(button)
    })
  }
  copyButtons()

  const openSearch = () => {
    if (!searchDialog) return
    if (typeof searchDialog.showModal === 'function') searchDialog.showModal()
    else searchDialog.setAttribute('open', '')
    searchInput?.focus()
    if (searchInput && !searchInput.value) renderResults('')
  }

  const closeSearch = () => {
    if (!searchDialog) return
    if (typeof searchDialog.close === 'function') searchDialog.close()
    else searchDialog.removeAttribute('open')
    selectedResult = -1
  }

  searchOpen?.addEventListener('click', openSearch)
  searchClose?.addEventListener('click', closeSearch)
  searchDialog?.addEventListener('click', (event) => {
    if (event.target === searchDialog) closeSearch()
  })

  const searchableText = (item) => [item.title, item.description, item.section, ...(item.headings ?? [])].join(' ').toLowerCase()

  const renderResults = (query) => {
    if (!searchResults) return
    const normalized = query.trim().toLowerCase()
    const results = searchItems
      .map((item) => {
        const text = searchableText(item)
        if (!normalized) return { item, score: item.section === 'Guide' ? 0 : 1 }
        const title = item.title.toLowerCase()
        const score = title === normalized ? 0 : title.startsWith(normalized) ? 1 : text.includes(normalized) ? 2 : 99
        return { item, score }
      })
      .filter(({ score }) => score < 99)
      .sort((a, b) => a.score - b.score || a.item.title.localeCompare(b.item.title))
      .slice(0, 12)
    selectedResult = -1
    searchResults.replaceChildren()
    if (!results.length) {
      const empty = document.createElement('p')
      empty.className = 'search-empty'
      empty.textContent = 'No matching pages. Try a package name or feature.'
      searchResults.appendChild(empty)
      return
    }
    results.forEach(({ item }, index) => {
      const link = document.createElement('a')
      link.href = item.url
      link.className = 'search-result'
      link.dataset.index = String(index)
      link.setAttribute('role', 'option')
      const title = document.createElement('strong')
      title.textContent = item.title
      const meta = document.createElement('span')
      meta.textContent = `${item.section} · ${item.description}`
      link.append(title, meta)
      searchResults.appendChild(link)
    })
  }

  fetch(`${base}/search.json`)
    .then((response) => response.ok ? response.json() : [])
    .then((items) => {
      searchItems = Array.isArray(items) ? items : []
      renderResults(searchInput?.value ?? '')
    })
    .catch(() => renderResults(''))

  searchInput?.addEventListener('input', () => renderResults(searchInput.value))
  searchInput?.addEventListener('keydown', (event) => {
    const results = [...(searchResults?.querySelectorAll('.search-result') ?? [])]
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      if (!results.length) return
      selectedResult = (selectedResult + (event.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length
      results.forEach((result, index) => result.classList.toggle('is-selected', index === selectedResult))
      results[selectedResult]?.scrollIntoView({ block: 'nearest' })
    }
    if (event.key === 'Enter' && selectedResult >= 0) {
      event.preventDefault()
      results[selectedResult]?.click()
    }
  })

  document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault()
      openSearch()
    }
    if (event.key === 'Escape' && searchDialog?.open) closeSearch()
  })
})()
