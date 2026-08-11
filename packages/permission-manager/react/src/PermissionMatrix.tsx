import { useMemo, useState } from 'react'
import type { SchemaComponentRendererProps } from '@inlayphp/forms-react'
import type { Option } from '@inlayphp/forms-react'
import { controlClass } from '@inlayphp/ui-react'
import type { AbilityDefinition } from './types'

export type PermissionMatrixProps = SchemaComponentRendererProps & {
  abilities?: AbilityDefinition[]
}

type MatrixPermission = Required<Pick<AbilityDefinition, 'name' | 'label'>> & {
  group: string
  description: string | null
  dangerous: boolean
}

type MatrixField = SchemaComponentRendererProps['component'] & {
  options?: Option[]
  disabled: boolean
}

const dangerousOperations = new Set(['delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny'])

export function PermissionMatrix({ component, path, value, update, abilities = [] }: PermissionMatrixProps) {
  const field = component as MatrixField
  const [query, setQuery] = useState('')
  const selected = Array.isArray(value) ? value.map(String) : []
  const metadata = useMemo(() => new Map(abilities.map((ability) => [ability.name, ability])), [abilities])
  const permissions = useMemo<MatrixPermission[]>(() => (field.options ?? []).map((option) => {
    const name = String(option.value)
    const ability = metadata.get(name)
    const segments = name.split('.')
    const operation = segments.at(-1) ?? name
    return {
      name,
      label: ability?.label ?? option.label,
      group: ability?.group || humanize(segments.slice(0, -1).join(' ') || 'Other'),
      description: ability?.description ?? null,
      dangerous: ability?.dangerous ?? dangerousOperations.has(operation),
    }
  }), [field.options, metadata])
  const needle = query.trim().toLocaleLowerCase()
  const filtered = permissions.filter((permission) => !needle || [permission.name, permission.label, permission.group, permission.description].some((candidate) => candidate?.toLocaleLowerCase().includes(needle)))
  const groups = [...filtered.reduce<Map<string, MatrixPermission[]>>((result, permission) => {
    result.set(permission.group, [...(result.get(permission.group) ?? []), permission])
    return result
  }, new Map())].sort(([left], [right]) => left.localeCompare(right))

  const setSelected = (next: string[]) => update(path, [...new Set(next)])
  const toggleGroup = (items: MatrixPermission[], checked: boolean) => {
    const names = new Set(items.map((item) => item.name))
    setSelected(checked ? [...selected, ...names] : selected.filter((name) => !names.has(name)))
  }

  return (
    <section aria-label={component.label} className="min-w-0" data-slot="permission-matrix">
      <div className="flex flex-col gap-3 border-b border-(--inlay-border) pb-4 sm:flex-row sm:items-center sm:justify-between">
        <label className="relative block min-w-0 flex-1 sm:max-w-sm">
          <span className="sr-only">Search permissions</span>
          <svg aria-hidden="true" className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-(--inlay-muted)" fill="none" height="16" viewBox="0 0 24 24" width="16"><circle cx="11" cy="11" r="7" stroke="currentColor" strokeWidth="2" /><path d="m16 16 4 4" stroke="currentColor" strokeLinecap="round" strokeWidth="2" /></svg>
          <input className={`${controlClass} pr-3 pl-9`} onChange={(event) => setQuery(event.target.value)} placeholder="Search permissions…" type="search" value={query} />
        </label>
        <p className="text-sm tabular-nums text-(--inlay-muted)"><strong className="font-semibold text-(--inlay-foreground)">{selected.length}</strong> of {permissions.length} selected</p>
      </div>

      {groups.length ? <div className="mt-5 grid gap-4 lg:grid-cols-2">{groups.map(([group, items = []]) => {
        const selectedCount = items.filter((item) => selected.includes(item.name)).length
        const allSelected = items.length > 0 && selectedCount === items.length
        return (
          <fieldset className="min-w-0 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface-muted) p-4" key={group}>
            <legend className="sr-only">{group}</legend>
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="font-semibold text-(--inlay-foreground)">{group}</h2>
                <p className="mt-0.5 text-xs tabular-nums text-(--inlay-muted)">{selectedCount} of {items.length} granted</p>
              </div>
              <label className="flex cursor-pointer items-center gap-2 text-sm font-medium text-(--inlay-muted)">
                <input aria-label={`Select all ${group} permissions`} checked={allSelected} className="size-4 accent-(--inlay-accent)" disabled={field.disabled} onChange={(event) => toggleGroup(items, event.target.checked)} type="checkbox" />
                All
              </label>
            </div>
            <div className="mt-4 grid gap-2">{items.map((permission) => (
              <label className={`group flex cursor-pointer items-start gap-3 rounded-(--inlay-radius) bg-(--inlay-surface) px-3 py-2.5 ring-1 ring-(--inlay-border) transition hover:ring-(--inlay-accent)/35 ${permission.dangerous ? 'has-checked:bg-(--inlay-danger-surface) has-checked:ring-(--inlay-danger)/25' : 'has-checked:bg-(--inlay-accent)/5 has-checked:ring-(--inlay-accent)/25'}`} key={permission.name}>
                <input checked={selected.includes(permission.name)} className="mt-0.5 size-4 shrink-0 accent-(--inlay-accent)" disabled={field.disabled} name={path} onChange={(event) => setSelected(event.target.checked ? [...selected, permission.name] : selected.filter((name) => name !== permission.name))} type="checkbox" value={permission.name} />
                <span className="min-w-0">
                  <span className={`block text-sm font-medium ${permission.dangerous ? 'text-(--inlay-danger)' : 'text-(--inlay-foreground)'}`}>{permission.label}</span>
                  <span className="mt-0.5 block truncate font-mono text-xs text-(--inlay-muted)">{permission.name}</span>
                  {permission.description ? <span className="mt-1 block text-xs text-(--inlay-muted)">{permission.description}</span> : null}
                </span>
              </label>
            ))}</div>
          </fieldset>
        )
      })}</div> : <div className="py-12 text-center"><p className="font-medium text-(--inlay-foreground)">No permissions found</p><p className="mt-1 text-sm text-(--inlay-muted)">Try a different search term.</p></div>}
    </section>
  )
}

function humanize(value: string) {
  return value.replace(/[._-]+/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/\b\w/g, (letter) => letter.toUpperCase())
}
