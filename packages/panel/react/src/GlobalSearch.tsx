import { createElement, useEffect, useRef, useState } from 'react'
import { isSafeUrl } from '@inlayphp/core'
import { controlClass, menuItemClass } from '@inlayphp/ui-react'
import type { ComponentType, MouseEvent } from 'react'
import type { PanelGlobalSearch, PanelLinkProps } from './types'
import { BuiltInIcon } from './BuiltInIcon'

export type GlobalSearchResult = {
  resource: string
  label: string
  title: string
  url: string | null
}

type Props = {
  config: PanelGlobalSearch
  placement?: PanelGlobalSearch['position']
  linkComponent?: ComponentType<PanelLinkProps>
  onNavigate?: (href: string, event: MouseEvent<HTMLAnchorElement>) => void
}

type ResponsePayload = { results?: GlobalSearchResult[] }

export function GlobalSearch({ config, placement = 'header-end', linkComponent, onNavigate }: Props) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<GlobalSearchResult[]>([])
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [searched, setSearched] = useState(false)
  const input = useRef<HTMLInputElement>(null)

  useEffect(() => {
    const onShortcut = (event: KeyboardEvent) => {
      if (event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes((event.target as HTMLElement | null)?.tagName ?? '')) {
        event.preventDefault()
        input.current?.focus()
      }
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        input.current?.focus()
      }
    }
    window.addEventListener('keydown', onShortcut)
    return () => window.removeEventListener('keydown', onShortcut)
  }, [])

  useEffect(() => {
    const term = query.trim()
    if (!isSafeUrl(config.endpoint) || term.length < config.minChars) {
      setResults([])
      setLoading(false)
      setSearched(false)
      return
    }

    const controller = new AbortController()
    const timer = window.setTimeout(async () => {
      setLoading(true)
      try {
        const url = new URL(config.endpoint, window.location.origin)
        url.searchParams.set('q', term)
        const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
        if (!response.ok) throw new Error('Global search failed')
        const payload = await response.json() as ResponsePayload
        setResults(Array.isArray(payload.results) ? payload.results : [])
        setSearched(true)
      } catch (error) {
        if ((error as { name?: string }).name !== 'AbortError') {
          setResults([])
          setSearched(true)
        }
      } finally {
        if (!controller.signal.aborted) setLoading(false)
      }
    }, 150)

    return () => {
      controller.abort()
      window.clearTimeout(timer)
    }
  }, [config.endpoint, config.minChars, query])

  const showResults = open && query.trim().length >= config.minChars
  const Link = linkComponent

  const navigate = (event: MouseEvent<HTMLAnchorElement>, url: string) => {
    if (!onNavigate || !isSafeUrl(url)) return
    event.preventDefault()
    onNavigate(url, event)
    setOpen(false)
  }

  const isSidebar = placement === 'sidebar' || placement === 'sidebar-footer'
  return <div className={`relative min-w-0 ${isSidebar ? 'w-full' : 'w-72 max-w-[min(18.75rem,40vw)] flex-none'}`} data-placement={placement} data-slot="global-search">
    <label className="sr-only" htmlFor="inlay-global-search">Search resources</label>
    <BuiltInIcon className="pointer-events-none absolute left-3 top-1/2 z-10 size-4 -translate-y-1/2 text-(--inlay-panel-muted)" name="search" />
    <input ref={input} id="inlay-global-search" name="global-search" aria-label="Search resources" className={`${controlClass} min-h-(--inlay-control-height) bg-(--inlay-panel-surface) py-1 pl-9 pr-3 text-(--inlay-panel-text) ring-(--inlay-panel-border) placeholder:text-(--inlay-panel-muted) focus:ring-(--inlay-panel-accent)`.trim()} onBlur={() => window.setTimeout(() => setOpen(false), 120)} onChange={(event) => { setQuery(event.target.value); setOpen(true) }} onFocus={() => setOpen(true)} placeholder={config.placeholder} role="searchbox" type="search" value={query} />
    {showResults ? <div aria-label="Search results" className="absolute left-0 right-0 top-full z-50 mt-2 max-h-80 overflow-auto rounded-(--inlay-panel-radius) bg-(--inlay-panel-surface) p-1 shadow-lg ring-1 ring-(--inlay-panel-border)" data-slot="global-search-results" role="listbox">
      {loading ? <p className="px-3 py-2 text-sm text-(--inlay-panel-muted)" role="status">Searching…</p> : null}
      {!loading && searched && results.length === 0 ? <p className="px-3 py-2 text-sm text-(--inlay-panel-muted)" role="status">No results found.</p> : null}
      {!loading && results.map((result, index) => {
        const key = `${result.resource}-${result.title}-${index}`
        const content = <><span className="block truncate text-sm font-medium text-(--inlay-panel-text)">{result.title}</span><span className="block truncate text-xs text-(--inlay-panel-muted)">{result.label}</span></>
        if (!result.url || !isSafeUrl(result.url)) return <div className="rounded-(--inlay-panel-radius) px-3 py-2" key={key} role="option">{content}</div>
        const props: PanelLinkProps = { href: result.url, className: `${menuItemClass} hover:bg-(--inlay-panel-hover)`, children: content, onClick: (event) => navigate(event, result.url!), role: 'option', 'data-slot': 'global-search-result' }
        return Link ? createElement(Link, { ...props, key }) : <a {...props} key={key} />
      })}
    </div> : null}
  </div>
}
