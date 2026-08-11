import { cleanup, render, within } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { Infolist } from '../src'
import type { InfolistComponent, InfolistResource } from '../src'
import { infolistContractCases } from '@inlayphp/core/testing'

afterEach(cleanup)

const entry = (values: Record<string, unknown>): InfolistComponent => ({
  type: 'text-entry', hidden: false, columnSpan: 1, extraAttributes: {}, ...values,
} as unknown as InfolistComponent)

const resource = (schema: InfolistComponent[], data: Record<string, unknown>): InfolistResource => ({
  contract: 'inlay.infolists.v1', type: 'infolist', name: 'contract', columns: 1, schema, data,
})

describe('React infolist contract', () => {
  it.each(infolistContractCases)('$name', ({ entries, data, expect: expected }) => {
    const { container } = render(<Infolist resource={resource(entries.map(entry), data ?? {})} />)

    for (const slot of expected.slots ?? []) {
      expect(container.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }

    for (const slot of expected.withoutSlots ?? []) {
      expect(container.querySelector(`[data-slot="${slot}"]`), slot).toBeNull()
    }

    if (expected.visuallyHidden) {
      expect(container.querySelector(`[data-slot="${expected.visuallyHidden}"]`)?.className).toContain('sr-only')
    }

    for (const [slot, classes] of Object.entries(expected.classes ?? {})) {
      const element = container.querySelector(`[data-slot="${slot}"]`)
      for (const name of classes) {
        expect(element?.className, `${slot} → ${name}`).toContain(name)
      }
    }

    for (const [outer, inner] of expected.notNested ?? []) {
      const container_ = container.querySelector(`[data-slot="${outer}"]`)
      const nested = container.querySelector(`[data-slot="${inner}"]`)
      expect(container_?.contains(nested), `${inner} inside ${outer}`).toBe(false)
    }

    for (const text of expected.text ?? []) {
      expect(within(container).getByText(text), text).toBeInTheDocument()
    }
  })
})
