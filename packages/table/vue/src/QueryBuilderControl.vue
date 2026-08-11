<script setup lang="ts">
import { buttonSmallClass, controlClass } from '@inlayphp/ui'
import { Select as InlaySelect } from '@inlayphp/ui-vue'
import { computed } from 'vue'
import type { Filter, QueryConstraint, QueryGroup, QueryRule } from './types'
import RemoteRelationshipSelect from './RemoteRelationshipSelect.vue'
defineOptions({ name: 'QueryBuilderControl' })
const props = defineProps<{ filter: Filter; value: unknown; depth?: number; root?: boolean }>()
const emit = defineEmits<{ change: [value: QueryGroup] }>()
const group = computed<QueryGroup>(() => props.value && typeof props.value === 'object' && !Array.isArray(props.value) && Array.isArray((props.value as QueryGroup).children) ? props.value as QueryGroup : { boolean: 'and', children: [] })
const constraints = computed(() => props.filter.constraints ?? [])
const currentDepth = computed(() => props.depth ?? 1)
// Keep query-builder controls on the same border/focus/theme contract as the
// table search and filter controls (and the React adapter).
const control = `${controlClass} text-sm`
const noValue = new Set(['filled', 'blank', 'is_true', 'is_false', 'has', 'does_not_have'])
const booleanOptions = [{ value: 'and', label: 'All conditions' }, { value: 'or', label: 'Any condition' }]
function isGroup(child: QueryRule | QueryGroup): child is QueryGroup { return 'children' in child }
function updateChild(index: number, child: QueryRule | QueryGroup) { emit('change', { ...group.value, children: group.value.children.map((item, itemIndex) => itemIndex === index ? child : item) }) }
function remove(index: number) { emit('change', { ...group.value, children: group.value.children.filter((_, itemIndex) => itemIndex !== index) }) }
function addRule() { const first = constraints.value[0]; if (first) emit('change', { ...group.value, children: [...group.value.children, { constraint: first.name, operator: first.operators[0], value: '' }] }) }
function addGroup() { emit('change', { ...group.value, children: [...group.value.children, { boolean: 'and', children: [] }] }) }
function constraintFor(rule: QueryRule) { return constraints.value.find((item) => item.name === rule.constraint || item.relationship === rule.constraint) ?? constraints.value[0] }
function normalizeOperator(rule: QueryRule, constraint = constraintFor(rule)) {
  if (!constraint) return rule.operator
  if (constraint.operators.includes(rule.operator)) return rule.operator
  const aliases: Record<string, string> = { exists: 'has', not_exists: 'does_not_have', is: 'equals', is_not: 'not_equals' }
  const candidate = aliases[rule.operator]
  return candidate && constraint.operators.includes(candidate) ? candidate : constraint.operators[0] ?? rule.operator
}
function operatorFor(rule: QueryRule) { const constraint = constraintFor(rule); return constraint?.operatorDefinitions?.find(item => item.name === normalizeOperator(rule, constraint)) }
function changeConstraint(rule: QueryRule, name: string) { const next = constraints.value.find((item) => item.name === name)!; return { constraint: next.name, operator: next.operators[0], value: '' } }
function singleValue(value: string | string[]) { return Array.isArray(value) ? value[0] ?? '' : value }
// PHP describes every operator, so nothing here infers behaviour from names.
function acceptsMany(constraint: QueryConstraint, operator: string) { return constraint.operatorDefinitions?.find(item => item.name === operator)?.multiple ?? false }
function selectValue(rule: QueryRule, constraint: QueryConstraint) { return acceptsMany(constraint, rule.operator) ? (Array.isArray(rule.value) ? rule.value.map(String).filter(Boolean) : []) : String(rule.value ?? '') }
function hasValue(rule: QueryRule) { const operator = normalizeOperator(rule); return operatorFor(rule)?.valueType !== 'none' && !noValue.has(operator) }
function isSelect(rule: QueryRule) { return operatorFor(rule)?.valueType === 'select' }
function optionsFor(rule: QueryRule) { const options = operatorFor(rule)?.options; return options?.length ? options : constraintFor(rule)?.options }
function inputType(rule: QueryRule) { const definition = operatorFor(rule); return definition?.valueType === 'number' ? 'number' : definition?.valueType === 'date' ? 'date' : 'text' }
function operatorLabel(constraint: QueryConstraint, operator: string) { return constraint.operatorDefinitions?.find(item => item.name === operator)?.label ?? label(operator) }
function countRules(value: QueryGroup): number { return value.children.reduce((count, child) => count + (isGroup(child) ? countRules(child) : 1), 0) }
function label(value: string) { return value.split('_').map(part => part[0].toUpperCase() + part.slice(1)).join(' ') }
</script>

