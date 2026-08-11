import type { ColumnSpanValue, ResponsiveOption, ResponsiveValue } from './types'

const breakpoints = ['default', 'sm', 'md', 'lg', 'xl', '2xl'] as const
const containerBreakpoints = ['sm', 'md', 'lg', 'xl', '2xl'] as const

export const responsiveGridClasses = 'grid-cols-(--inlay-columns) sm:grid-cols-(--inlay-columns-sm) md:grid-cols-(--inlay-columns-md) lg:grid-cols-(--inlay-columns-lg) xl:grid-cols-(--inlay-columns-xl) 2xl:grid-cols-(--inlay-columns-2xl) @sm:grid-cols-(--inlay-columns-at-sm) @md:grid-cols-(--inlay-columns-at-md) @lg:grid-cols-(--inlay-columns-at-lg) @xl:grid-cols-(--inlay-columns-at-xl) @2xl:grid-cols-(--inlay-columns-at-2xl) supports-[not_(container-type:inline-size)]:sm:grid-cols-(--inlay-columns-fallback-sm) supports-[not_(container-type:inline-size)]:md:grid-cols-(--inlay-columns-fallback-md) supports-[not_(container-type:inline-size)]:lg:grid-cols-(--inlay-columns-fallback-lg) supports-[not_(container-type:inline-size)]:xl:grid-cols-(--inlay-columns-fallback-xl) supports-[not_(container-type:inline-size)]:2xl:grid-cols-(--inlay-columns-fallback-2xl)'
export const responsivePlacementClasses = 'col-span-(--inlay-column-span) sm:col-span-(--inlay-column-span-sm) md:col-span-(--inlay-column-span-md) lg:col-span-(--inlay-column-span-lg) xl:col-span-(--inlay-column-span-xl) 2xl:col-span-(--inlay-column-span-2xl) @sm:col-span-(--inlay-column-span-at-sm) @md:col-span-(--inlay-column-span-at-md) @lg:col-span-(--inlay-column-span-at-lg) @xl:col-span-(--inlay-column-span-at-xl) @2xl:col-span-(--inlay-column-span-at-2xl) supports-[not_(container-type:inline-size)]:sm:col-span-(--inlay-column-span-fallback-sm) supports-[not_(container-type:inline-size)]:md:col-span-(--inlay-column-span-fallback-md) supports-[not_(container-type:inline-size)]:lg:col-span-(--inlay-column-span-fallback-lg) supports-[not_(container-type:inline-size)]:xl:col-span-(--inlay-column-span-fallback-xl) supports-[not_(container-type:inline-size)]:2xl:col-span-(--inlay-column-span-fallback-2xl) col-start-(--inlay-column-start) sm:col-start-(--inlay-column-start-sm) md:col-start-(--inlay-column-start-md) lg:col-start-(--inlay-column-start-lg) xl:col-start-(--inlay-column-start-xl) 2xl:col-start-(--inlay-column-start-2xl) @sm:col-start-(--inlay-column-start-at-sm) @md:col-start-(--inlay-column-start-at-md) @lg:col-start-(--inlay-column-start-at-lg) @xl:col-start-(--inlay-column-start-at-xl) @2xl:col-start-(--inlay-column-start-at-2xl) supports-[not_(container-type:inline-size)]:sm:col-start-(--inlay-column-start-fallback-sm) supports-[not_(container-type:inline-size)]:md:col-start-(--inlay-column-start-fallback-md) supports-[not_(container-type:inline-size)]:lg:col-start-(--inlay-column-start-fallback-lg) supports-[not_(container-type:inline-size)]:xl:col-start-(--inlay-column-start-fallback-xl) supports-[not_(container-type:inline-size)]:2xl:col-start-(--inlay-column-start-fallback-2xl) order-(--inlay-order) sm:order-(--inlay-order-sm) md:order-(--inlay-order-md) lg:order-(--inlay-order-lg) xl:order-(--inlay-order-xl) 2xl:order-(--inlay-order-2xl) @sm:order-(--inlay-order-at-sm) @md:order-(--inlay-order-at-md) @lg:order-(--inlay-order-at-lg) @xl:order-(--inlay-order-at-xl) @2xl:order-(--inlay-order-at-2xl) supports-[not_(container-type:inline-size)]:sm:order-(--inlay-order-fallback-sm) supports-[not_(container-type:inline-size)]:md:order-(--inlay-order-fallback-md) supports-[not_(container-type:inline-size)]:lg:order-(--inlay-order-fallback-lg) supports-[not_(container-type:inline-size)]:xl:order-(--inlay-order-fallback-xl) supports-[not_(container-type:inline-size)]:2xl:order-(--inlay-order-fallback-2xl)'
export const responsiveFlexClasses = '[flex-direction:var(--inlay-flex-direction)] sm:[flex-direction:var(--inlay-flex-direction-sm)] md:[flex-direction:var(--inlay-flex-direction-md)] lg:[flex-direction:var(--inlay-flex-direction-lg)] xl:[flex-direction:var(--inlay-flex-direction-xl)] 2xl:[flex-direction:var(--inlay-flex-direction-2xl)] @sm:[flex-direction:var(--inlay-flex-direction-at-sm)] @md:[flex-direction:var(--inlay-flex-direction-at-md)] @lg:[flex-direction:var(--inlay-flex-direction-at-lg)] @xl:[flex-direction:var(--inlay-flex-direction-at-xl)] @2xl:[flex-direction:var(--inlay-flex-direction-at-2xl)] supports-[not_(container-type:inline-size)]:sm:[flex-direction:var(--inlay-flex-direction-fallback-sm)] supports-[not_(container-type:inline-size)]:md:[flex-direction:var(--inlay-flex-direction-fallback-md)] supports-[not_(container-type:inline-size)]:lg:[flex-direction:var(--inlay-flex-direction-fallback-lg)] supports-[not_(container-type:inline-size)]:xl:[flex-direction:var(--inlay-flex-direction-fallback-xl)] supports-[not_(container-type:inline-size)]:2xl:[flex-direction:var(--inlay-flex-direction-fallback-2xl)] [justify-content:var(--inlay-flex-justify)] sm:[justify-content:var(--inlay-flex-justify-sm)] md:[justify-content:var(--inlay-flex-justify-md)] lg:[justify-content:var(--inlay-flex-justify-lg)] xl:[justify-content:var(--inlay-flex-justify-xl)] 2xl:[justify-content:var(--inlay-flex-justify-2xl)] @sm:[justify-content:var(--inlay-flex-justify-at-sm)] @md:[justify-content:var(--inlay-flex-justify-at-md)] @lg:[justify-content:var(--inlay-flex-justify-at-lg)] @xl:[justify-content:var(--inlay-flex-justify-at-xl)] @2xl:[justify-content:var(--inlay-flex-justify-at-2xl)] supports-[not_(container-type:inline-size)]:sm:[justify-content:var(--inlay-flex-justify-fallback-sm)] supports-[not_(container-type:inline-size)]:md:[justify-content:var(--inlay-flex-justify-fallback-md)] supports-[not_(container-type:inline-size)]:lg:[justify-content:var(--inlay-flex-justify-fallback-lg)] supports-[not_(container-type:inline-size)]:xl:[justify-content:var(--inlay-flex-justify-fallback-xl)] supports-[not_(container-type:inline-size)]:2xl:[justify-content:var(--inlay-flex-justify-fallback-2xl)] [align-items:var(--inlay-flex-align)] sm:[align-items:var(--inlay-flex-align-sm)] md:[align-items:var(--inlay-flex-align-md)] lg:[align-items:var(--inlay-flex-align-lg)] xl:[align-items:var(--inlay-flex-align-xl)] 2xl:[align-items:var(--inlay-flex-align-2xl)] @sm:[align-items:var(--inlay-flex-align-at-sm)] @md:[align-items:var(--inlay-flex-align-at-md)] @lg:[align-items:var(--inlay-flex-align-at-lg)] @xl:[align-items:var(--inlay-flex-align-at-xl)] @2xl:[align-items:var(--inlay-flex-align-at-2xl)] supports-[not_(container-type:inline-size)]:sm:[align-items:var(--inlay-flex-align-fallback-sm)] supports-[not_(container-type:inline-size)]:md:[align-items:var(--inlay-flex-align-fallback-md)] supports-[not_(container-type:inline-size)]:lg:[align-items:var(--inlay-flex-align-fallback-lg)] supports-[not_(container-type:inline-size)]:xl:[align-items:var(--inlay-flex-align-fallback-xl)] supports-[not_(container-type:inline-size)]:2xl:[align-items:var(--inlay-flex-align-fallback-2xl)]'

