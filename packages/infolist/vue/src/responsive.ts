import type { ColumnSpanValue, ResponsiveColumns } from './types'

const viewport = ['default', 'sm', 'md', 'lg', 'xl', '2xl'] as const
const containers = ['sm', 'md', 'lg', 'xl', '2xl'] as const

export const responsiveSpanClasses = 'col-span-(--inlay-column-span) sm:col-span-(--inlay-column-span-sm) md:col-span-(--inlay-column-span-md) lg:col-span-(--inlay-column-span-lg) xl:col-span-(--inlay-column-span-xl) 2xl:col-span-(--inlay-column-span-2xl) @sm:col-span-(--inlay-column-span-at-sm) @md:col-span-(--inlay-column-span-at-md) @lg:col-span-(--inlay-column-span-at-lg) @xl:col-span-(--inlay-column-span-at-xl) @2xl:col-span-(--inlay-column-span-at-2xl) supports-[not_(container-type:inline-size)]:sm:col-span-(--inlay-column-span-fallback-sm) supports-[not_(container-type:inline-size)]:md:col-span-(--inlay-column-span-fallback-md) supports-[not_(container-type:inline-size)]:lg:col-span-(--inlay-column-span-fallback-lg) supports-[not_(container-type:inline-size)]:xl:col-span-(--inlay-column-span-fallback-xl) supports-[not_(container-type:inline-size)]:2xl:col-span-(--inlay-column-span-fallback-2xl)'
export const repeatableGridClasses = 'grid-cols-(--inlay-repeatable-grid-columns) sm:grid-cols-(--inlay-repeatable-grid-columns-sm) md:grid-cols-(--inlay-repeatable-grid-columns-md) lg:grid-cols-(--inlay-repeatable-grid-columns-lg) xl:grid-cols-(--inlay-repeatable-grid-columns-xl) 2xl:grid-cols-(--inlay-repeatable-grid-columns-2xl) @sm:grid-cols-(--inlay-repeatable-grid-columns-at-sm) @md:grid-cols-(--inlay-repeatable-grid-columns-at-md) @lg:grid-cols-(--inlay-repeatable-grid-columns-at-lg) @xl:grid-cols-(--inlay-repeatable-grid-columns-at-xl) @2xl:grid-cols-(--inlay-repeatable-grid-columns-at-2xl) supports-[not_(container-type:inline-size)]:sm:grid-cols-(--inlay-repeatable-grid-columns-fallback-sm) supports-[not_(container-type:inline-size)]:md:grid-cols-(--inlay-repeatable-grid-columns-fallback-md) supports-[not_(container-type:inline-size)]:lg:grid-cols-(--inlay-repeatable-grid-columns-fallback-lg) supports-[not_(container-type:inline-size)]:xl:grid-cols-(--inlay-repeatable-grid-columns-fallback-xl) supports-[not_(container-type:inline-size)]:2xl:grid-cols-(--inlay-repeatable-grid-columns-fallback-2xl)'

const full: Record<string, string> = {
  default: 'col-span-full', sm: 'sm:col-span-full', md: 'md:col-span-full', lg: 'lg:col-span-full', xl: 'xl:col-span-full', '2xl': '2xl:col-span-full',
  '@sm': '@sm:col-span-full', '@md': '@md:col-span-full', '@lg': '@lg:col-span-full', '@xl': '@xl:col-span-full', '@2xl': '@2xl:col-span-full',
  '!@sm': 'supports-[not_(container-type:inline-size)]:sm:col-span-full', '!@md': 'supports-[not_(container-type:inline-size)]:md:col-span-full', '!@lg': 'supports-[not_(container-type:inline-size)]:lg:col-span-full', '!@xl': 'supports-[not_(container-type:inline-size)]:xl:col-span-full', '!@2xl': 'supports-[not_(container-type:inline-size)]:2xl:col-span-full',
}

export function spanClasses(value: ColumnSpanValue) {
  if (typeof value === 'number') return responsiveSpanClasses
  return `${responsiveSpanClasses} ${Object.entries(value).filter(([, span]) => span === 'full').map(([breakpoint]) => full[breakpoint]).filter(Boolean).join(' ')}`
}

export function spanStyles(value: ColumnSpanValue) {
  const source = typeof value === 'number' ? { default: value } : value
  const styles: Record<string, number | 'full'> = {}
  let current: number | 'full' = source.default ?? 1
  for (const breakpoint of viewport) {
    current = source[breakpoint] ?? current
    styles[`--inlay-column-span${breakpoint === 'default' ? '' : `-${breakpoint}`}`] = current
  }
  for (const breakpoint of containers) {
    const container = source[`@${breakpoint}`]
    const fallback = source[`!@${breakpoint}`]
    if (container != null) styles[`--inlay-column-span-at-${breakpoint}`] = container
    if (fallback != null) styles[`--inlay-column-span-fallback-${breakpoint}`] = fallback
  }
  return styles
}

export function repeatableGridStyles(value: ResponsiveColumns) {
  const source = typeof value === 'number' ? { default: value } : value
  const styles: Record<string, number> = {}
  let current = source.default ?? 1
  for (const breakpoint of viewport) {
    current = source[breakpoint] ?? current
    styles[`--inlay-repeatable-grid-columns${breakpoint === 'default' ? '' : `-${breakpoint}`}`] = current
  }
  for (const breakpoint of containers) {
    const container = source[`@${breakpoint}`]
    const fallback = source[`!@${breakpoint}`]
    if (container != null) styles[`--inlay-repeatable-grid-columns-at-${breakpoint}`] = container
    if (fallback != null) styles[`--inlay-repeatable-grid-columns-fallback-${breakpoint}`] = fallback
  }

  return styles
}
