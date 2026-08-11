import { cleanup, render } from '@testing-library/vue'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { Form } from '../src'
import type { FormComponent } from '../src'
import { formContractCases } from '@inlayphp/core/testing'

afterEach(cleanup)

const field = (values: Record<string, unknown>): FormComponent => ({
  type: 'text', hidden: false, columnSpan: 1, extraAttributes: {},
  default: null, placeholder: null, helperText: null, required: false, disabled: false,
  autofocus: false, readOnly: false, ...values,
} as FormComponent)

const resource = (schema: FormComponent[]) => ({
  contract: 'inlay.forms.v1', type: 'form', name: 'contract', action: '/contract', method: 'post',
  columns: 1, submitLabel: 'Save', data: {}, values: {}, errors: {}, schema,
})

describe('Vue form contract', () => {
  it.each(formContractCases)('$name', async ({ fields, errors, expect: expected }) => {
    const view = render(Form, { props: { errors: errors ?? {}, resource: resource(fields.map(field)) as never } })

    // Vue takes focus after the form settles its initial values, so the same
    // assertion needs a tick that React does not.
    await nextTick()
    await nextTick()

    for (const name of expected.named ?? []) {
      expect(view.getByLabelText(name), name).toBeTruthy()
    }

    if (expected.focused) {
      expect(document.activeElement).toBe(view.getByLabelText(expected.focused))
    }

    for (const slot of expected.slots ?? []) {
      expect(view.container.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }

    for (const slot of expected.withoutSlots ?? []) {
      expect(view.container.querySelector(`[data-slot="${slot}"]`), slot).toBeNull()
    }

    if (expected.visuallyHidden) {
      expect(view.container.querySelector(`[data-slot="${expected.visuallyHidden}"]`)?.className).toContain('sr-only')
    }

    for (const text of expected.text ?? []) {
      expect(view.getByText(text), text).toBeTruthy()
    }
  })
})
