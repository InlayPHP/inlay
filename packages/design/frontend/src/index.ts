export {
  baseTheme,
  customThemeCss,
  customThemeVariables,
  defaultTheme,
  orbitTheme,
  highContrastTheme,
  mergeTheme,
  normalizeThemeTokenName,
  normalizeThemeTokens,
  recipeVariables,
  resolveThemeTokens,
  themeToken,
  themeVariables,
} from '@inlayphp/theme'

export type {
  BuiltInThemeToken,
  ThemeContract,
  ThemeInput,
  ThemeSource,
  ThemeTokens,
  ThemeValue,
} from '@inlayphp/theme'

export {
  badgeClass,
  buttonBaseClass,
  buttonDangerClass,
  buttonExtraSmallClass,
  buttonLargeClass,
  buttonPrimaryClass,
  buttonSecondaryClass,
  buttonSmallClass,
  cardClass,
  controlClass,
  descriptionClass,
  dialogClass,
  focusRingClass,
  iconButtonClass,
  labelClass,
  menuItemClass,
  resolveIcon,
  selectMenuClass,
  selectOptionClass,
  tableCellClass,
  tableHeaderClass,
  tableRowClass,
  recipes,
  recipeFocus,
  recipeMotion,
  recipeSpacing,
  recipeTypography,
  recipeVariants,
} from '@inlayphp/ui'
export type { IconMap, IconRegistry, IconSource } from '@inlayphp/ui'

import { themeVariables } from '@inlayphp/theme'
import type { ThemeContract } from '@inlayphp/theme'

/**
 * Apply the design contract to a root element's inline style.
 *
 * This named façade keeps React, Vue, and framework-neutral shells on the same
 * API while `themeVariables` remains available for direct low-level use.
 */
export function designStyle(
  theme: ThemeContract,
  mode: 'light' | 'dark' = 'light',
): Record<string, string> {
  return themeVariables(theme, mode)
}
