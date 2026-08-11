import type { FormRendererRegistryTypes, SchemaComponentRenderer } from '@inlayphp/forms-react'
import type { RendererRegistrySet } from '@inlayphp/core'

export const orderSummaryView = 'acme/order-summary'

export const OrderSummary: SchemaComponentRenderer = ({ component, renderSchema }) => (
  <article aria-label={component.label} data-view={orderSummaryView}>
    <p>{String(component.data?.number ?? '')}</p>
    <strong>{String(component.data?.total ?? '')}</strong>
    {renderSchema()}
  </article>
)

export function registerOrderSummary(
  registries: RendererRegistrySet<FormRendererRegistryTypes>,
) {
  return registries.schema.register(orderSummaryView, OrderSummary, {
    owner: '@acme/inlay-order-summary',
  })
}
