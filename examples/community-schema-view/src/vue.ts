import { defineComponent, h } from 'vue'
import type { PropType } from 'vue'
import type { RendererRegistrySet } from '@inlayphp/core'
import type {
  FormComponent,
  FormNestedSchemaOptions,
  FormRendererRegistryTypes,
} from '@inlayphp/forms-vue'

export const orderSummaryView = 'acme/order-summary'

export const OrderSummary = defineComponent({
  name: 'AcmeOrderSummary',
  props: {
    component: { type: Object as PropType<FormComponent>, required: true },
    renderSchema: {
      type: Function as PropType<(options?: FormNestedSchemaOptions) => unknown>,
      required: true,
    },
  },
  setup: (props) => () => h('article', {
    'aria-label': props.component.label,
    'data-view': orderSummaryView,
  }, [
    h('p', String(props.component.data?.number ?? '')),
    h('strong', String(props.component.data?.total ?? '')),
    props.renderSchema() as any,
  ]),
})

export function registerOrderSummary(
  registries: RendererRegistrySet<FormRendererRegistryTypes>,
) {
  return registries.schema.register(orderSummaryView, OrderSummary, {
    owner: '@acme/inlay-order-summary',
  })
}
