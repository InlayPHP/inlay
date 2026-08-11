# Component matrix

Every listed component has a PHP builder, a stable JSON type, React rendering, Vue rendering, and adapter coverage.

## Form fields

| PHP component | JSON type | React | Vue |
| --- | --- | --- | --- |
| `TextInput` | `text` | ✓ | ✓ |
| `Textarea` | `textarea` | ✓ | ✓ |
| `Select` | `select` | ✓ | ✓ |
| `Checkbox` | `checkbox` | ✓ | ✓ |
| `CheckboxList` | `checkbox-list` | ✓ | ✓ |
| `Radio` | `radio` | ✓ | ✓ |
| `Toggle` | `toggle` | ✓ | ✓ |
| `ToggleButtons` | `toggle-buttons` | ✓ | ✓ |
| `Hidden` | `hidden` | ✓ | ✓ |
| `ColorPicker` | `color-picker` | ✓ | ✓ |
| `DatePicker` | `date-picker` | ✓ | ✓ |
| `TimePicker` | `time-picker` | ✓ | ✓ |
| `DateTimePicker` | `date-time-picker` | ✓ | ✓ |
| `FileUpload` | `file-upload` | ✓ | ✓ |
| `Slider` | `slider` | ✓ | ✓ |
| `TagsInput` | `tags-input` | ✓ | ✓ |
| `KeyValue` | `key-value` | ✓ | ✓ |
| `CodeEditor` | `code-editor` | ✓ | ✓ |
| `MarkdownEditor` | `markdown-editor` | ✓ | ✓ |
| `RichEditor` | `rich-editor` | ✓ | ✓ |
| `Repeater` | `repeater` | ✓ | ✓ |
| `Builder` | `builder` | ✓ | ✓ |

## Schema and layout

`Section`, `Grid`, `Group`, `Fieldset`, `Tabs`, `Tab`, `Wizard`, `WizardStep`, and `Callout`.

## Table columns

`TextColumn`, `BadgeColumn`, `BooleanColumn`, `IconColumn`, `ImageColumn`, `ColorColumn`, `SelectColumn`, `ToggleColumn`, `TextInputColumn`, and `CheckboxColumn`.

## Table filters and actions

`SelectFilter`, `BooleanFilter`, `TernaryFilter`, `TextFilter`, `DateFilter`, `NumericFilter`, `Action`, and `BulkAction`.

## Imports

`ImportDefinition`, `ImportColumn`, `ImportPreview`, `ImportProcessor`, `ImportResult`, and `ImportFailure` provide the `inlay.imports.v1` workflow. React and Vue both include an accessible `ImportWizard` covering upload, column mapping, preview, progress, and results. Network transport and queue execution stay application-owned adapters.

## Infolist entries

| PHP component | JSON type | React | Vue |
| --- | --- | --- | --- |
| `TextEntry` | `text-entry` | ✓ | ✓ |
| `IconEntry` | `icon-entry` | ✓ | ✓ |
| `ImageEntry` | `image-entry` | ✓ | ✓ |
| `ColorEntry` | `color-entry` | ✓ | ✓ |
| `KeyValueEntry` | `key-value-entry` | ✓ | ✓ |
| `RepeatableEntry` | `repeatable-entry` | ✓ | ✓ |

Infolists reuse the shared schema layouts and support dotted state paths, conditions, formatting, links, copying, nested repeatables, custom renderer registries, and semantic CSS hooks.

## Panels

`Panel`, `PanelRegistry`, `PanelProvider`, `NavigationGroup`, and `NavigationItem` define the `inlay.panels.v1` shell. React and Vue provide responsive sidebar/top navigation, mobile and desktop collapse, active and conditional items, badges, user menus, breadcrumbs, SPA link adapters, icon registries, slots, semantic theme hooks, and the authorization-aware `PanelSwitcher` directory UI.

## Shared behavior

- Automatic labels, defaults, required/disabled/read-only state, helper text, validation errors, and responsive column spans.
- Serializable `visibleWhen`, `hiddenWhen`, `requiredWhen`, and `disabledWhen` conditions with eight allow-listed operators, nested paths, and matching React/Vue evaluation.
- `live()` change/blur/debounce metadata, conditional Laravel `required_if` extraction, and safe wrapper `extraAttributes`.
- Nested state for repeaters, tabs, wizards, sections, grids, groups, and fieldsets.
- Search, allow-listed sorting, allow-listed filtering, pagination, row/header/bulk actions, selection, editable cells, loading states, and empty states.
- Native labels, names, button types, keyboard focus, ARIA states, responsive tables, mobile pagination, and dark-mode styling.
