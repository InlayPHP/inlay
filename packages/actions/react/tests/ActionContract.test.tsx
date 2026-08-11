import { cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ActionButton, useActionRuntime } from '../src'
import type { ActionResource } from '@inlayphp/actions'
import { actionContractCases } from '@inlayphp/core/testing'

afterEach(cleanup)

const base = (values: Record<string, unknown>): ActionResource => ({
  name: 'publish', label: 'Publish', url: null, method: 'post', color: 'default',
  requiresConfirmation: false, icon: null, modalHeading: null, modal: null, ...values,
} as unknown as ActionResource)

function Harness({ action }: { action: ActionResource }) {
  const runtime = useActionRuntime(vi.fn())

  return <ActionButton action={action} runtime={runtime} />
}

describe('React action contract', () => {
  it.each(actionContractCases)('$name', ({ action, expect: expected }) => {
    const { container } = render(<Harness action={base(action)} />)
    const trigger = container.querySelector('button')!

    for (const slot of expected.slots ?? []) {
      expect(trigger.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }

    for (const slot of expected.withoutSlots ?? []) {
      expect(trigger.querySelector(`[data-slot="${slot}"]`), slot).toBeNull()
    }

    for (const [name, value] of Object.entries(expected.attributes ?? {})) {
      expect(trigger.getAttribute(name), name).toBe(value)
    }

    for (const text of expected.withoutText ?? []) {
      expect(trigger.textContent, text).not.toContain(text)
    }

    if (expected.disabled !== undefined) {
      expect(trigger.disabled).toBe(expected.disabled)
    }

    if (expected.iconAt) {
      const children = Array.from(trigger.children)
      const index = children.indexOf(trigger.querySelector('[data-slot="action-icon"]')!)
      expect(index).toBe(expected.iconAt === 'first' ? 0 : children.length - 1)
    }
  })
})
