/**
 * The full set of `data-slot` names a payload must produce, checked at runtime.
 *
 * The static guard in `tests/ThemeTokenContractTest.php` compares the literal
 * names in each renderer's source, and exempts `form`, `infolist`, and `panel`
 * because those compose names at runtime — `data-slot={`${slot}-schema`}` resolves
 * to nothing a regex can read. That exemption was honest and it was also a hole:
 * the three largest packages were the three it could not check.
 *
 * Rendering closes it. Each renderer mounts the same payload, collects every
 * `data-slot` in the DOM, and compares the whole set against the list here — so a
 * name one renderer publishes and the other does not fails in one suite.
 *
 * Comparing the whole set matters more than checking for names one at a time.
 * React's form published `control` and Vue's did not, and no per-case assertion
 * noticed, because every case that cared about a control found the control by role
 * or by label instead. `[data-slot="control"]` is the most-used hook in a form:
 * it selected every React input and nothing in Vue.
 *
 * Counts matter too, not only names. Once Vue named its controls the two sets
 * matched exactly, and React was still missing the name on radio inputs — its
 * option group builds its inputs by hand rather than spreading the shared
 * attribute bundle. Only comparing which elements carry the name found it, which
 * is why `controlSelector` exists.
 */

export type SlotVocabulary = {
  /** The package the payload belongs to, for the failure message. */
  name: string
  /**
   * Every `data-slot` the payload must produce, sorted. A renderer publishing a
   * name absent here fails just as loudly as one missing a name listed here: an
   * extra name in one renderer is the same defect seen from the other side.
   */
  slots: string[]
  /**
   * A selector whose matched elements must be identical in both renderers,
   * identified by tag and name. Optional, for the surfaces where the same name is
   * expected on many elements.
   */
  controlSelector?: string
  /** The tag and `name`/`id` of each element matching `controlSelector`, sorted. */
  controls?: string[]
}

/**
 * The form payload exercises every region a schema can produce: the label and
 * content slots around a field, hints and their actions, the four container types,
 * tabs, a wizard, a repeater, and a section's header and footer in both their
 * schema and action forms.
 */
export const formSlotVocabulary: SlotVocabulary = {
  name: 'form',
  slots: [
    'actions', 'control', 'control-wrapper', 'error', 'field', 'fieldset', 'flex',
    'footer-actions', 'footer-schema', 'grid', 'header-actions', 'header-schema',
    'helper-text', 'hint', 'hint-actions', 'hint-icon', 'label', 'label-row', 'repeater-item',
    'root', 'schema', 'schema-component', 'section', 'submit', 'tabs', 'wizard',
  ],
  controlSelector: '[data-slot="control"]',
  controls: [
    'input#chk', 'input#fs2', 'input#hs', 'input#in', 'input#in2', 'input#in3',
    'input#in4', 'input#in5', 'input#in6', 'input#plain', 'input#rad', 'input#req',
    'input#rp.0.in7', 'input#tg', 'select#sel', 'textarea#ta',
  ],
}

/**
 * The infolist payload covers the eight label and content regions around an entry,
 * prefix and suffix actions, a section's header and footer schemas, and every
 * layout type. React composes all eight region names at runtime, which is why the
 * static guard reported twelve names as Vue-only that React publishes too.
 */
export const infolistSlotVocabulary: SlotVocabulary = {
  name: 'infolist',
  slots: [
    'above-content', 'above-label', 'after-content', 'after-label', 'before-content',
    'before-label', 'below-content', 'below-label', 'callout', 'content-row',
    'empty-state', 'empty-value', 'entry', 'fieldset', 'footer', 'footer-schema',
    'header', 'header-schema', 'helper-text', 'label', 'label-row', 'prefix-actions',
    'repeatable', 'repeatable-item', 'root', 'schema', 'schema-component', 'section',
    'suffix-actions', 'tabs', 'value', 'wizard',
  ],
}

/**
 * The panel publishes a different set per navigation mode, so both are listed. The
 * sidebar mode adds the drawer itself, its collapse control, and the scrim.
 *
 * This was the last package the static check could not read, and comparing the two
 * renderings found five divergences in the chrome every page is wrapped in. The
 * breadcrumb landmark sat inside `main` in React and outside it in Vue, was named
 * "Breadcrumb" in one and "Breadcrumbs" in the other, and Vue rendered it even with
 * no breadcrumbs to show — an empty navigation landmark that assistive technology
 * announces. React drew a placeholder icon beside the brand where Vue drew nothing,
 * and Vue had no way to show a registered brand icon at all. An unresolved
 * navigation icon now uses the panel's built-in open-source outline fallback in
 * both renderers. The mobile scrim stayed in React's tree hidden and was mounted on
 * demand in Vue, so a host could not style it without opening the drawer.
 */
export const panelSlotVocabulary: Record<'sidebar' | 'top', SlotVocabulary> = {
  sidebar: {
    name: 'panel (sidebar navigation)',
    slots: [
      'brand', 'header', 'header-actions', 'main', 'mobile-navigation-trigger',
      'mobile-overlay', 'navigation', 'navigation-badge', 'navigation-group',
      'navigation-group-trigger', 'navigation-item', 'root', 'sidebar',
      'sidebar-collapse-trigger', 'tenant-switcher', 'user-menu', 'user-menu-trigger',
    ],
  },
  top: {
    name: 'panel (top navigation)',
    slots: [
      'brand', 'header', 'header-actions', 'main', 'mobile-navigation-trigger',
      'navigation', 'navigation-badge', 'navigation-group',
      'navigation-group-trigger', 'navigation-item', 'root', 'tenant-switcher',
      'user-menu', 'user-menu-trigger',
    ],
  },
}
