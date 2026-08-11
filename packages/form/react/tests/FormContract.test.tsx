import { cleanup, render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { Form } from '../src'
import type { FormComponent, FormResource } from '../src'
import { formContractCases } from '@inlayphp/core/testing'

afterEach(cleanup)

const field = (values: Record<string, unknown>): FormComponent => ({
  type: 'text', hidden: false, columnSpan: 1, extraAttributes: {},
  default: null, placeholder: null, helperText: null, required: false, disabled: false,
  autofocus: false, readOnly: false, prefix: null, suffix: null, rules: [], ...values,
} as unknown as FormComponent)

const resource = (schema: FormComponent[]): FormResource => ({
  contract: 'inlay.forms.v1', type: 'form', name: 'contract', action: '/contract', method: 'post',
  columns: 1, submitLabel: 'Save', data: {}, schema,
})

describe('React form contract', () => {
  it.each(formContractCases)('$name', ({ fields, errors, expect: expected }) => {
    const { container } = render(<Form errors={errors ?? {}} resource={resource(fields.map(field))} />)

    for (const name of expected.named ?? []) {
      expect(screen.getByLabelText(name), name).toBeInTheDocument()
    }

    if (expected.focused) {
      expect(document.activeElement).toBe(screen.getByLabelText(expected.focused))
    }

    for (const slot of expected.slots ?? []) {
      expect(container.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }

    for (const slot of expected.withoutSlots ?? []) {
      expect(container.querySelector(`[data-slot="${slot}"]`), slot).toBeNull()
    }

    if (expected.visuallyHidden) {
      expect(container.querySelector(`[data-slot="${expected.visuallyHidden}"]`)?.className).toContain('sr-only')
    }

    for (const text of expected.text ?? []) {
      expect(within(container).getByText(text), text).toBeInTheDocument()
    }
  })
})
