import { describe, expect, it } from 'vitest'
import { createRendererRegistries } from '@inlayphp/core'
import type { FormRendererRegistryTypes as ReactTypes } from '@inlayphp/forms-react'
import type { FormRendererRegistryTypes as VueTypes } from '@inlayphp/forms-vue'
import {
  OrderSummary as ReactOrderSummary,
  registerOrderSummary as registerReact,
} from '../src/react'
import {
  OrderSummary as VueOrderSummary,
  registerOrderSummary as registerVue,
} from '../src/vue'

describe('community schema view template', () => {
  it('registers the same stable view name for React and Vue', () => {
    const react = createRendererRegistries<ReactTypes>()
    const vue = createRendererRegistries<VueTypes>()

    registerReact(react)
    registerVue(vue)

    expect(react.schema.get('acme/order-summary')).toBe(ReactOrderSummary)
    expect(vue.schema.get('acme/order-summary')).toBe(VueOrderSummary)
    expect(react.schema.registration('acme/order-summary')?.owner).toBe('@acme/inlay-order-summary')
  })
})
