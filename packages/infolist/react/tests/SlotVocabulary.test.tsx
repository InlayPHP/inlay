import { cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { Infolist } from '../src'
import type { InfolistComponent, InfolistResource } from '../src'
import { infolistSlotVocabulary } from '@inlayphp/core/testing'

afterEach(cleanup)

const c = (v: Record<string, unknown>): InfolistComponent => ({ type: 'text-entry', hidden: false, columnSpan: 1, extraAttributes: {}, ...v } as unknown as InfolistComponent)

const action = { name: 'go', label: 'Go', url: '/x', method: 'get', color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }

const schema: InfolistComponent[] = [
  c({ name: 'plain', label: 'Plain' }),
  c({ name: 'blank', label: 'Blank' }),
  c({ name: 'rich', label: 'Rich', helperText: 'help',
      aboveLabel: [c({ name: 'al', label: 'AL' })], belowLabel: [c({ name: 'bl', label: 'BL' })],
      beforeLabel: [c({ name: 'bfl', label: 'BFL' })], afterLabel: [c({ name: 'afl', label: 'AFL' })],
      aboveContent: [c({ name: 'ac', label: 'AC' })], belowContent: [c({ name: 'bc', label: 'BC' })],
      beforeContent: [c({ name: 'bfc', label: 'BFC' })], afterContent: [c({ name: 'afc', label: 'AFC' })],
      prefixActions: [action], suffixActions: [action] }),
  c({ type: 'section', name: 'sec', label: 'Sec', schema: [c({ name: 'in', label: 'In' })], headerSchema: [c({ name: 'hs', label: 'HS' })], footerSchema: [c({ name: 'fs', label: 'FS' })] }),
  c({ type: 'fieldset', name: 'fs2', label: 'FS2', schema: [c({ name: 'in2', label: 'In2' })] }),
  c({ type: 'callout', name: 'cal', label: 'Cal', color: 'info' }),
  c({ type: 'empty-state', name: 'es', label: 'ES' }),
  c({ type: 'tabs', name: 'tb', tabs: [{ name: 't1', label: 'T1', schema: [c({ name: 'i3', label: 'I3' })] }] }),
  c({ type: 'wizard', name: 'wz', steps: [{ name: 's1', label: 'S1', schema: [c({ name: 'i4', label: 'I4' })] }] }),
  c({ type: 'repeatable-entry', name: 'rep', label: 'Rep', schema: [c({ name: 'i5', label: 'I5' })] }),
]

describe('React infolist slot vocabulary', () => {
  it('publishes exactly the names the shared vocabulary lists', () => {
    const r: InfolistResource = { contract: 'inlay.infolists.v1', type: 'infolist', name: 'd', columns: 2, schema, data: { plain: 'p', rich: 'r', in: 'i', in2: 'i2', i3: 'x', i4: 'y', rep: [{ i5: 'a' }] } }
    const { container } = render(<Infolist resource={r} slots={{ header: <b>H</b>, footer: <b>F</b> }} />)

    // Twelve of these are composed at runtime here — `${slot}-schema`,
    // `${position}-actions`, and a bare `{slot}` — which is exactly what the static
    // guard cannot read and why it reported them as published by Vue alone.
    const names = [...new Set([...container.querySelectorAll('[data-slot]')].map(n => n.getAttribute('data-slot')!))].sort()
    expect(names).toEqual(infolistSlotVocabulary.slots)
  })
})
