export { TwoFactorChallengePage } from './TwoFactorChallengePage'
export type { TwoFactorChallengePageProps } from './TwoFactorChallengePage'
export { TwoFactorSettingsPage } from './TwoFactorSettingsPage'
export type { TwoFactorEnrollment, TwoFactorSettingsPageProps } from './TwoFactorSettingsPage'

import { TwoFactorChallengePage } from './TwoFactorChallengePage'
import { TwoFactorSettingsPage } from './TwoFactorSettingsPage'

export const twoFactorAuthenticationPages = { 'inlay-two-factor/challenge': TwoFactorChallengePage, 'inlay-two-factor/settings': TwoFactorSettingsPage } as const
export type TwoFactorAuthenticationPageName = keyof typeof twoFactorAuthenticationPages
export function resolveTwoFactorAuthenticationPage(name: string) {
  return twoFactorAuthenticationPages[name as TwoFactorAuthenticationPageName]
}
