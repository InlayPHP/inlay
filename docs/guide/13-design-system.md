# Orbit default UI guidelines

Inlay’s default panel theme is Orbit: a focused operations workspace for teams
that scan tables, filter records, review details, and complete small mutations
throughout the day. Orbit is not a page-specific skin. It is the default semantic
contract consumed by the Panel, Forms, Tables, Infolists, Actions, Widgets,
Notifications, Media Manager, and permission surfaces.

## The visual contract

- **Canvas:** cool near-white (`#f5f7fb`) with white content surfaces.
- **Navigation:** a bright light sidebar, never a black block in light mode.
- **Accent:** one restrained purple (`#5b64db`) for primary actions, active
  navigation, links, progress, and focus.
- **Separation:** subtle borders first, then whitespace; use shadows only for
  cards, menus, drawers, and dialogs.
- **Geometry:** 7px controls, 10px cards, 14px major panels. Avoid excessive
  pill-shaped controls.
- **Typography:** DM Sans with Hong Kong Traditional Chinese system fallbacks;
  14px body text, 24px page titles, and a small mono eyebrow for technical labels.
- **Icons:** 16px micro icons inline with controls and 18px navigation icons;
  icons use current color and never replace a text label needed for clarity.

## Interaction rules

Every interactive control has a visible default, hover, focus-visible, disabled,
and (when relevant) invalid state. Focus uses a 3px accent ring. Motion is short
and purposeful—140ms for controls and 180ms for structural surfaces—and respects
`prefers-reduced-motion`.

Buttons use at most two sizes in one screen. Use one filled primary action for
the page’s main save/create operation; use outline, soft, or ghost treatment for
secondary actions. Destructive actions are muted by default and become prominent
only in a confirmation context.

Inputs, selects, date controls, textareas, checkboxes, and radios share a 44px
control rhythm. Every field has an associated label, helper text when needed, and
an error message that pairs a stronger danger border with a soft danger surface.
Do not use a black browser-default border as the visual system.

## Navigation and layout

The desktop shell uses a 248px sidebar and a flexible content column. Keep the
active item legible with a soft purple surface and darker purple text; do not
change font weight between default and hover states. At mobile widths the sidebar
becomes a labelled dialog/drawer and the content returns to one column without
horizontal overflow.

Organize navigation into meaningful groups such as **Workspace** and **System**.
Group labels are quiet metadata, not heavy dividers. Keep the top bar reserved
for brand, compact search, tenant/account controls, and a small number of actions.

## Data surfaces

Tables are the primary work surface. Use a subtle table header, readable row
height, consistent cell padding, row hover that changes only the surface, and a
defined empty/loading/overflow state. Keep long emails, IDs, and notes readable;
wrap or scroll rather than clipping with `nowrap`.

Infolists use semantic `dl`, `dt`, and `dd` structure. Labels are muted, values
are strong, and metadata is quieter still. Two columns are appropriate only for
independent key/value pairs; narrow screens return to one column.

## Theme usage

Use the preset through PHP so every renderer receives the same contract:

```php
use Inlay\Design\Design;

return $panel->theme(Design::default());
```

For an application-owned brand, copy the preset and change semantic tokens in one
place:

```php
use Inlay\Design\Design;

final class BrandTheme
{
    public static function make(): \Inlay\Theme\Theme
    {
        return Design::default()
            ->named('brand')
            ->accent('#6b5bd2', '#ffffff')
            ->tokens([
                'sidebar-width' => '15.5rem',
                'control-height' => '2.75rem',
            ]);
    }
}
```

Prefer semantic tokens and stable `data-slot`/`data-field` hooks over page-wide
CSS overrides. Use the generated theme command when the application needs a
versioned CSS file:

```bash
php artisan make:inlay-theme Brand
```

Dark mode is opt-in and swaps the complete semantic surface set. It must preserve
the same spacing, focus, status, and motion rules as light mode.

## Review checklist

Before shipping a panel or plugin screen, check:

1. Light and dark themes use the same semantic roles.
2. Default, hover, active, focus, disabled, invalid, empty, and loading states exist.
3. Controls meet the 44px touch rhythm and labels remain associated.
4. There is one clear primary action and secondary actions are quieter.
5. Tables wrap/scroll safely and infolists remain readable on mobile.
6. Sidebar navigation remains light in light mode and collapses at mobile widths.
7. Motion is reduced when the user requests reduced motion.
