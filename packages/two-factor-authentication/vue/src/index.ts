export { default as TwoFactorChallengePage } from './TwoFactorChallengePage.vue'
export { default as TwoFactorSettingsPage } from './TwoFactorSettingsPage.vue'

import TwoFactorChallengePage from './TwoFactorChallengePage.vue'
import TwoFactorSettingsPage from './TwoFactorSettingsPage.vue'

export const twoFactorAuthenticationPages = { 'inlay-two-factor/challenge': TwoFactorChallengePage, 'inlay-two-factor/settings': TwoFactorSettingsPage } as const
export type TwoFactorAuthenticationPageName = keyof typeof twoFactorAuthenticationPages
export function resolveTwoFactorAuthenticationPage(name: string) {
  return twoFactorAuthenticationPages[name as TwoFactorAuthenticationPageName]
}