const fullSpanClasses: Record<string, string> = {
  default: 'col-span-full', sm: 'sm:col-span-full', md: 'md:col-span-full', lg: 'lg:col-span-full', xl: 'xl:col-span-full', '2xl': '2xl:col-span-full',
  '@sm': '@sm:col-span-full', '@md': '@md:col-span-full', '@lg': '@lg:col-span-full', '@xl': '@xl:col-span-full', '@2xl': '@2xl:col-span-full',
  '!@sm': 'supports-[not_(container-type:inline-size)]:sm:col-span-full', '!@md': 'supports-[not_(container-type:inline-size)]:md:col-span-full', '!@lg': 'supports-[not_(container-type:inline-size)]:lg:col-span-full', '!@xl': 'supports-[not_(container-type:inline-size)]:xl:col-span-full', '!@2xl': 'supports-[not_(container-type:inline-size)]:2xl:col-span-full',
}

export function responsiveFullSpanClasses(value: ColumnSpanValue | null | undefined): string {
  if (value == null || typeof value === 'number') return ''
  return Object.entries(value).filter(([, span]) => span === 'full').map(([breakpoint]) => fullSpanClasses[breakpoint]).filter(Boolean).join(' ')
}

export function responsiveOptionClasses<T extends string>(value: ResponsiveOption<T> | undefined, fallback: T, classes: Record<T, string>): string {
  if (typeof value === 'string') return classes[value]
  const source = value ?? {}
  const result = [classes[source.default ?? fallback]]
  for (const breakpoint of ['sm', 'md', 'lg', 'xl', '2xl'] as const) {
    if (source[breakpoint]) result.push(`${breakpoint}:${classes[source[breakpoint]!]}`)
  }
  for (const breakpoint of containerBreakpoints) {
    if (source[`@${breakpoint}`]) result.push(`@${breakpoint}:${classes[source[`@${breakpoint}`]!]}`)
    if (source[`!@${breakpoint}`]) result.push(`supports-[not_(container-type:inline-size)]:${breakpoint}:${classes[source[`!@${breakpoint}`]!]}`)
  }
  return result.join(' ')
}

