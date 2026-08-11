import { buttonSmallClass, controlClass, Select as InlaySelect } from '@inlayphp/ui-react'
import { useEffect, useRef, useState } from 'react'
import type { Filter, Option, QueryConstraint, QueryGroup, QueryRule } from './types'

const noValueOperators = new Set(['filled', 'blank', 'is_true', 'is_false', 'has', 'does_not_have'])

export function QueryBuilderControl({ filter, value, onChange }: { filter: Filter; value: unknown; onChange: (value: QueryGroup) => void }) {
  const group = normalizeGroup(value)
  const constraints = filter.constraints ?? []
  const ruleCount = countRules(group)
  return <fieldset className="grid gap-3 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-3" data-slot="query-builder">
    <legend className="px-1 text-sm font-semibold text-(--inlay-text)">{filter.label}</legend>
    <GroupEditor constraints={constraints} depth={1} group={group} maxDepth={filter.maxDepth ?? 5} maxRules={filter.maxRules ?? 50} onChange={onChange} ruleCount={ruleCount} />
  </fieldset>
}

function GroupEditor({ group, constraints, depth, maxDepth, maxRules, ruleCount, onChange }: { group: QueryGroup; constraints: QueryConstraint[]; depth: number; maxDepth: number; maxRules: number; ruleCount: number; onChange: (group: QueryGroup) => void }) {
  const updateChild = (index: number, child: QueryRule | QueryGroup) => onChange({ ...group, children: group.children.map((item, itemIndex) => itemIndex === index ? child : item) })
  const remove = (index: number) => onChange({ ...group, children: group.children.filter((_, itemIndex) => itemIndex !== index) })
  const first = constraints[0]
  return <div className="grid gap-3 border-l-2 border-(--inlay-border) pl-3" data-depth={depth}>
    <div className="flex flex-wrap items-center gap-2"><span className="text-xs font-medium text-(--inlay-muted)">Match</span><InlaySelect ariaLabel={`Group ${depth} boolean`} buttonClassName="text-sm" className="min-w-40" onValueChange={(next) => onChange({ ...group, boolean: next as 'and' | 'or' })} options={[{ value: 'and', label: 'All conditions' }, { value: 'or', label: 'Any condition' }]} value={group.boolean} /></div>
    {group.children.map((child, index) => <div className="flex items-start gap-2" key={index}>
      <div className="min-w-0 flex-1">{'children' in child ? <GroupEditor constraints={constraints} depth={depth + 1} group={child} maxDepth={maxDepth} maxRules={maxRules} onChange={(next) => updateChild(index, next)} ruleCount={ruleCount} /> : <RuleEditor constraints={constraints} onChange={(next) => updateChild(index, next)} rule={child} />}</div>
      <button aria-label="Remove query condition" className={`${buttonSmallClass} border-transparent bg-transparent px-2 text-(--inlay-danger) shadow-none hover:bg-(--inlay-danger-surface)`} onClick={() => remove(index)} type="button">Remove</button>
    </div>)}
    {!group.children.length ? <p className="text-sm text-(--inlay-muted)">No conditions. All records match.</p> : null}
    <div className="flex flex-wrap gap-2"><button className={`${buttonSmallClass} font-medium`} disabled={!first || ruleCount >= maxRules} onClick={() => first && onChange({ ...group, children: [...group.children, { constraint: first.name, operator: first.operators[0], value: '' }] })} type="button">Add condition</button><button className={`${buttonSmallClass} font-medium`} disabled={depth >= maxDepth || ruleCount >= maxRules} onClick={() => onChange({ ...group, children: [...group.children, { boolean: 'and', children: [] }] })} type="button">Add group</button></div>
  </div>
}

