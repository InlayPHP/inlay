import type { ReactActionRuntime } from '@inlayphp/actions-react'
import { Form } from './Form'
import type { FormProps } from './Form'
import type { FormErrors, FormResource } from './types'

export type ActionFormProps = Omit<FormProps, 'errors' | 'onSubmit' | 'processing' | 'resource' | 'showSubmit'> & {
  runtime: ReactActionRuntime
}

export function ActionForm({ runtime, onChange, ...props }: ActionFormProps) {
  const resource = runtime.state.form as FormResource | null
  if (!resource) return null
  const errors = Object.fromEntries(
    Object.entries(runtime.state.validationErrors)
      .filter(([, messages]) => messages.length > 0)
      .map(([path, messages]) => [path, messages[0]!]),
  )

  return <Form
    {...props}
    errors={errors satisfies FormErrors}
    onChange={(data) => {
      runtime.setData(data)
      onChange?.(data)
    }}
    onSubmit={() => void runtime.confirm()}
    processing={runtime.state.phase === 'executing'}
    resource={resource}
    showSubmit={false}
  />
}
