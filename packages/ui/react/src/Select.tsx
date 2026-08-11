import { controlClass, selectMenuClass, selectOptionClass } from '@inlayphp/ui'
import { useEffect, useId, useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'

export type SelectOption = { value: string | number; label: string; disabled?: boolean }

type SelectValue<TMultiple extends boolean> = TMultiple extends true ? Array<string | number> : string | number | null

export type SelectProps<TMultiple extends boolean = false> = {
  options: SelectOption[]
  value?: SelectValue<TMultiple>
  onValueChange: (value: TMultiple extends true ? string[] : string) => void
  placeholder?: string
  id?: string
  name?: string
  disabled?: boolean
  readOnly?: boolean
  required?: boolean
  autoFocus?: boolean
  invalid?: boolean
  describedBy?: string
  ariaLabel?: string
  className?: string
  buttonClassName?: string
  menuClassName?: string
  searchable?: boolean
  searchPlaceholder?: string
  searchAriaLabel?: string
  loading?: boolean
  loadingMessage?: string
  emptyMessage?: string
  onSearchChange?: (search: string) => void
  multiple?: TMultiple
  attributes?: Record<string, string | number | boolean | null>
}

function safeButtonAttributes(attributes: Record<string, string | number | boolean | null>) {
  const reserved = new Set(['children', 'dangerouslySetInnerHTML', 'class', 'className', 'disabled', 'id', 'name', 'role', 'style', 'type'])

  return Object.fromEntries(Object.entries(attributes).filter(([key]) => !reserved.has(key) && !key.toLowerCase().startsWith('on')))
}



export function Select<TMultiple extends boolean = false>({ options, value = '' as SelectValue<TMultiple>, onValueChange, placeholder = 'Select an option', id, name, disabled = false, readOnly = false, required = false, autoFocus = false, invalid = false, describedBy, ariaLabel, className = '', buttonClassName = '', menuClassName = '', searchable = false, searchPlaceholder = 'Type to search…', searchAriaLabel, loading = false, loadingMessage = 'Loading options…', emptyMessage = 'No options available.', onSearchChange, multiple = false as TMultiple, attributes = {} }: SelectProps<TMultiple>) {
  const generatedId = useId()
  const controlId = id ?? `inlay-select-${generatedId.replaceAll(':', '')}`
  const listboxId = `${controlId}-listbox`
  const root = useRef<HTMLDivElement>(null)
  const button = useRef<HTMLButtonElement>(null)
  const searchInput = useRef<HTMLInputElement>(null)
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const visibleOptions = searchable && !onSearchChange && search
    ? options.filter((option) => option.label.toLocaleLowerCase().includes(search.toLocaleLowerCase()))
    : options
  const selectedValues = (Array.isArray(value) ? value : [value]).map(item => String(item ?? '')).filter(Boolean)
  const selectedIndex = visibleOptions.findIndex((option) => selectedValues.includes(String(option.value)))
  const [activeIndex, setActiveIndex] = useState(Math.max(0, selectedIndex))
  const selected = options.filter((option) => selectedValues.includes(String(option.value)))
  const emitValue = onValueChange as (next: string | string[]) => void

  useEffect(() => {
    if (!open) return
    const close = (event: PointerEvent) => {
      if (!root.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('pointerdown', close)
    return () => document.removeEventListener('pointerdown', close)
  }, [open])

  useEffect(() => {
    if (open) {
      setActiveIndex(Math.max(0, selectedIndex))
      if (searchable) queueMicrotask(() => searchInput.current?.focus())
    } else if (search !== '') {
      setSearch('')
      onSearchChange?.('')
    }
  }, [open, selectedIndex])

  const available = (start: number, direction: 1 | -1) => {
    if (!visibleOptions.length) return -1
    let next = start
    for (let count = 0; count < visibleOptions.length; count += 1) {
      next = (next + direction + visibleOptions.length) % visibleOptions.length
      if (!visibleOptions[next]?.disabled) return next
    }
    return -1
  }
  const choose = (index: number) => {
    const option = visibleOptions[index]
    if (!option || option.disabled) return
    if (multiple) {
      const optionValue = String(option.value)
      emitValue(selectedValues.includes(optionValue) ? selectedValues.filter(item => item !== optionValue) : [...selectedValues, optionValue])
    } else {
      emitValue(String(option.value))
      setOpen(false)
      button.current?.focus()
    }
  }
  const keyboard = (event: KeyboardEvent<HTMLButtonElement>) => {
    if (disabled || readOnly) return
    if (event.key === 'Escape') { setOpen(false); return }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      if (!open) { setOpen(true); return }
      const next = available(activeIndex, event.key === 'ArrowDown' ? 1 : -1)
      if (next >= 0) setActiveIndex(next)
      return
    }
    if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault(); setOpen(true)
      const next = available(event.key === 'Home' ? visibleOptions.length - 1 : 0, event.key === 'Home' ? 1 : -1)
      if (next >= 0) setActiveIndex(next)
      return
    }
    if ((event.key === 'Enter' || event.key === ' ') && open) { event.preventDefault(); choose(activeIndex) }
  }

  return (
    <div className={`relative min-w-0 ${className}`.trim()} data-slot="select" ref={root}>
      <button aria-activedescendant={open && visibleOptions[activeIndex] ? `${listboxId}-${activeIndex}` : undefined} aria-controls={listboxId} aria-describedby={describedBy} aria-expanded={open} aria-haspopup="listbox" aria-invalid={invalid || undefined} aria-label={ariaLabel} aria-readonly={readOnly || undefined} aria-required={required || undefined} autoFocus={autoFocus} className={`${controlClass} flex items-center justify-between gap-3 text-left ${selected.length ? '' : 'text-(--inlay-muted)'} ${buttonClassName}`.trim()} disabled={disabled} id={controlId} onClick={() => !readOnly && setOpen((current) => !current)} onKeyDown={keyboard} ref={button} role="combobox" type="button" {...safeButtonAttributes(attributes)}>
        <span className="min-w-0 flex-1 truncate">{selected.length ? selected.map(option => option.label).join(', ') : placeholder}</span>
        <svg aria-hidden="true" className={`size-4 shrink-0 text-(--inlay-muted) transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 16 16"><path d="m4 6 4 4 4-4" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" /></svg>
      </button>
      {name ? multiple ? selectedValues.map(item => <input key={item} name={`${name}[]`} type="hidden" value={item} />) : <input name={name} type="hidden" value={String(value ?? '')} /> : null}
      {open ? <div className={`${selectMenuClass} ${menuClassName}`.trim()}>
        {searchable ? <input aria-label={`Search ${searchAriaLabel ?? ariaLabel ?? name ?? 'options'}`} className={`${controlClass} mb-1.5`} onChange={(event) => { setSearch(event.target.value); onSearchChange?.(event.target.value) }} placeholder={searchPlaceholder} ref={searchInput} role="searchbox" value={search} /> : null}
        <ul aria-labelledby={ariaLabel ? undefined : controlId} aria-multiselectable={multiple || undefined} className="max-h-60 overflow-auto" id={listboxId} role="listbox">
        {loading ? <li className="px-2.5 py-3 text-(--inlay-muted)" role="status">{loadingMessage}</li> : visibleOptions.length === 0 ? <li className="px-2.5 py-3 text-(--inlay-muted)" role="status">{emptyMessage}</li> : visibleOptions.map((option, index) => {
          const isSelected = selectedValues.includes(String(option.value))
          const isActive = index === activeIndex
          return <li aria-disabled={option.disabled || undefined} aria-selected={isSelected} className={`${selectOptionClass} ${isActive ? 'bg-(--inlay-surface-muted)' : ''} ${option.disabled ? 'opacity-45' : ''}`} id={`${listboxId}-${index}`} key={option.value} onMouseEnter={() => !option.disabled && setActiveIndex(index)} onPointerDown={(event) => { event.preventDefault(); event.stopPropagation() }} onClick={(event) => { event.stopPropagation(); choose(index) }} role="option">
            <span className="min-w-0 flex-1 truncate">{option.label}</span>
            {isSelected ? <svg aria-hidden="true" className="size-4 shrink-0 text-(--inlay-accent)" fill="none" viewBox="0 0 16 16"><path d="m3.5 8.5 3 3 6-7" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.75" /></svg> : null}
          </li>
        })}
        </ul>
      </div> : null}
    </div>
  )
}