<template>
  <fieldset :class="root === false ? 'grid gap-3' : 'grid gap-3 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-3'" data-slot="query-builder">
    <legend v-if="root !== false" class="px-1 text-sm font-semibold text-(--inlay-text)">{{ filter.label }}</legend>
    <div class="grid gap-3 border-l-2 border-(--inlay-border) pl-3" :data-depth="currentDepth">
      <div class="flex flex-wrap items-center gap-2"><span class="text-xs font-medium text-(--inlay-muted)">Match</span><InlaySelect :aria-label="`Group ${currentDepth} boolean`" button-class-name="text-sm" class-name="min-w-40" :model-value="group.boolean" :options="booleanOptions" @update:model-value="value => emit('change', { ...group, boolean: singleValue(value) as 'and' | 'or' })" /></div>
      <div v-for="(child, index) in group.children" :key="index" class="flex items-start gap-2">
        <div class="min-w-0 flex-1">
          <QueryBuilderControl v-if="isGroup(child)" :depth="currentDepth + 1" :filter="filter" :root="false" :value="child" @change="updateChild(index, $event)" />
          <div v-else-if="constraintFor(child)" class="grid gap-2 md:grid-cols-[minmax(9rem,1fr)_minmax(9rem,1fr)_minmax(10rem,1.5fr)]">
            <InlaySelect aria-label="Constraint" button-class-name="text-sm" class-name="min-w-0" :model-value="constraintFor(child)!.name" :options="constraints.map(item => ({ value: item.name, label: item.label }))" @update:model-value="value => updateChild(index, changeConstraint(child, singleValue(value)))" />
            <InlaySelect aria-label="Operator" button-class-name="text-sm" class-name="min-w-0" :model-value="normalizeOperator(child)" :options="constraintFor(child)!.operators.map(operator => ({ value: operator, label: operatorLabel(constraintFor(child)!, operator) }))" @update:model-value="value => updateChild(index, { ...child, operator: singleValue(value), value: '' })" />
            <RemoteRelationshipSelect v-if="hasValue(child) && constraintFor(child)!.type === 'relationship-constraint' && constraintFor(child)!.remoteOptions && ['is_related_to', 'is_not_related_to'].includes(normalizeOperator(child))" :constraint="constraintFor(child)!" :control-class="control" :multiple="acceptsMany(constraintFor(child)!, normalizeOperator(child))" :value="child.value" @change="updateChild(index, { ...child, value: $event })" />
            <InlaySelect v-else-if="hasValue(child) && operatorFor(child)?.valueType === 'boolean'" aria-label="Value" button-class-name="text-sm" class-name="min-w-0" :model-value="String(child.value ?? '')" :options="[{ value: '', label: 'Choose…' }, { value: 'true', label: 'True' }, { value: 'false', label: 'False' }]" @update:model-value="value => updateChild(index, { ...child, value: singleValue(value) })" />
            <template v-else-if="hasValue(child) && isSelect(child)"><InlaySelect v-if="acceptsMany(constraintFor(child)!, normalizeOperator(child))" aria-label="Value" button-class-name="text-sm" class-name="min-w-0" multiple :model-value="selectValue({ ...child, operator: normalizeOperator(child) }, constraintFor(child)!)" :options="(optionsFor(child) ?? []).map(option => ({ value: String(option.value), label: option.label }))" @update:model-value="value => updateChild(index, { ...child, value })" /><InlaySelect v-else aria-label="Value" button-class-name="text-sm" class-name="min-w-0" :model-value="selectValue({ ...child, operator: normalizeOperator(child) }, constraintFor(child)!)" :options="[{ value: '', label: 'Choose…' }, ...(optionsFor(child) ?? []).map(option => ({ value: String(option.value), label: option.label }))]" @update:model-value="value => updateChild(index, { ...child, value: singleValue(value) })" /></template>
            <input v-else-if="hasValue(child)" aria-label="Value" :class="control" :type="inputType(child)" :value="String(child.value ?? '')" @input="updateChild(index, { ...child, value: ($event.target as HTMLInputElement).value })" />
            <span v-else class="self-center text-sm text-(--inlay-muted)">No value required</span>
          </div>
        </div>
        <button aria-label="Remove query condition" :class="`${buttonSmallClass} border-transparent bg-transparent px-2 text-(--inlay-danger) shadow-none hover:bg-(--inlay-hover)`" type="button" @click="remove(index)">Remove</button>
      </div>
      <p v-if="!group.children.length" class="text-sm text-(--inlay-muted)">No conditions. All records match.</p>
      <div class="flex flex-wrap gap-2"><button :class="`${buttonSmallClass} font-medium`" :disabled="!constraints.length || countRules(group) >= (filter.maxRules ?? 50)" type="button" @click="addRule">Add condition</button><button :class="`${buttonSmallClass} font-medium`" :disabled="currentDepth >= (filter.maxDepth ?? 5) || countRules(group) >= (filter.maxRules ?? 50)" type="button" @click="addGroup">Add group</button></div>
    </div>
  </fieldset>
</template>