function responsiveOptionStyles<T extends string>(prefix: string, value: ResponsiveOption<T> | undefined, fallback: T) {
  const source = typeof value === 'string' ? { default: value } : value ?? {}
  let current: T = source.default ?? fallback
  const styles: Record<string, string> = {}
  for (const breakpoint of breakpoints) {
    current = source[breakpoint] ?? current
    styles[`${prefix}${breakpoint === 'default' ? '' : `-${breakpoint}`}`] = current
  }
  for (const breakpoint of containerBreakpoints) {
    if (source[`@${breakpoint}`]) styles[`${prefix}-at-${breakpoint}`] = source[`@${breakpoint}`]!
    if (source[`!@${breakpoint}`]) styles[`${prefix}-fallback-${breakpoint}`] = source[`!@${breakpoint}`]!
  }
  return styles
}

export function flexStyles(direction: ResponsiveOption<'row' | 'column'> | undefined, justify: ResponsiveOption<'start' | 'center' | 'end' | 'between' | 'around' | 'evenly'> | undefined, align: ResponsiveOption<'start' | 'center' | 'end' | 'stretch' | 'baseline'> | undefined) {
  const justifyValues = { start: 'flex-start', center: 'center', end: 'flex-end', between: 'space-between', around: 'space-around', evenly: 'space-evenly' } as const
  const alignValues = { start: 'flex-start', center: 'center', end: 'flex-end', stretch: 'stretch', baseline: 'baseline' } as const
  const mappedJustify = typeof justify === 'string' ? justifyValues[justify] : Object.fromEntries(Object.entries(justify ?? {}).map(([key, value]) => [key, justifyValues[value]]))
  const mappedAlign = typeof align === 'string' ? alignValues[align] : Object.fromEntries(Object.entries(align ?? {}).map(([key, value]) => [key, alignValues[value]]))
  return { ...responsiveOptionStyles('--inlay-flex-direction', direction, 'row'), ...responsiveOptionStyles('--inlay-flex-justify', mappedJustify as ResponsiveOption<(typeof justifyValues)[keyof typeof justifyValues]>, 'flex-start'), ...responsiveOptionStyles('--inlay-flex-align', mappedAlign as ResponsiveOption<(typeof alignValues)[keyof typeof alignValues]>, 'flex-start') }
}

export function responsiveStyles(prefix: string, value: ResponsiveValue | ColumnSpanValue | null | undefined, fallback?: number) {
  if (value == null && fallback == null) return {}
  const source = typeof value === 'number' ? { default: value } : value ?? {}
  let current = source.default ?? fallback
  const styles: Record<string, number | 'full'> = {}

  for (const breakpoint of breakpoints) {
    current = source[breakpoint] ?? current
    if (current != null) styles[`${prefix}${breakpoint === 'default' ? '' : `-${breakpoint}`}`] = current
  }
  for (const breakpoint of containerBreakpoints) {
    const container = source[`@${breakpoint}`]
    const fallbackValue = source[`!@${breakpoint}`]
    if (container != null) styles[`${prefix}-at-${breakpoint}`] = container
    if (fallbackValue != null) styles[`${prefix}-fallback-${breakpoint}`] = fallbackValue
  }

  return styles
}

export function gridStyles(value: ResponsiveValue) {
  return Object.fromEntries(Object.entries(responsiveStyles('--inlay-columns', value, 1)).map(([key, columns]) => [key, `repeat(${columns as number}, minmax(0, 1fr))`]))
}

export function placementStyles(component: { columnSpan: ColumnSpanValue; columnStart?: ResponsiveValue | null; order?: ResponsiveValue | null }) {
  return {
    ...responsiveStyles('--inlay-column-span', component.columnSpan, 1),
    ...responsiveStyles('--inlay-column-start', component.columnStart),
    ...responsiveStyles('--inlay-order', component.order),
  }
}
