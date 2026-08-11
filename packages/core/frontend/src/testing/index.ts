/**
 * Renderer behaviour contracts, shared by the React and Vue test suites.
 *
 * These are data, not assertions: each case names a payload and the observable
 * outcome it must produce, and each renderer's suite checks them with its own
 * library. Describing behaviour once is what stops the two suites drifting into
 * testing different things, which is how `autofocus()` worked in React and did
 * nothing in Vue for as long as it did.
 *
 * They are published under a subpath rather than imported across packages by
 * relative path: a relative import into another package widens TypeScript's
 * declaration root, which silently moves a package's built `index.d.ts` out from
 * under the path its `exports` promises.
 */
export * from './actionContract'
export * from './formContract'
export * from './globalsContract'
export * from './infolistContract'
export * from './menusContract'
export * from './redirectsContract'
export * from './sitesContract'
export * from './slotVocabulary'
export * from './submissionsContract'
export * from './tableContract'
