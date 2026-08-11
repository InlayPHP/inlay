import { cleanup, render } from '@testing-library/vue'
import { afterEach, describe, expect, it } from 'vitest'
import { Form } from '../src'
import type { FormComponent, FormResource } from '../src'
import { formSlotVocabulary } from '@inlayphp/core/testing'

afterEach(cleanup)

const f = (v: Record<string, unknown>): FormComponent => ({
  type: 'text', hidden: false, columnSpan: 1, extraAttributes: {},
  default: null, placeholder: null, helperText: null, required: false, disabled: false,
  autofocus: false, readOnly: false, prefix: null, suffix: null, rules: [], ...v,
} as unknown as FormComponent)
const action = { name: 'go', label: 'Go', url: '/x', method: 'get', color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }

const schema: FormComponent[] = [
  f({ name: 'plain', label: 'Plain', helperText: 'help', hint: 'Hint', hintIcon: 'i', hintActions: [action] }),
  f({ name: 'req', label: 'Req', required: true, prefix: 'P', suffix: 'S' }),
  f({ type: 'select', name: 'sel', label: 'Sel', options: [{ label: 'A', value: 'a' }] }),
  f({ type: 'checkbox', name: 'chk', label: 'Chk' }),
  f({ type: 'radio', name: 'rad', label: 'Rad', options: [{ label: 'A', value: 'a' }] }),
  f({ type: 'textarea', name: 'ta', label: 'TA' }),
  f({ type: 'toggle', name: 'tg', label: 'TG' }),
  f({ type: 'section', name: 'sec', label: 'Sec', schema: [f({ name: 'in', label: 'In' })], headerActions: [action], footerActions: [action], headerSchema: [f({ name: 'hs', label: 'HS' })], footerSchema: [f({ name: 'fs2', label: 'FS2' })] }),
  f({ type: 'fieldset', name: 'fst', label: 'Fst', schema: [f({ name: 'in2', label: 'In2' })] }),
  f({ type: 'grid', name: 'gr', schema: [f({ name: 'in3', label: 'In3' })] }),
  f({ type: 'flex', name: 'fx', schema: [f({ name: 'in4', label: 'In4' })] }),
  f({ type: 'tabs', name: 'tb', tabs: [{ name: 't1', label: 'T1', schema: [f({ name: 'in5', label: 'In5' })] }] }),
  f({ type: 'wizard', name: 'wz', steps: [{ name: 's1', label: 'S1', schema: [f({ name: 'in6', label: 'In6' })] }] }),
  f({ type: 'repeater', name: 'rp', label: 'Rp', schema: [f({ name: 'in7', label: 'In7' })] }),
]

describe('Vue form slot vocabulary', () => {
  it('publishes exactly the names the shared vocabulary lists', () => {
    const r: FormResource = { contract: 'inlay.forms.v1', type: 'form', name: 'd', action: '/a', method: 'post', columns: 2, submitLabel: 'Save', data: { rp: [{ in7: 'x' }] }, schema }
    const view = render(Form, { props: { errors: { plain: 'bad' }, resource: r } })

    const names = [...new Set([...view.container.querySelectorAll('[data-slot]')].map(n => n.getAttribute('data-slot')!))].sort()
    expect(names).toEqual(formSlotVocabulary.slots)

    // `[data-slot="control"]` was published by React and not here at all, and no
    // per-case assertion noticed: every case that cared found its control by role.
    const controls = [...view.container.querySelectorAll(formSlotVocabulary.controlSelector!)]
      .map(n => `${n.tagName.toLowerCase()}#${n.getAttribute('name') ?? n.id}`).sort()
    expect(controls).toEqual(formSlotVocabulary.controls)
  })
})

describe('Vue action row alignment', () => {
  it('puts each action row where PHP said, not where the renderer prefers', () => {
    // Seven rows here hardcoded their alignment, one of them `justify-center` while
    // its six siblings used `justify-end`, and the section footer read a key only
    // Callout sends — so it fell back to the leading edge where React used trailing.
    const sec = (v: Record<string, unknown>) => f({ type: 'section', name: 'sec', label: 'Sec', schema: [f({ name: 'x', label: 'X' })], headerActions: [action], footerActions: [action], ...v })
    const mount = (component: FormComponent) => render(Form, { props: { resource: { contract: 'inlay.forms.v1', type: 'form', name: 'd', action: '/a', method: 'post', columns: 1, submitLabel: 'Save', data: {}, schema: [component] } } })

    const declared = mount(sec({ headerActionsAlignment: 'between', footerActionsAlignment: 'center' }))
    expect(declared.container.querySelector('[data-slot="header-actions"]')).toHaveClass('justify-between')
    expect(declared.container.querySelector('[data-slot="footer-actions"]')).toHaveClass('justify-center')

    cleanup()

    const silent = mount(sec({}))
    expect(silent.container.querySelector('[data-slot="header-actions"]')).toHaveClass('justify-end')
    expect(silent.container.querySelector('[data-slot="footer-actions"]')).toHaveClass('justify-start')
  })
})

describe('Vue form control sizing', () => {
  it('declares the control height on its own root', () => {
    // Measured at `min-height: 0px` here against 40px in React for the same payload,
    // because the shared control class reads a token no form root declared.
    const view = render(Form, { props: { resource: { contract: 'inlay.forms.v1', type: 'form', name: 'd', action: '/a', method: 'post', columns: 1, submitLabel: 'Save', data: {}, schema: [f({ name: 'x', label: 'X' })] } } })

    const root = view.container.querySelector('[data-slot="root"]') as HTMLElement
    expect(root.style.getPropertyValue('--inlay-control-height')).not.toBe('')
    expect(view.container.querySelector('[data-slot="control"]')?.className).toContain('min-h-(--inlay-control-height)')
  })
})
