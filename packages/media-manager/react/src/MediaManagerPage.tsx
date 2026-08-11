import { Head } from '@inertiajs/react'
import { MediaManager } from './MediaManager'
import type { MediaManagerPageProps } from './types'

export function MediaManagerPage({ media, flash, theme }: MediaManagerPageProps) {
  return <><Head title="Media library" /><main className="mx-auto w-full max-w-[100rem]">{flash?.success ? <div className="mb-5 rounded-(--inlay-radius) border border-(--inlay-success)/25 bg-(--inlay-success-surface) px-4 py-3 text-sm font-medium text-(--inlay-success)" role="status">{flash.success}</div> : null}<MediaManager resource={media} theme={theme} /></main></>
}