function RuleEditor({ rule, constraints, onChange }: { rule: QueryRule; constraints: QueryConstraint[]; onChange: (rule: QueryRule) => void }) {
  const constraint = constraints.find((item) => item.name === rule.constraint || item.relationship === rule.constraint) ?? constraints[0]
  if (!constraint) return null
  const operator = normalizeOperator(rule.operator, constraint)
  // PHP describes every operator, so nothing here infers behaviour from names.
  const definition = constraint.operatorDefinitions?.find((item) => item.name === operator)
  const acceptsMany = definition?.multiple ?? false
  const hasValue = definition ? definition.valueType !== 'none' : !noValueOperators.has(operator)
  const selectOptions = definition?.valueType === 'select' ? (definition.options?.length ? definition.options : constraint.options) : constraint.options
  const inputType = definition?.valueType === 'number' ? 'number' : definition?.valueType === 'date' ? 'date' : 'text'
  return <div className="grid gap-2 md:grid-cols-[minmax(9rem,1fr)_minmax(9rem,1fr)_minmax(10rem,1.5fr)]">
    <InlaySelect ariaLabel="Constraint" buttonClassName="text-sm" className="min-w-0" onValueChange={(next) => { const selected = constraints.find((item) => item.name === next); if (selected) onChange({ constraint: selected.name, operator: selected.operators[0], value: '' }) }} options={constraints.map((item) => ({ value: item.name, label: item.label }))} value={constraint.name} />
    <InlaySelect ariaLabel="Operator" buttonClassName="text-sm" className="min-w-0" onValueChange={(next) => onChange({ ...rule, operator: next, value: '' })} options={constraint.operators.map((item) => ({ value: item, label: constraint.operatorDefinitions?.find((definition) => definition.name === item)?.label ?? operatorLabel(item) }))} value={operator} />
    {hasValue ? constraint.type === 'relationship-constraint' && constraint.remoteOptions && ['is_related_to', 'is_not_related_to'].includes(operator) ? <RemoteRelationshipValue constraint={constraint} multiple={acceptsMany} onChange={(value) => onChange({ ...rule, operator, value })} value={rule.value} /> : definition?.valueType === 'boolean' ? <InlaySelect ariaLabel="Value" buttonClassName="text-sm" className="min-w-0" onValueChange={(next) => onChange({ ...rule, operator, value: next })} options={[{ value: '', label: 'Choose…' }, { value: 'true', label: 'True' }, { value: 'false', label: 'False' }]} value={String(rule.value ?? '')} /> : (definition?.valueType === 'select' || constraint.type === 'select-constraint' || (constraint.type === 'relationship-constraint' && ['is_related_to', 'is_not_related_to'].includes(operator))) ? acceptsMany ? <InlaySelect ariaLabel="Value" buttonClassName="text-sm" className="min-w-0" multiple onValueChange={(next) => onChange({ ...rule, operator, value: next })} options={(selectOptions ?? []).map((option) => ({ value: String(option.value), label: option.label }))} value={Array.isArray(rule.value) ? rule.value.map(String).filter(Boolean) : []} /> : <InlaySelect ariaLabel="Value" buttonClassName="text-sm" className="min-w-0" onValueChange={(next) => onChange({ ...rule, operator, value: next })} options={[{ value: '', label: 'Choose…' }, ...(selectOptions ?? []).map((option) => ({ value: String(option.value), label: option.label }))]} value={String(rule.value ?? '')} /> : <input aria-label="Value" className={controlClass} onChange={(event) => onChange({ ...rule, operator, value: event.target.value })} type={inputType} value={String(rule.value ?? '')} /> : <span className="self-center text-sm text-(--inlay-muted)">No value required</span>}
  </div>
}

function normalizeOperator(operator: string, constraint: QueryConstraint): string {
  if (constraint.operators.includes(operator)) return operator
  const aliases: Record<string, string> = { exists: 'has', not_exists: 'does_not_have', is: 'equals', is_not: 'not_equals' }
  const candidate = aliases[operator]
  return candidate && constraint.operators.includes(candidate) ? candidate : constraint.operators[0] ?? operator
}

function RemoteRelationshipValue({ constraint, value, multiple, onChange }: { constraint: QueryConstraint; value: unknown; multiple: boolean; onChange: (value: string | string[]) => void }) {
  const config = constraint.remoteOptions!
  const [options, setOptions] = useState<Option[]>(constraint.options ?? [])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const selected = (Array.isArray(value) ? value : [value]).map(item => String(item ?? '')).filter(Boolean)
  const load = async (search = '', values: string[] = []) => {
    if (!config.endpoint) return
    setLoading(true)
    setError(null)
    try {
      const url = new URL(config.endpoint, window.location.href)
      if (search) url.searchParams.set('search', search)
      values.forEach(item => url.searchParams.append('values[]', item))
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      if (!response.ok) throw new Error(`Option request failed with ${response.status}.`)
      const payload = await response.json() as { options?: Option[] }
      const incoming = Array.isArray(payload.options) ? payload.options : []
      setOptions(current => mergeSelectedOptions(current, incoming, selected))
    } catch {
      setError('Related records could not be loaded. Try again.')
    } finally {
      setLoading(false)
    }
  }
  useEffect(() => {
    const missing = selected.filter(item => !options.some(option => String(option.value) === item))
    if (missing.length) void load('', missing)
  }, [])
  useEffect(() => () => { if (timer.current) clearTimeout(timer.current) }, [])
  const search = (term: string) => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => { void load(term) }, config.searchDebounce)
  }

  return <div className="grid gap-1.5" data-slot="remote-relationship-select">
    {multiple
      ? <InlaySelect ariaLabel="Value" emptyMessage="No related records found." loading={loading} multiple onSearchChange={search} onValueChange={onChange} options={options} searchable searchAriaLabel="Value" value={selected} />
      : <InlaySelect ariaLabel="Value" emptyMessage="No related records found." loading={loading} onSearchChange={search} onValueChange={onChange} options={options} placeholder="Choose…" searchable searchAriaLabel="Value" value={selected[0] ?? ''} />}
    {error ? <span className="text-xs text-(--inlay-danger)" role="alert">{error}</span> : null}
  </div>
}

function mergeSelectedOptions(current: Option[], incoming: Option[], selected: string[]) {
  const retained = current.filter(option => selected.includes(String(option.value)))
  return [...retained, ...incoming].filter((option, index, all) => all.findIndex(candidate => String(candidate.value) === String(option.value)) === index)
}

function normalizeGroup(value: unknown): QueryGroup { return value && typeof value === 'object' && !Array.isArray(value) && Array.isArray((value as QueryGroup).children) ? value as QueryGroup : { boolean: 'and', children: [] } }
function countRules(group: QueryGroup): number { return group.children.reduce((count, child) => count + ('children' in child ? countRules(child) : 1), 0) }
function operatorLabel(value: string) { return value.split('_').map((part) => part[0].toUpperCase() + part.slice(1)).join(' ') }
