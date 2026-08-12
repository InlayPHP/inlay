# Responsive layout contract

Inlay's React and Vue renderers share the same mobile-first layout contract. A
host application should not need renderer-specific CSS to keep a panel, form,
table, media picker, import wizard, or widget dashboard usable.

## Host setup

Include both renderer trees in the Tailwind CSS source scan when an application
can render either adapter:

```css
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
@source '../js/**/*.{ts,tsx,vue}';

@layer base {
    html,
    body {
        max-width: 100%;
        overflow-x: clip;
    }
}
```

The shared theme tokens (`--inlay-*`) remain the styling API. Override tokens
or provide a theme object once at the panel/root boundary; controls and
renderers consume those tokens rather than hard-coded colours or dimensions.

## Layout rules

- Page shells use `min-h-dvh`, `min-w-0`, and clip accidental page-level
  overflow without disabling a component's own scroll region.
- Panels wrap their topbar on narrow screens; global search and brand labels
  shrink and truncate instead of forcing the viewport wider.
- Tables keep a `data-slot="table-scroll"` horizontal scroll region. The page
  itself stays within the viewport while long cells, headings, and action
  controls remain readable inside that region.
- Import steps scroll horizontally on small screens, and action rows wrap.
- Media picker headers and footers wrap, with the content area retaining its
  own vertical scroll and using dynamic viewport height (`dvh`).
- Dashboard widgets are one column on phones. Declared spans apply from the
  medium breakpoint onward, so inline grid styles cannot create implicit
  columns or mobile overflow.
- Select menus are bounded by the viewport (`max-w-[calc(100vw-2rem)]`) while
  preserving their intrinsic option text.

Every renderer exposes stable `data-slot` hooks and `classNames` props, so an
application can refine spacing or typography without coupling itself to an
internal component tree.
