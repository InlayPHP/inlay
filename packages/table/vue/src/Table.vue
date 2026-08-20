<script setup lang="ts">
import { buttonBaseClass, buttonDangerClass, buttonPrimaryClass, buttonSecondaryClass, controlClass as sharedControlClass } from '@inlayphp/ui'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import { Select as InlaySelect } from '@inlayphp/ui-vue'
import { router } from "@inertiajs/vue3";
import { computed, getCurrentInstance, onBeforeUnmount, onMounted, ref, toRaw, watch } from "vue";
import type { Component, CSSProperties } from "vue";
import { downloadAction, executeActionEndpoint, interpolateActionUrl, normalizeAction } from "@inlayphp/actions";
import type { ActionExecutionContext } from "@inlayphp/actions";
import TableAction from "./TableAction.vue";
import TableRowActionsCell from "./TableRowActionsCell.vue";
import TableBulkActionTree from "./TableBulkActionTree.vue";
import TableCell from "./TableCell.vue";
import TableColumnHeader from "./TableColumnHeader.vue";
import ColumnLayoutRenderer from "./ColumnLayoutRenderer.vue";
import QueryBuilderControl from './QueryBuilderControl.vue'
import { SchemaRenderer } from '@inlayphp/forms-vue';
import NamedIcon from './NamedIcon.vue';
import { safeUrl } from "./url";
import type {
  Action,
  BulkActionDefinition,
  Column,
  CellPresentation,
  ColumnUpdater,
  ColumnGroup,
  Filter,
  FilterIndicator,
  QueryConstraint,
  QueryGroup,
  QueryRule,
  QueryState,
  SummaryResult,
  TableActionExecutor,
  TableClassNames,
  TableRendererRegistries,
  TableRenderers,
  TableResource,
  TableRow,
  TableTheme,
} from "./types";
import { ColumnUpdateError, updateColumnOnServer } from "./columnUpdate";
const props = withDefaults(
  defineProps<{
    resource: TableResource;
    loading?: boolean;
    manual?: boolean;
    theme?: TableTheme;
    classNames?: TableClassNames;
    renderers?: TableRenderers;
    registries?: TableRendererRegistries;
    actionExecutor?: TableActionExecutor;
    columnUpdater?: ColumnUpdater;
  }>(),
  { loading: false, manual: false },
);
const emit = defineEmits<{
  queryChange: [query: QueryState];
  action: [action: Action, rows: TableRow[], selection?: { mode: 'page'; records: Array<string | number>; query?: QueryState } | { mode: 'query'; excluded: Array<string | number>; query: QueryState }];
  cellChange: [row: TableRow, column: Column, value: unknown];
  cellUpdateError: [error: Error, row: TableRow, column: Column];
  reorder: [records: Array<string | number>, startPosition: number];
  refresh: [];
}>();

function actionVisible(condition: Action["visibleWhen"], row: TableRow): boolean {
  if (!condition) return true;
  if ("logic" in condition) {
    if (condition.logic === "all") return condition.conditions.every((child) => actionVisible(child, row));
    if (condition.logic === "any") return condition.conditions.some((child) => actionVisible(child, row));
    return condition.conditions.length === 1 && !actionVisible(condition.conditions[0], row);
  }
  const value = condition.path.split(".").reduce<unknown>(
    (current, segment) => current && typeof current === "object"
      ? (current as Record<string, unknown>)[segment]
      : undefined,
    row,
  );
  const blank = value == null || value === "" || (Array.isArray(value) && value.length === 0);
  switch (condition.operator) {
    case "equals": return value === condition.value;
    case "not-equals": return value !== condition.value;
    case "in": return Array.isArray(condition.value) && condition.value.includes(value);
    case "not-in": return !Array.isArray(condition.value) || !condition.value.includes(value);
    case "truthy": return Boolean(value);
    case "falsy": return !value;
    case "filled": return !blank;
    case "blank": return blank;
  }
  return true;
}

function triggerButtonClass(action?: Action): string {
  if (action?.color === 'primary') return `${buttonPrimaryClass} font-medium`
  if (action?.color === 'danger') return `${buttonDangerClass} font-medium`
  return `${buttonSecondaryClass} font-medium`
}
const query = ref<QueryState>(
  props.resource.query ?? {
    search: "",
    columnSearches: {},
    sort: null,
    direction: "asc",
    page: props.resource.pagination?.currentPage ?? 1,
    cursor: null,
    filters: defaults(),
    loaded: !props.resource.deferLoading,
    view: props.resource.activeView ?? null,
  },
);
const draftFilters = ref<Record<string, unknown>>({ ...query.value.filters });
const filtersOpen = ref(false);
const columnsOpen = ref(false);
const collapsedGroups = ref<string[]>(props.resource.grouping?.collapsedByDefault ? props.resource.grouping.buckets.map((bucket) => bucket.key) : []);
const reordering = ref(false);
const reorderSubmitting = ref(false);
const reorderError = ref<string | null>(null);
const exportError = ref<string | null>(null);
const exportQueued = ref<string | null>(null);
const orderedRows = ref<TableRow[]>([...props.resource.rows]);
const updatingCells = ref<string[]>([]);
const cellErrors = ref<Record<string, string>>({});
const cellRequests = new Map<string, AbortController>();
const draggedRecordKey = ref<string | number | null>(null);
const dragTargetKey = ref<string | number | null>(null);
const reorderAnnouncement = ref("");
const viewEditorOpen = ref(false);
const viewNameDraft = ref("");
const viewLabelDraft = ref("");
const viewDescriptionDraft = ref("");
const viewSaving = ref(false);
const viewError = ref<string | null>(null);
const initialColumns = initialColumnState();
const columnVisibility = ref<Record<string, boolean>>({ ...initialColumns.visibility });
const draftColumnVisibility = ref<Record<string, boolean>>({ ...initialColumns.visibility });
const columnOrder = ref<string[]>([...initialColumns.order]);
const draftColumnOrder = ref<string[]>([...initialColumns.order]);
const columnsByName = computed(() => new Map(props.resource.columns.map((column) => [column.name, column])));
const summaryPageVisible = computed(() => props.resource.summaries?.pageVisible !== false);
const summaryQueryVisible = computed(() => props.resource.summaries?.queryVisible !== false);
const summaryPage = computed(() => summaryPageVisible.value ? props.resource.summaries?.page ?? {} : {});
const summaryQuery = computed(() => summaryQueryVisible.value ? props.resource.summaries?.query ?? {} : {});
const hasSummaryRows = computed(() => Object.keys(summaryPage.value).length > 0 || Object.keys(summaryQuery.value).length > 0);
// PHP validates the name against one shared list, so an unknown width here
// would be a contract break rather than an author's typo.
function panelWidthClass(width?: string | null) {
  return width == null ? '' : ({ xs: 'max-w-xs', sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-xl', '2xl': 'max-w-2xl', '3xl': 'max-w-3xl', '4xl': 'max-w-4xl', '5xl': 'max-w-5xl', '6xl': 'max-w-6xl', '7xl': 'max-w-7xl', screen: 'max-w-full' }[width] ?? '');
}
const actionsPosition = computed(() => props.resource.actionsPosition === "after-cells" ? "after-columns" : props.resource.actionsPosition ?? "after-columns");
const columns = computed(() =>
  columnOrder.value
    .map((name) => columnsByName.value.get(name))
    .filter((column): column is Column => column != null)
    .filter((column) => columnVisibility.value[column.name] ?? column.visible),
);
// Explicit dimensions opt into deterministic fixed sizing; otherwise let the
// browser distribute intrinsic content widths and use the scroll shell on
// narrow screens.
const fixedTableLayout = computed(() => columns.value.some((column) => Boolean(column.columnWidth || column.minWidth || column.maxWidth)));
const headerSegments = computed(() => columnHeaderSegments(columns.value, props.resource.columnGroups ?? []));
const hasColumnGroups = computed(() => headerSegments.value.some((segment) => segment.group !== null));
const gridLayout = computed(() => Boolean(props.resource.layout?.contentGrid));
const stackedLayout = computed(() => Boolean(props.resource.layout?.stackedOnMobile));
const customLayout = computed(() => Boolean(props.resource.columnLayout?.length));
const cardLayout = computed(() => gridLayout.value || stackedLayout.value || customLayout.value);
const displayRows = computed(() => {
  const grouping = props.resource.grouping;
  if (!grouping?.active) return orderedRows.value.map((row) => ({ kind: "row" as const, key: `row-${String(keyFor(row))}`, row }));
  return grouping.buckets.flatMap((bucket) => {
    const items: Array<{ kind: "group"; key: string; bucket: typeof bucket } | { kind: "row"; key: string; row: TableRow }> = [{ kind: "group", key: `group-${bucket.key}`, bucket }];
    if (!collapsedGroups.value.includes(bucket.key) && !grouping.groupsOnly) {
      items.push(...orderedRows.value.filter((row) => bucket.rowKeys.includes(String(keyFor(row)))).map((row) => ({ kind: "row" as const, key: `row-${String(keyFor(row))}`, row })));
    }
    return items;
  });
});
const rowIndexFor = (row: TableRow) => orderedRows.value.findIndex((candidate) => keyFor(candidate) === keyFor(row));
const selected = ref<Array<string | number>>([]);
const allMatchingSelected = ref(false);
const excluded = ref<Array<string | number>>([]);
const selectableKeys = computed(() => props.resource.selection?.recordKeys ?? props.resource.rows.map(keyFor));
const selectionMaximum = computed(() => props.resource.selection?.maximum ?? null);
const selectAllKeys = computed(() => selectableKeys.value.slice(0, selectionMaximum.value ?? selectableKeys.value.length));
const isKeySelected = (key: string | number) => allMatchingSelected.value ? !excluded.value.includes(key) : selected.value.includes(key);
const allSelectableSelected = computed(() => selectAllKeys.value.length > 0 && selectAllKeys.value.every(isKeySelected));
const someSelectableSelected = computed(() => selectableKeys.value.some(isKeySelected));
const selectedCount = computed(() => allMatchingSelected.value ? Math.max(0, (props.resource.selection?.total ?? 0) - excluded.value.length) : selected.value.length);
// Imported rather than written out: this string had drifted from the one React
// uses, losing `aria-invalid:ring-(--inlay-danger)` so an invalid control showed no
// red ring, among other differences.
const controlClass = sharedControlClass
const primaryButton = `${buttonPrimaryClass} font-semibold`
const secondaryButton = `${buttonSecondaryClass} font-semibold`
const smallButton = `${buttonBaseClass} min-h-(--inlay-button-sm-height) px-2.5 py-1 text-sm font-medium`
const themeStyle = computed(() => ({
  ...customThemeVariables(props.theme),
  ...recipeVariables(props.theme),
  "--inlay-accent": themeToken(props.theme, "accent", "var(--inlay-default-accent, #4f46e5)"),
  "--inlay-accent-foreground": themeToken(props.theme, "accent-foreground", "var(--inlay-panel-accent-foreground, #ffffff)"),
  "--inlay-radius": themeToken(props.theme, "radius", "var(--inlay-panel-radius, 0.75rem)"),
  "--inlay-surface": themeToken(props.theme, "surface", "var(--inlay-default-surface, #ffffff)"),
  "--inlay-surface-muted": themeToken(props.theme, ["surface-muted", "muted-surface"], "var(--inlay-default-surface-muted, color-mix(in srgb, var(--inlay-surface) 94%, var(--inlay-text)))"),
  "--inlay-hover": themeToken(props.theme, ["table-row-hover", "row-hover", "hover"], "var(--inlay-table-row-hover, var(--inlay-panel-hover, color-mix(in srgb, var(--inlay-accent) 6%, var(--inlay-surface))))"),
  "--inlay-foreground": themeToken(props.theme, ["foreground", "text"], "var(--inlay-default-foreground, #18181b)"),
  "--inlay-text": "var(--inlay-foreground)",
  "--inlay-muted": themeToken(props.theme, "muted", "var(--inlay-default-muted, #71717a)"),
  "--inlay-border": themeToken(props.theme, "border", "var(--inlay-default-border, rgb(24 24 27 / 0.12))"),
  "--inlay-control-border": themeToken(props.theme, ["control-border", "border"], "var(--inlay-panel-control-border, #d4d4d8)"),
  "--inlay-focus-ring": "color-mix(in srgb, var(--inlay-focus-ring-color) 22%, transparent)",
  "--inlay-danger": themeToken(props.theme, "danger", "var(--inlay-default-danger, #dc2626)"),
  "--inlay-danger-surface": themeToken(props.theme, "danger-surface", "var(--inlay-default-danger-surface, color-mix(in srgb, var(--inlay-danger) 8%, var(--inlay-surface)))"),
  "--inlay-success": themeToken(props.theme, "success", "var(--inlay-default-success, #16a34a)"),
  "--inlay-success-surface": themeToken(props.theme, "success-surface", "var(--inlay-default-success-surface, rgb(22 163 74 / 0.08))"),
  "--inlay-warning": themeToken(props.theme, "warning", "var(--inlay-default-warning, #d97706)"),
  "--inlay-warning-surface": themeToken(props.theme, "warning-surface", "var(--inlay-default-warning-surface, rgb(217 119 6 / 0.1))"),
  "--inlay-info": themeToken(props.theme, "info", "var(--inlay-default-info, #0284c7)"),
  "--inlay-info-surface": themeToken(props.theme, "info-surface", "var(--inlay-default-info-surface, rgb(2 132 199 / 0.08))"),
  "--inlay-overlay": themeToken(props.theme, "overlay", "var(--inlay-panel-overlay, rgb(24 24 27 / 0.55))"),
  "--inlay-scrim": themeToken(props.theme, "scrim", "var(--inlay-panel-scrim, rgb(0 0 0 / 0.3))"),
  "--inlay-control-height": themeToken(props.theme, "control-height", "var(--inlay-panel-control-height, 2.5rem)"),
  "--inlay-button-height": themeToken(props.theme, "button-height", "var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))"),
  "--inlay-button-xs-height": themeToken(props.theme, ["button-xs-height", "button-extra-small-height"], "var(--inlay-panel-button-xs-height, 2rem)"),
  "--inlay-button-sm-height": themeToken(props.theme, ["button-sm-height", "button-small-height"], "var(--inlay-panel-button-sm-height, 2.25rem)"),
  "--inlay-button-lg-height": themeToken(props.theme, ["button-lg-height", "button-large-height"], "var(--inlay-panel-button-lg-height, 2.75rem)"),
  "--inlay-icon-button-size": themeToken(props.theme, "icon-button-size", "var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))"),
  "--inlay-shadow": themeToken(props.theme, "shadow", "var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))"),
}));
const activeFilters = computed(() =>
  props.resource.filters.filter((filter) =>
    isActiveFilter(query.value.filters[filter.name]),
  ),
);
const filtersLayout = computed(() => props.resource.filtersLayout ?? "dropdown");
const chipFilters = computed(() => props.resource.filters.filter(filter => filter.type === 'select-filter' && (filter.options?.length ?? 0) > 0));
const filtersFormColumns = computed(() => Math.min(Math.max(props.resource.filtersFormColumns ?? 3, 1), 6));
const filtersResetActionPosition = computed(() => props.resource.filtersResetActionPosition ?? "header");
// A cell offering several actions opens them in a menu, but each one still runs
// through the row-action boundary the single-action cell uses.
const openColumnActions = ref<string | null>(null);
function toggleColumnActions(row: TableRow, column: Column) {
  const key = cellKey(row, column);
  openColumnActions.value = openColumnActions.value === key ? null : key;
}

const filterSearches = ref<Record<string, string>>({})
const remoteFilterOptions = ref<Record<string, Array<{ value: string | number; label: string }>>>({})
const filterSearchTimers = new Map<string, ReturnType<typeof setTimeout>>()
async function loadFilterOptions(filter: Filter, term: string) {
  const endpoint = filter.remoteOptions?.endpoint
  if (!endpoint) return
  const url = new URL(endpoint, window.location.origin)
  url.searchParams.set('search', term)
  const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
  if (!response.ok) return
  const payload = await response.json() as { options?: Array<{ value: string | number; label: string }> }
  if (Array.isArray(payload.options)) remoteFilterOptions.value = { ...remoteFilterOptions.value, [filter.name]: payload.options }
}
function searchFilterOptions(filter: Filter, term: string) {
  filterSearches.value = { ...filterSearches.value, [filter.name]: term }
  const existing = filterSearchTimers.get(filter.name)
  if (existing !== undefined) clearTimeout(existing)
  filterSearchTimers.set(filter.name, setTimeout(() => void loadFilterOptions(filter, term), 250))
}
onBeforeUnmount(() => { filterSearchTimers.forEach(timer => clearTimeout(timer)); filterSearchTimers.clear() })

function filtersPanelVisible() {
  if (!props.resource.filters.length) return false;

  return (
    filtersLayout.value === "above-content" ||
    filtersLayout.value === "below-content" ||
    filtersOpen.value
  );
}

function closeFilterModalOnEscape(event: KeyboardEvent) {
  if (event.key === "Escape" && filtersLayout.value === "modal" && filtersOpen.value) {
    filtersOpen.value = false;
  }
}

function filtersPanelStyle(): CSSProperties | undefined {
  const maxHeight = props.resource.filtersFormMaxHeight;

  return maxHeight ? { maxHeight, overflowY: "auto" } : undefined;
}

// A search only leaves the browser when PHP says it should: immediately, after
// the configured debounce, or when the field is left or Enter is pressed.
const searchDraft = ref(query.value.search);
let searchTimer: ReturnType<typeof setTimeout> | null = null;
function commitSearch(search: string) {
  if (searchTimer) clearTimeout(searchTimer);
  if (search !== query.value.search) changeQuery({ search, page: 1 });
}
function onSearchInput(search: string) {
  searchDraft.value = search;
  if (props.resource.searchOnBlur) return;
  if (searchTimer) clearTimeout(searchTimer);
  const debounce = props.resource.searchDebounce ?? 0;
  if (debounce <= 0) {
    commitSearch(search);

    return;
  }
  searchTimer = setTimeout(() => commitSearch(search), debounce);
}
onBeforeUnmount(() => { if (searchTimer) clearTimeout(searchTimer); });
/** Attributes are sanitized in PHP; this keeps a hand-written payload harmless too. */
function safeAttributes(attributes?: Record<string, string>) {
  if (!attributes) return {};
  const unsafe = new Set(["style", "srcdoc", "href", "src", "formaction", "action", "key", "ref"]);

  return Object.fromEntries(
    Object.entries(attributes).filter(([key, value]) =>
      typeof value === "string" && !unsafe.has(key.toLowerCase()) && !key.toLowerCase().startsWith("on")),
  );
}
function cellAttributesFor(row: TableRow, column: Column) {
  const presentation = (row.__inlay as { columns?: Record<string, CellPresentation> } | undefined)?.columns?.[column.name];

  return safeAttributes({ ...(column.extraCellAttributes ?? {}), ...(presentation?.cellAttributes ?? {}) });
}
function contentAttributesFor(row: TableRow, column: Column) {
  const presentation = (row.__inlay as { columns?: Record<string, CellPresentation> } | undefined)?.columns?.[column.name];

  return safeAttributes({ ...(column.extraAttributes ?? {}), ...(presentation?.attributes ?? {}) });
}
function schemaFilterValues(name: string): Record<string, unknown> {
  const value = draftFilters.value[name];

  return value && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : {};
}
/** Matched by type and label: a custom summarizer may publish no page value. */
function pageSummary(column: string, summary: SummaryResult) {
  return props.resource.summaries?.page[column]?.find(
    candidate => candidate.type === summary.type && candidate.label === summary.label,
  );
}
const indicators = computed<FilterIndicator[]>(() =>
  props.resource.filterIndicatorsHidden
    ? []
    : props.resource.filterIndicators
      ?? activeFilters.value.map(filter => ({
        filter: filter.name,
        field: filter.name,
        label: `${filter.label}: ${filterDisplayValue(filter, query.value.filters[filter.name])}`,
      })),
);
function removeIndicator(indicator: FilterIndicator) {
  const [name, ...path] = indicator.field.split(".");
  const next = { ...query.value.filters };
  if (path.length === 0) delete next[name];
  else {
    const branch = { ...(next[name] as Record<string, unknown>) };
    delete branch[path.join(".")];
    if (Object.keys(branch).length === 0) delete next[name];
    else next[name] = branch;
  }
  draftFilters.value = { ...next };
  changeQuery({ filters: next, page: 1 });
}
watch(
  () => props.resource.query,
  (value) => {
    if (value) {
      query.value = value;
      draftFilters.value = { ...value.filters };
      // The server can replace the query without `changeQuery()` (for
      // example, an Inertia visit started by a parent). Selection belongs to
      // the query that produced the current rows; keeping it here would send
      // stale records or exclusions to a later bulk action.
      selected.value = [];
      allMatchingSelected.value = false;
      excluded.value = [];
    }
  },
);
watch([() => props.resource.rows, () => props.resource.selection], () => {
  selected.value = selected.value.filter(key => selectableKeys.value.includes(key));
  if (!reordering.value) orderedRows.value = [...props.resource.rows];
  for (const controller of cellRequests.values()) controller.abort();
  cellRequests.clear();
  updatingCells.value = [];
  cellErrors.value = {};
});
onBeforeUnmount(() => {
  for (const controller of cellRequests.values()) controller.abort();
  cellRequests.clear();
});
function keyFor(row: TableRow) {
  return row[props.resource.primaryKey] as string | number;
}
function cellKey(row: TableRow, column: Column) {
  return `${String(keyFor(row))}:${column.name}`;
}
function columnState(row: TableRow, path: string): unknown {
  const presentation = (row.__inlay as { columns?: Record<string, CellPresentation> } | undefined)?.columns?.[path];
  return presentation ? presentation.state : getAtPath(row, path);
}
function updateRowColumn(rows: TableRow[], record: string | number, path: string, state: unknown): TableRow[] {
  return rows.map((row) => {
    if (String(keyFor(row)) !== String(record)) return row;
    const rawRow = toRaw(row);
    const next = { ...rawRow };
    const inlay = rawRow.__inlay as { columns?: Record<string, CellPresentation> } | undefined;
    const existingPresentation = inlay?.columns?.[path];
    if (inlay) {
      next.__inlay = {
        ...inlay,
        columns: {
          ...inlay.columns,
          ...(existingPresentation ? { [path]: { ...existingPresentation, state } } : {}),
        },
      };
    }
    const segments = path.split('.');
    let target: TableRow = next;
    for (const segment of segments.slice(0, -1)) {
      const value = target[segment];
      target[segment] = value && typeof value === 'object' && !Array.isArray(value)
        ? { ...(value as TableRow) }
        : {};
      target = target[segment] as TableRow;
    }
    target[segments.at(-1)!] = state;
    return next;
  });
}
function handleCellChange(row: TableRow, column: Column, state: unknown) {
  if (!props.resource.editableColumns?.url || !column.editable) {
    emit('cellChange', row, column, state);
    return;
  }

  const key = cellKey(row, column);
  const previous = columnState(row, column.name);
  cellRequests.get(key)?.abort();
  const controller = new AbortController();
  cellRequests.set(key, controller);
  orderedRows.value = updateRowColumn(orderedRows.value, keyFor(row), column.name, state);
  const nextErrors = { ...cellErrors.value };
  delete nextErrors[key];
  cellErrors.value = nextErrors;
  if (!updatingCells.value.includes(key)) updatingCells.value = [...updatingCells.value, key];

  void (props.columnUpdater ?? updateColumnOnServer)({
    resource: props.resource,
    row,
    column,
    state,
    signal: controller.signal,
  }).then((response) => {
    if (cellRequests.get(key) !== controller) return;
    orderedRows.value = updateRowColumn(orderedRows.value, keyFor(row), column.name, response.state);
    emit('cellChange', updateRowColumn([row], keyFor(row), column.name, response.state)[0], column, response.state);
  }).catch((error: unknown) => {
    if (controller.signal.aborted || cellRequests.get(key) !== controller) return;
    orderedRows.value = updateRowColumn(orderedRows.value, keyFor(row), column.name, previous);
    const resolved = error instanceof Error ? error : new Error('Column update failed.');
    const message = error instanceof ColumnUpdateError
      ? error.errors.state?.[0] ?? error.message
      : resolved.message;
    cellErrors.value = { ...cellErrors.value, [key]: message };
    emit('cellUpdateError', resolved, row, column);
  }).finally(() => {
    if (cellRequests.get(key) !== controller) return;
    cellRequests.delete(key);
    updatingCells.value = updatingCells.value.filter((item) => item !== key);
  });
}
function sortColumn(column: Column) {
  changeQuery({
    sort: column.name,
    direction: query.value.sort === column.name && query.value.direction === 'asc' ? 'desc' : 'asc',
    page: 1,
  });
}
function searchColumn(column: Column, value: string) {
  const columnSearches = { ...(query.value.columnSearches ?? {}) };
  if (value) columnSearches[column.name] = value;
  else delete columnSearches[column.name];
  changeQuery({ columnSearches, page: 1 });
}
function moveRecord(row: TableRow, offset: -1 | 1) {
  const index = orderedRows.value.findIndex((candidate) => keyFor(candidate) === keyFor(row));
  const target = index + offset;
  if (index < 0 || target < 0 || target >= orderedRows.value.length) return;
  const next = [...orderedRows.value];
  [next[index], next[target]] = [next[target], next[index]];
  orderedRows.value = next;
  reorderAnnouncement.value = `Moved row ${keyFor(row)} to position ${target + 1}.`;
}
function startDragging(row: TableRow, event: DragEvent) {
  const key = keyFor(row);
  draggedRecordKey.value = key;
  event.dataTransfer!.effectAllowed = 'move';
  event.dataTransfer!.setData('text/plain', String(key));
}
function stopDragging() {
  draggedRecordKey.value = null;
  dragTargetKey.value = null;
}
function dropRecord(targetRow: TableRow) {
  if (draggedRecordKey.value === null) return;
  const source = orderedRows.value.findIndex(row => String(keyFor(row)) === String(draggedRecordKey.value));
  const target = orderedRows.value.findIndex(row => String(keyFor(row)) === String(keyFor(targetRow)));
  if (source < 0 || target < 0 || source === target) {
    stopDragging();
    return;
  }
  const next = [...orderedRows.value];
  const [moved] = next.splice(source, 1);
  next.splice(target, 0, moved);
  orderedRows.value = next;
  reorderAnnouncement.value = `Moved row ${draggedRecordKey.value} to position ${target + 1}.`;
  stopDragging();
}
function cancelReordering() {
  orderedRows.value = [...props.resource.rows];
  reordering.value = false;
  reorderError.value = null;
  stopDragging();
  reorderAnnouncement.value = "";
}
function saveReordering() {
  const records = orderedRows.value.map(keyFor);
  const startPosition = props.resource.pagination?.from ?? 1;
  reorderError.value = null;
  emit('reorder', records, startPosition);
  if (props.manual || !props.resource.reordering?.url) {
    reordering.value = false;
    return;
  }
  reorderSubmitting.value = true;
  router.patch(props.resource.reordering.url, { table: props.resource.name, records, startPosition, version: props.resource.reordering.version ?? null }, {
    preserveScroll: true,
    onSuccess: () => { reordering.value = false; },
    onError: (errors) => {
      const message = Object.values(errors as Record<string, unknown>)
        .flatMap((value) => Array.isArray(value) ? value : [value])
        .find((value): value is string => typeof value === 'string' && value.trim() !== '');
      reorderError.value = message ?? 'The table order could not be saved. Reload the table and try again.';
    },
    onFinish: () => { reorderSubmitting.value = false; },
  });
}
function changeQuery(patch: Partial<QueryState>) {
  const resetCursor = ["search", "columnSearches", "sort", "filters", "group", "groupDirection", "view"].some((key) =>
    Object.prototype.hasOwnProperty.call(patch, key),
  );
  query.value = {
    ...query.value,
    ...(resetCursor ? { cursor: null } : {}),
    ...patch,
  };
  if (resetCursor) {
    selected.value = [];
    allMatchingSelected.value = false;
    excluded.value = [];
  }
  persistQueryState(query.value);
  emit("queryChange", query.value);
  if (!props.manual)
    router.get(
      window.location.pathname,
      flattenQuery(props.resource.name, query.value) as never,
      {
        preserveState: true,
        preserveScroll: true,
        queryStringArrayFormat: "indices",
        replace: true,
      },
    );
}
function chooseView(name: string) {
  const view = props.resource.views?.find(item => item.name === name);
  const preset = view?.query ?? {};
  changeQuery({
    view: view?.name ?? null,
    search: typeof preset.search === 'string' ? preset.search : '',
    columnSearches: preset.columnSearches ?? {},
    sort: typeof preset.sort === 'string' ? preset.sort : null,
    direction: preset.direction === 'desc' ? 'desc' : 'asc',
    filters: preset.filters && typeof preset.filters === 'object' && !Array.isArray(preset.filters)
      ? preset.filters as Record<string, unknown>
      : defaults(),
    group: typeof preset.group === 'string' ? preset.group : null,
    groupDirection: preset.groupDirection === 'desc' ? 'desc' : 'asc',
    perPage: preset.perPage ?? null,
    page: 1,
    cursor: null,
  });
}
const activePersonalView = computed(() => props.resource.views?.find(view => view.name === query.value.view && view.personal === true));
function personalViewQuery() {
  return {
    search: query.value.search,
    columnSearches: query.value.columnSearches ?? {},
    sort: query.value.sort,
    direction: query.value.direction,
    filters: query.value.filters,
    group: query.value.group ?? null,
    groupDirection: query.value.groupDirection ?? 'asc',
    perPage: query.value.perPage ?? null,
  };
}
function savePersonalView() {
  const management = props.resource.viewManagement;
  if (!management || viewNameDraft.value.trim() === '' || viewLabelDraft.value.trim() === '') return;
  viewSaving.value = true;
  viewError.value = null;
  const editing = activePersonalView.value !== undefined;
  const payload = { _inlay_table_view: 'save', table: props.resource.name, name: viewNameDraft.value.trim(), originalName: editing ? activePersonalView.value?.name : null, label: viewLabelDraft.value.trim(), description: viewDescriptionDraft.value.trim() || null, query: personalViewQuery() };
  const finish = () => { viewSaving.value = false; viewEditorOpen.value = false; viewNameDraft.value = ''; viewLabelDraft.value = ''; viewDescriptionDraft.value = ''; router.reload(); };
  const fail = () => { viewSaving.value = false; viewError.value = 'The view could not be saved.'; };
  router.post(management.url, payload as never, { preserveScroll: true, onSuccess: finish, onError: fail });
}
function deletePersonalView() {
  const management = props.resource.viewManagement;
  const view = activePersonalView.value;
  if (!management || !view || !window.confirm(`Delete ${view.label}?`)) return;
  const endpoint = `${management.url}${management.url.includes('?') ? '&' : '?'}${new URLSearchParams({ _inlay_table_view: 'delete', table: props.resource.name, name: view.name }).toString()}`;
  router.delete(endpoint, { preserveScroll: true, onSuccess: () => router.reload() });
}
onMounted(() => {
  window.addEventListener("keydown", closeFilterModalOnEscape);
  const restored = restoredQueryState();
  if (restored) changeQuery({ ...restored, page: 1, cursor: null });
});
onBeforeUnmount(() => window.removeEventListener("keydown", closeFilterModalOnEscape));
function paginationMode() {
  return props.resource.pagination?.mode ?? "length-aware";
}
function currentPage() {
  return props.resource.pagination?.currentPage ?? 1;
}
function lastPage() {
  return props.resource.pagination?.lastPage ?? currentPage();
}
function previousPage() {
  const pagination = props.resource.pagination;
  if (!pagination) return;
  if (paginationMode() === "cursor") {
    changeQuery({ cursor: pagination.previousCursor ?? null, page: 1 });
  } else {
    changeQuery({ page: currentPage() - 1, cursor: null });
  }
}
function nextPage() {
  const pagination = props.resource.pagination;
  if (!pagination) return;
  if (paginationMode() === "cursor") {
    changeQuery({ cursor: pagination.nextCursor ?? null, page: 1 });
  } else {
    changeQuery({ page: currentPage() + 1, cursor: null });
  }
}
function perPageOptions() {
  return props.resource.pagination?.perPageOptions ?? [];
}
function changePerPage(value: string) {
  changeQuery({ perPage: value === "all" ? "all" : Number(value), page: 1, cursor: null });
}
function paginationSummary() {
  const pagination = props.resource.pagination;
  if (!pagination) return "";
  if (pagination.total != null && pagination.from != null && pagination.to != null)
    return `Showing ${pagination.from}–${pagination.to} of ${pagination.total}`;
  if (paginationMode() === "cursor") return "Cursor pagination";
  if (pagination.from != null && pagination.to != null)
    return `Showing ${pagination.from}–${pagination.to}`;
  return `Page ${currentPage()}${paginationMode() === "length-aware" ? ` of ${lastPage()}` : ""}`;
}
watch(
  () => [props.resource.deferLoading, query.value.loaded] as const,
  ([deferred, loaded]) => {
    if (deferred && !loaded) changeQuery({ loaded: true });
  },
  { immediate: true },
);
// A table polls only while the tab is being looked at: one left open in a
// background tab would otherwise keep asking the server forever. A host that
// listens for `refresh` owns the request, so the table does not also reload —
// which is what React does with `onRefresh`, and what this used to get wrong.
const instance = getCurrentInstance();
function hostHandlesRefresh(): boolean {
  return props.manual || Boolean(instance?.vnode.props?.onRefresh);
}
watch(
  () => [props.resource.pollIntervalMs, query.value.loaded] as const,
  ([interval, loaded], _previous, onCleanup) => {
    if (!interval || loaded === false) return;
    const timer = window.setInterval(() => {
      if (document.hidden) return;
      emit("refresh");
      if (!hostHandlesRefresh()) router.reload();
    }, interval);
    onCleanup(() => window.clearInterval(timer));
  },
  { immediate: true },
);
function recordUrl(row: TableRow) {
  return props.resource.recordUrls?.[String(keyFor(row))];
}
function visitRecord(row: TableRow, event: Event) {
  const target = event.target;
  if (
    target instanceof Element &&
    target.closest("a, button, input, select, textarea, label, [data-no-record-click]")
  ) return;
  const url = safeUrl(recordUrl(row));
  if (!url) return;
  if (props.resource.openRecordUrlInNewTab) {
    window.open(url, "_blank", "noopener,noreferrer");
  } else {
    router.visit(url);
  }
}
const isLoading = computed(
  () => props.loading || Boolean(props.resource.deferLoading && !query.value.loaded),
);
function changeFilter(name: string, value: unknown) {
  const filters = {
    ...(props.resource.deferFilters ? draftFilters.value : query.value.filters),
    [name]: value,
  };
  draftFilters.value = filters;
  if (!props.resource.deferFilters) changeQuery({ filters, page: 1 });
}
function chipSelected(filter: Filter, value: string | number): boolean {
  const selected = query.value.filters[filter.name];
  if (value === '') return selected == null || selected === '' || (Array.isArray(selected) && selected.length === 0);
  return Array.isArray(selected) ? selected.some(item => String(item) === String(value)) : String(selected) === String(value);
}
function chooseChip(filter: Filter, value: string | number): void {
  const filters = { ...query.value.filters };
  if (value === '') delete filters[filter.name];
  else filters[filter.name] = value;
  draftFilters.value = filters;
  changeQuery({ filters, page: 1 });
}
function applyFilters() {
  const filters = normalizeQueryBuilderFilters(props.resource.filters, draftFilters.value);
  draftFilters.value = filters;
  changeQuery({ filters, page: 1 });
  filtersOpen.value = false;
}
function resetFilters() {
  const filters = defaults();
  draftFilters.value = filters;
  changeQuery({ filters, page: 1 });
}
function columnStorageKey() {
  return `inlay:table:${props.resource.name}:columns`;
}
function queryStorageKey() {
  return `inlay:table:${props.resource.name}:query`;
}
function persistQueryState(value: QueryState) {
  const config = props.resource.queryPersistence;
  if (!config || !Object.values(config).some(Boolean)) return;
  const stored = {
    ...(config.search ? { search: value.search } : {}),
    ...(config.search ? { columnSearches: value.columnSearches ?? {} } : {}),
    ...(config.sort ? { sort: value.sort, direction: value.direction } : {}),
    ...(config.filters ? { filters: value.filters } : {}),
  };
  try {
    window.sessionStorage.setItem(queryStorageKey(), JSON.stringify(stored));
  } catch {
    // Query navigation still works when browser storage is unavailable.
  }
}
function restoredQueryState(): Partial<QueryState> | null {
  const config = props.resource.queryPersistence;
  if (!config || !Object.values(config).some(Boolean)) return null;
  try {
    const stored = JSON.parse(window.sessionStorage.getItem(queryStorageKey()) ?? "null");
    if (!stored || typeof stored !== "object" || Array.isArray(stored)) return null;
    const restored: Partial<QueryState> = {};
    if (config.search && typeof stored.search === "string") restored.search = stored.search.slice(0, 200);
    if (config.search && stored.columnSearches && typeof stored.columnSearches === "object" && !Array.isArray(stored.columnSearches)) {
      const names = new Set(props.resource.columns.filter((column) => column.individuallySearchable).map((column) => column.name));
      restored.columnSearches = Object.fromEntries(
        Object.entries(stored.columnSearches)
          .filter(([name, value]) => names.has(name) && typeof value === "string")
          .map(([name, value]) => [name, (value as string).slice(0, 500)]),
      );
    }
    if (config.sort && (stored.sort === null || props.resource.columns.some((column) => column.sortable && column.name === stored.sort))) {
      restored.sort = stored.sort;
      restored.direction = stored.direction === "desc" ? "desc" : "asc";
    }
    if (config.filters && stored.filters && typeof stored.filters === "object" && !Array.isArray(stored.filters)) {
      const names = new Set(props.resource.filters.map((filter) => filter.name));
      restored.filters = Object.fromEntries(Object.entries(stored.filters).filter(([name]) => names.has(name)));
    }
    return Object.keys(restored).length ? restored : null;
  } catch {
    return null;
  }
}
function initialColumnState(): { visibility: Record<string, boolean>; order: string[] } {
  const defaults = Object.fromEntries(
    props.resource.columns.map((column) => [column.name, column.visible]),
  );
  const defaultOrder = props.resource.columns.map((column) => column.name);
  if (!props.resource.columnManager?.persistInSession || typeof window === "undefined") return { visibility: defaults, order: defaultOrder };
  try {
    const stored = JSON.parse(window.sessionStorage.getItem(columnStorageKey()) ?? "null");
    if (!stored || typeof stored !== "object" || Array.isArray(stored)) return { visibility: defaults, order: defaultOrder };
    const storedVisibility = stored.visibility && typeof stored.visibility === "object" ? stored.visibility : stored;
    const visibility = Object.fromEntries(props.resource.columns.map((column) => [
      column.name,
      column.toggleable && typeof storedVisibility[column.name] === "boolean" ? storedVisibility[column.name] : column.visible,
    ]));
    const validStoredOrder = props.resource.columnManager.reorderable && Array.isArray(stored.order)
      ? stored.order.filter((name: unknown, index: number, values: unknown[]): name is string => typeof name === "string" && defaultOrder.includes(name) && values.indexOf(name) === index)
      : [];
    return { visibility, order: [...validStoredOrder, ...defaultOrder.filter((name) => !validStoredOrder.includes(name))] };
  } catch {
    return { visibility: defaults, order: defaultOrder };
  }
}
function commitColumns(visibility: Record<string, boolean>, order = draftColumnOrder.value) {
  columnVisibility.value = { ...visibility };
  columnOrder.value = [...order];
  if (props.resource.columnManager?.persistInSession) {
    try {
      window.sessionStorage.setItem(columnStorageKey(), JSON.stringify({ visibility, order }));
    } catch {
      // Storage can be unavailable; visibility still applies for this component instance.
    }
  }
}
function changeColumnVisibility(name: string, visible: boolean) {
  const next = { ...draftColumnVisibility.value, [name]: visible };
  draftColumnVisibility.value = next;
  if (!props.resource.columnManager?.deferred) commitColumns(next, draftColumnOrder.value);
}
function moveColumn(name: string, offset: -1 | 1) {
  const index = draftColumnOrder.value.indexOf(name);
  const target = index + offset;
  if (index < 0 || target < 0 || target >= draftColumnOrder.value.length) return;
  const next = [...draftColumnOrder.value];
  [next[index], next[target]] = [next[target], next[index]];
  draftColumnOrder.value = next;
  if (!props.resource.columnManager?.deferred) commitColumns(draftColumnVisibility.value, next);
}
function resetColumns() {
  const visibility = Object.fromEntries(props.resource.columns.map((column) => [column.name, column.visible]));
  const order = props.resource.columns.map((column) => column.name);
  draftColumnVisibility.value = visibility;
  draftColumnOrder.value = order;
  if (!props.resource.columnManager?.deferred) commitColumns(visibility, order);
}
function openColumns() {
  draftColumnVisibility.value = { ...columnVisibility.value };
  draftColumnOrder.value = [...columnOrder.value];
  columnsOpen.value = true;
}
function closeColumns() {
  draftColumnVisibility.value = { ...columnVisibility.value };
  draftColumnOrder.value = [...columnOrder.value];
  columnsOpen.value = false;
}
function toggleColumns() {
  if (columnsOpen.value) closeColumns();
  else openColumns();
}
function columnManagerGridClass(columns: number) {
  return columns === 2 ? "sm:grid-cols-2"
    : columns === 3 ? "sm:grid-cols-2 lg:grid-cols-3"
      : columns === 4 ? "sm:grid-cols-2 lg:grid-cols-4"
        : columns === 5 ? "sm:grid-cols-2 lg:grid-cols-5"
          : columns >= 6 ? "sm:grid-cols-2 lg:grid-cols-6"
            : "grid-cols-1";
}
function filterValue(event: Event, multiple = false) {
  const target = event.target as HTMLSelectElement;
  return multiple
    ? [...target.selectedOptions].map((option) => option.value)
    : target.value;
}
function execute(
  action: Action,
  rows: TableRow[],
  context?: ActionExecutionContext,
) {
  if (action.download) {
    exportError.value = null;
    exportQueued.value = null;
  }
  const resolved = context ?? {
    action: normalizeAction(action),
    input: {
      parameters: rows[0] ?? {},
      data: action.data ?? {},
      records: rows.map(keyFor),
    },
    url: interpolateActionUrl(action.url, rows[0] ?? {}),
  };
  const selection = action.bulk && allMatchingSelected.value
    ? { mode: 'query' as const, excluded: [...excluded.value], query: query.value }
    : undefined;
  const exportSelection = action.download && action.bulk
    ? selection ?? { mode: 'page' as const, records: rows.map(keyFor), query: query.value }
    : undefined;
  if (selection?.mode === 'query') emit("action", action, rows, selection);
  else emit("action", action, rows);
  if (props.actionExecutor) {
    const executionSelection = exportSelection ?? selection ?? (action.bulk
      ? { mode: 'page' as const, records: rows.map(keyFor) }
      : undefined);
    return props.actionExecutor(action, rows, resolved, executionSelection);
  }
  const url = safeUrl(resolved.url);
  if (!url) return;
  const data = action.bulk
    ? { ...resolved.input.data, ...(exportSelection ? { selection: exportSelection } : selection ? { selection } : { records: rows.map(keyFor) }) }
    : { ...resolved.input.data };
  if (action.lifecycle) {
    return executeActionEndpoint({
      ...resolved,
      input: { ...resolved.input, data },
    });
  }
  if (action.download && action.bulk) {
    return downloadAction({
      url,
      method: action.method,
      data,
      filename: action.filename,
    }).then((result) => {
      if (result?.queued === true) {
        exportQueued.value = typeof result.message === 'string' && result.message.trim() !== '' ? result.message : 'Export queued.';
      }
      return result;
    }).catch((error: unknown) => {
      exportError.value = error instanceof Error ? error.message : 'The export could not be downloaded.';
      throw error;
    });
  }
  return router.visit(url, { method: action.method, data: data as never });
}
function selectAll(checked: boolean) {
  allMatchingSelected.value = false;
  excluded.value = [];
  selected.value = checked ? selectAllKeys.value : [];
}
function selectRow(row: TableRow, checked: boolean) {
  const key = keyFor(row);
  if (allMatchingSelected.value) {
    excluded.value = checked ? excluded.value.filter(item => item !== key) : [...excluded.value, key];
    return;
  }
  if (checked && (!selectableKeys.value.includes(key) || (selectionMaximum.value !== null && selectedCount.value >= selectionMaximum.value))) return;
  selected.value = checked
    ? [...selected.value, key]
    : selected.value.filter((item) => item !== key);
}
function isActionGroup(definition: BulkActionDefinition): definition is Extract<BulkActionDefinition, { type: 'action-group' }> { return definition.type === 'action-group' && 'actions' in definition }
function selectionReason(action: Action) { const minimum = action.minimumSelection ?? 1; if (selectedCount.value < minimum) return `Select at least ${minimum} records.`; if (action.maximumSelection != null && selectedCount.value > action.maximumSelection) return `Select no more than ${action.maximumSelection} records.`; return null }
function deselectAfter(action: Action) { if (action.deselectRecordsAfterCompletion) { selected.value = []; allMatchingSelected.value = false; excluded.value = [] } }
function completeAction(action: Action) { deselectAfter(action); if (action.lifecycle) router.reload() }
function selectedRows() {
  return props.resource.rows.filter((row) =>
    isKeySelected(keyFor(row)),
  );
}
function alignmentClass(alignment: Column["alignment"]) {
  return alignment === "right"
    ? "text-right"
    : alignment === "center"
      ? "text-center"
      : "text-left";
}
function verticalAlignmentClass(alignment: Column["verticalAlignment"]) {
  return alignment === "start"
    ? "align-top"
    : alignment === "end"
      ? "align-bottom"
      : "align-middle";
}
function interpolate(template: string, row: TableRow) {
  return template.replace(/\{([^}]+)\}/g, (_, key) =>
    encodeURIComponent(String(getAtPath(row, key) ?? "")),
  );
}
function getAtPath(row: TableRow, path: string) {
  return path
    .split(".")
    .reduce<unknown>(
      (value, key) =>
        value && typeof value === "object"
          ? (value as TableRow)[key]
          : undefined,
      row,
    );
}
function flattenQuery(name: string, value: QueryState) {
  return {
    [`${name}_search`]: value.search || undefined,
    [`${name}_column_searches`]: value.columnSearches ?? {},
    [`${name}_sort`]: value.sort || undefined,
    [`${name}_direction`]: value.sort ? value.direction : undefined,
    [`${name}_page`]: value.page,
    [`${name}_per_page`]: value.perPage ?? undefined,
    [`${name}_cursor`]: value.cursor || undefined,
    [`${name}_filters`]: value.filters,
    [`${name}_loaded`]: value.loaded ? 1 : undefined,
    [`${name}_group`]: value.group || undefined,
    [`${name}_group_direction`]: value.group ? (value.groupDirection ?? "asc") : undefined,
    [`${name}_view`]: value.view || undefined,
  };
}
function toggleGroup(key: string) {
  collapsedGroups.value = collapsedGroups.value.includes(key) ? collapsedGroups.value.filter((item) => item !== key) : [...collapsedGroups.value, key];
}
function summaryValue(summary: SummaryResult) {
  const raw = summary.value;
  if (raw && typeof raw === "object" && "min" in raw && "max" in raw) return `${String((raw as { min: unknown }).min ?? "—")} – ${String((raw as { max: unknown }).max ?? "—")}`;
  if (raw == null) return "—";
  const numeric = typeof raw === "number" ? raw : Number(raw);
  let value = Number.isNaN(numeric) ? String(raw) : summary.currency
    ? new Intl.NumberFormat(undefined, { style: "currency", currency: summary.currency, minimumFractionDigits: summary.decimalPlaces ?? undefined, maximumFractionDigits: summary.decimalPlaces ?? undefined }).format(numeric)
    : new Intl.NumberFormat(undefined, { minimumFractionDigits: summary.decimalPlaces ?? undefined, maximumFractionDigits: summary.decimalPlaces ?? undefined }).format(numeric);
  if (!summary.currency) value = `${summary.prefix ?? ""}${value}${summary.suffix ?? ""}`;
  return value;
}
function summaryText(summaries: Record<string, SummaryResult[]>) {
  return Object.values(summaries).flat().map((summary) => `${summary.label}: ${summaryValue(summary)}`).join(" · ");
}
function responsiveColumnClass(column: Column) {
  const visible = column.visibleFrom ? ({ sm: "hidden sm:table-cell", md: "hidden md:table-cell", lg: "hidden lg:table-cell", xl: "hidden xl:table-cell", "2xl": "hidden 2xl:table-cell" } as const)[column.visibleFrom] : "";
  const hidden = column.hiddenFrom ? ({ sm: "sm:hidden", md: "md:hidden", lg: "lg:hidden", xl: "xl:hidden", "2xl": "2xl:hidden" } as const)[column.hiddenFrom] : "";
  return `${visible} ${hidden}`;
}
function columnDimensionStyle(column: Column): CSSProperties | undefined {
  if (!column.columnWidth && !column.minWidth && !column.maxWidth) return undefined;
  return { width: column.columnWidth ?? undefined, minWidth: column.minWidth ?? undefined, maxWidth: column.maxWidth ?? undefined };
}
function columnHeaderSegments(columns: Column[], groups: ColumnGroup[]): Array<{ group: ColumnGroup | null; columns: Column[] }> {
  const result: Array<{ group: ColumnGroup | null; columns: Column[] }> = [];
  for (const column of columns) {
    const group = groups.find((candidate) => candidate.columns.includes(column.name)) ?? null;
    const previous = result.at(-1);
    if (group && previous?.group === group) previous.columns.push(column);
    else result.push({ group, columns: [column] });
  }
  return result;
}
function contentGridClass(grid: Partial<Record<'default' | 'sm' | 'md' | 'lg' | 'xl' | '2xl', number>>) {
  const classes: Record<string, string[]> = {
    default: ["grid-cols-1", "grid-cols-2", "grid-cols-3", "grid-cols-4", "grid-cols-5", "grid-cols-6", "grid-cols-7", "grid-cols-8", "grid-cols-9", "grid-cols-10", "grid-cols-11", "grid-cols-12"],
    sm: ["sm:grid-cols-1", "sm:grid-cols-2", "sm:grid-cols-3", "sm:grid-cols-4", "sm:grid-cols-5", "sm:grid-cols-6", "sm:grid-cols-7", "sm:grid-cols-8", "sm:grid-cols-9", "sm:grid-cols-10", "sm:grid-cols-11", "sm:grid-cols-12"],
    md: ["md:grid-cols-1", "md:grid-cols-2", "md:grid-cols-3", "md:grid-cols-4", "md:grid-cols-5", "md:grid-cols-6", "md:grid-cols-7", "md:grid-cols-8", "md:grid-cols-9", "md:grid-cols-10", "md:grid-cols-11", "md:grid-cols-12"],
    lg: ["lg:grid-cols-1", "lg:grid-cols-2", "lg:grid-cols-3", "lg:grid-cols-4", "lg:grid-cols-5", "lg:grid-cols-6", "lg:grid-cols-7", "lg:grid-cols-8", "lg:grid-cols-9", "lg:grid-cols-10", "lg:grid-cols-11", "lg:grid-cols-12"],
    xl: ["xl:grid-cols-1", "xl:grid-cols-2", "xl:grid-cols-3", "xl:grid-cols-4", "xl:grid-cols-5", "xl:grid-cols-6", "xl:grid-cols-7", "xl:grid-cols-8", "xl:grid-cols-9", "xl:grid-cols-10", "xl:grid-cols-11", "xl:grid-cols-12"],
    "2xl": ["2xl:grid-cols-1", "2xl:grid-cols-2", "2xl:grid-cols-3", "2xl:grid-cols-4", "2xl:grid-cols-5", "2xl:grid-cols-6", "2xl:grid-cols-7", "2xl:grid-cols-8", "2xl:grid-cols-9", "2xl:grid-cols-10", "2xl:grid-cols-11", "2xl:grid-cols-12"],
  };
  return Object.entries(grid).map(([breakpoint, count]) => classes[breakpoint]?.[(count ?? 1) - 1]).filter(Boolean).join(" ");
}
function defaults() {
  return Object.fromEntries(
    props.resource.filters
      .filter((filter) => filter.default != null)
      .map((filter) => [filter.name, filter.default]),
  );
}
function booleanValue(value: unknown) {
  return value === true || value === 1 || value === "1";
}
function isActiveFilter(value: unknown) {
  if (isQueryGroup(value)) return queryRuleCount(value) > 0;
  return Array.isArray(value)
    ? value.length > 0
    : value !== "" && value !== null && value !== undefined && value !== false;
}
function filterDisplayValue(
  filter: TableResource["filters"][number],
  value: unknown,
) {
  if (filter.type === "query-builder") {
    const count = isQueryGroup(value) ? queryRuleCount(value) : 0;
    return `${count} ${count === 1 ? "condition" : "conditions"}`;
  }
  const values = Array.isArray(value) ? value : [value];
  if (filter.type === "ternary-filter")
    return String(value) === "1" || value === true
      ? filter.trueLabel
      : filter.falseLabel;
  return values
    .map(
      (item) =>
        filter.options?.find((option) => String(option.value) === String(item))
          ?.label ?? String(item),
    )
    .join(", ");
}
function isQueryGroup(value: unknown): value is { children: unknown[] } {
  return value !== null && typeof value === "object" && Array.isArray((value as { children?: unknown }).children);
}
function queryRuleCount(group: { children: unknown[] }): number {
  return group.children.reduce<number>((count, child) => count + (isQueryGroup(child) ? queryRuleCount(child) : 1), 0);
}
function normalizeQueryBuilderFilters(definitions: Filter[], filters: Record<string, unknown>): Record<string, unknown> {
  const normalized = { ...filters };
  for (const filter of definitions) {
    if (filter.type === "query-builder" && Object.prototype.hasOwnProperty.call(normalized, filter.name))
      normalized[filter.name] = normalizeQueryGroup(normalized[filter.name], filter.constraints ?? []);
  }
  return normalized;
}
function normalizeQueryGroup(value: unknown, constraints: QueryConstraint[]): QueryGroup {
  const source = isQueryGroup(value) ? value : { children: [] };
  const children = source.children.flatMap<QueryRule | QueryGroup>((child) => {
    if (isQueryGroup(child)) return [normalizeQueryGroup(child, constraints)];
    if (!child || typeof child !== "object" || Array.isArray(child)) return [];
    const rule = child as Partial<QueryRule>;
    const constraint = constraints.find(item => item.name === rule.constraint || item.relationship === rule.constraint) ?? constraints[0];
    if (!constraint?.operators.length) return [];
    return [{
      constraint: constraint.name,
      operator: normalizeQueryOperator(rule.operator, constraint),
      ...("value" in rule ? { value: rule.value } : {}),
    }];
  });
  return {
    boolean: (value as Partial<QueryGroup> | null)?.boolean === "or" ? "or" : "and",
    children,
  };
}
function normalizeQueryOperator(operator: unknown, constraint: QueryConstraint): string {
  if (typeof operator === "string" && constraint.operators.includes(operator)) return operator;
  const aliases: Record<string, string> = { exists: "has", not_exists: "does_not_have", is: "equals", is_not: "not_equals" };
  const candidate = typeof operator === "string" ? aliases[operator] : undefined;
  return candidate && constraint.operators.includes(candidate) ? candidate : constraint.operators[0] ?? "";
}
function filterRenderer(filter: Filter): Component | undefined {
  return (
    props.renderers?.filter?.[filter.type] ??
    (props.registries?.filter
      ? toRaw(props.registries.filter).get(filter.type)
      : undefined)
  );
}
function rawComponent(component: Component | undefined): Component | undefined {
  return component && typeof component === "object"
    ? toRaw(component)
    : component;
}
</script>

<template>
  <section
    :aria-busy="isLoading"
    :aria-label="resource.name"
    :class="`antialiased isolate min-w-0 max-w-full overflow-x-hidden ${classNames?.root ?? ''}`"
    :data-contract="resource.contract"
    data-slot="root"
    :style="themeStyle"
  >
    <p aria-live="polite" class="sr-only" data-slot="reorder-status">{{ reorderAnnouncement }}</p>
    <div v-if="resource.heading || resource.description" class="mb-4" data-slot="table-heading">
      <h2 v-if="resource.heading" class="text-lg font-semibold text-(--inlay-text)">{{ resource.heading }}</h2>
      <p v-if="resource.description" class="mt-1 text-base text-(--inlay-muted) sm:text-sm">{{ resource.description }}</p>
    </div>
    <div
      :class="`flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between ${classNames?.toolbar ?? ''}`"
      data-slot="toolbar"
    >
      <div class="flex min-w-0 flex-1 flex-wrap items-center gap-3">
        <label
          v-if="resource.searchable ?? resource.columns.some((column) => column.searchable)"
          class="w-full max-w-[250px] flex-none"
        >
          <span class="sr-only">Search</span>
          <input
            aria-label="Search"
            :class="`${controlClass} w-full`"
            data-slot="search"
            :placeholder="resource.searchPlaceholder"
            type="search"
            :value="searchDraft"
            @blur="resource.searchOnBlur && commitSearch(searchDraft)"
            @input="onSearchInput(($event.target as HTMLInputElement).value)"
            @keydown.enter.prevent="commitSearch(searchDraft)"
          />
        </label>
        <div v-if="filtersLayout === 'chips' && chipFilters.length" aria-label="Table filters" class="flex flex-wrap gap-1.5" data-slot="filter-chips" role="group"><template v-for="filter in chipFilters" :key="filter.name"><button :aria-pressed="chipSelected(filter, '')" :class="['inline-flex min-h-(--inlay-control-height) items-center justify-center gap-1.5 rounded-full border px-2.5 py-1 text-xs text-(--inlay-muted) transition', chipSelected(filter, '') ? 'border-(--inlay-accent)/30 bg-(--inlay-accent)/10 font-semibold text-(--inlay-accent)' : 'border-(--inlay-border) bg-(--inlay-surface) hover:border-(--inlay-control-border) hover:text-(--inlay-text)']" type="button" @click="chooseChip(filter, '')">All</button><button v-for="option in filter.options ?? []" :key="`${filter.name}:${option.value}`" :aria-pressed="chipSelected(filter, option.value)" :class="['inline-flex min-h-(--inlay-control-height) items-center justify-center gap-1.5 rounded-full border px-2.5 py-1 text-xs text-(--inlay-muted) transition', chipSelected(filter, option.value) ? 'border-(--inlay-accent)/30 bg-(--inlay-accent)/10 font-semibold text-(--inlay-accent)' : 'border-(--inlay-border) bg-(--inlay-surface) hover:border-(--inlay-control-border) hover:text-(--inlay-text)']" type="button" @click="chooseChip(filter, option.value)">{{ option.label }}</button></template></div>
        <div v-if="resource.views?.length" class="min-w-0 flex-[1_1_12rem]">
          <span class="sr-only">Saved view</span>
          <InlaySelect
            aria-label="Saved view"
            class-name="w-full"
            :model-value="query.view ?? ''"
            placeholder="All records"
            :options="[{ value: '', label: 'All records' }, ...resource.views.map(view => ({ value: view.name, label: view.label }))]"
            @update:model-value="value => chooseView(Array.isArray(value) ? value[0] ?? '' : value)"
          />
        </div>
        <button
          v-if="resource.filters.length && filtersLayout !== 'chips' && (filtersLayout === 'dropdown' || filtersLayout === 'above-content-collapsible' || filtersLayout === 'modal')"
          :aria-controls="`${resource.name}-filters`"
          :aria-expanded="filtersOpen"
          :class="`${triggerButtonClass(resource.triggers?.filters)} shrink-0 ${classNames?.filtersTrigger ?? ''}`"
          data-slot="filters-trigger"
          type="button"
          @click="filtersOpen = !filtersOpen"
        >
          <NamedIcon v-if="resource.triggers?.filters?.icon" fallback="◆" :name="resource.triggers.filters.icon" :registries="registries" :renderers="renderers" />{{ resource.triggers?.filters?.label ?? 'Filters' }}<span
            v-if="activeFilters.length"
            :aria-label="`${activeFilters.length} active filters`"
            class="rounded-full bg-(--inlay-accent) px-1.5 py-0.5 text-xs text-(--inlay-accent-foreground)"
          >{{ activeFilters.length }}</span>
        </button>
        <div v-if="resource.grouping && !resource.grouping.settingsHidden && resource.grouping.groups.length" class="min-w-0 flex-[1_1_12rem]">
          <span class="sr-only">Group records</span>
          <InlaySelect aria-label="Group records" class-name="w-full" placeholder="No grouping" :model-value="query.group ?? ''" :options="[{ value: '', label: 'No grouping' }, ...resource.grouping.groups.map(group => ({ value: group.name, label: group.label }))]" @update:model-value="value => changeQuery({ group: (Array.isArray(value) ? value[0] ?? '' : value) || null, page: 1 })" />
        </div>
        <button v-if="resource.grouping?.active && !resource.grouping.directionSettingHidden" :class="`${secondaryButton} shrink-0`" type="button" @click="changeQuery({ groupDirection: query.groupDirection === 'desc' ? 'asc' : 'desc', page: 1 })">
          Group {{ query.groupDirection === 'desc' ? 'descending' : 'ascending' }}
        </button>
      </div>
      <div :class="`flex w-full flex-wrap items-center gap-2 lg:w-auto lg:justify-end ${classNames?.headerActions ?? ''}`" data-slot="header-actions">
        <template v-if="resource.reordering?.enabled">
          <template v-if="reordering">
            <button :class="primaryButton" :disabled="reorderSubmitting" type="button" @click="saveReordering">{{ reorderSubmitting ? 'Saving…' : 'Save order' }}</button>
            <button :class="secondaryButton" :disabled="reorderSubmitting" type="button" @click="cancelReordering">Cancel</button>
          </template>
          <button v-else :class="`${triggerButtonClass(resource.triggers?.reordering)} ${classNames?.headerActions ?? ''}`" :disabled="Boolean(resource.grouping?.active) || resource.rows.length < 2" :title="resource.grouping?.active ? 'Remove grouping before reordering records.' : undefined" type="button" @click="orderedRows = [...resource.rows]; reorderError = null; reorderAnnouncement = 'Drag a row handle or use its move up and move down buttons.'; reordering = true"><NamedIcon v-if="resource.triggers?.reordering?.icon" fallback="◆" :name="resource.triggers.reordering.icon" :registries="registries" :renderers="renderers" />{{ resource.triggers?.reordering?.label ?? 'Reorder records' }}</button>
        </template>
        <button
          v-if="resource.columnManager && (resource.columnManager.reorderable || resource.columns.some((column) => column.toggleable))"
          :aria-controls="`${resource.name}-columns`"
          :aria-expanded="columnsOpen"
          :class="triggerButtonClass(resource.triggers?.columnManager)"
          data-slot="columns-trigger"
          type="button"
          @click="toggleColumns"
        ><NamedIcon v-if="resource.triggers?.columnManager?.icon" fallback="◆" :name="resource.triggers.columnManager.icon" :registries="registries" :renderers="renderers" />{{ resource.triggers?.columnManager?.label ?? 'Columns' }}</button>
        <div v-if="resource.viewManagement" class="flex items-center gap-2" data-slot="view-actions">
          <button :class="secondaryButton" type="button" @click="viewNameDraft = activePersonalView?.name ?? ''; viewLabelDraft = activePersonalView?.label ?? ''; viewDescriptionDraft = activePersonalView?.description ?? ''; viewError = null; viewEditorOpen = true">{{ activePersonalView ? 'Edit view' : 'Save view' }}</button>
          <button v-if="activePersonalView" :class="secondaryButton" type="button" @click="deletePersonalView">Delete view</button>
        </div>
        <TableAction
          v-for="action in resource.headerActions"
          :key="action.instanceKey ?? action.name"
          :action="action"
          :executor="(context) => execute(action, [], context)"
          :record-keys="[]"
          :registries="registries"
          :renderers="renderers"
          :rows="[]"
          @success="completeAction(action)"
        />
      </div>
      <form v-if="viewEditorOpen" class="mt-3 flex flex-wrap items-end gap-2 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface-muted) p-3" data-slot="view-editor" @submit.prevent="savePersonalView">
        <label class="min-w-40 flex-1 text-sm font-medium text-(--inlay-text)"><span class="mb-1 block">View key</span><input v-model="viewNameDraft" aria-label="View key" pattern="[a-z][a-z0-9_-]{0,63}" :class="controlClass + ' w-full'" required /></label>
        <label class="min-w-40 flex-1 text-sm font-medium text-(--inlay-text)"><span class="mb-1 block">Label</span><input v-model="viewLabelDraft" aria-label="View label" :class="controlClass + ' w-full'" required /></label>
        <label class="min-w-52 flex-1 text-sm font-medium text-(--inlay-text)"><span class="mb-1 block">Description</span><input v-model="viewDescriptionDraft" aria-label="View description" :class="controlClass + ' w-full'" /></label>
        <button :class="primaryButton" :disabled="viewSaving || viewNameDraft.trim() === '' || viewLabelDraft.trim() === ''" type="submit">{{ viewSaving ? 'Saving…' : 'Save' }}</button>
        <button :class="secondaryButton" :disabled="viewSaving" type="button" @click="viewEditorOpen = false">Cancel</button>
        <p v-if="viewError" class="basis-full text-sm text-(--inlay-danger)" role="alert">{{ viewError }}</p>
      </form>
      <div v-if="reorderError" class="mt-3 flex items-start justify-between gap-3 rounded-(--inlay-radius) border border-(--inlay-danger)/30 bg-(--inlay-danger-surface) px-3 py-2.5 text-sm text-(--inlay-danger)" data-slot="reorder-error" role="alert">
        <span>{{ reorderError }}</span>
        <button aria-label="Dismiss reorder error" class="shrink-0 rounded px-1 hover:bg-(--inlay-danger)/10" type="button" @click="reorderError = null">×</button>
      </div>
      <div v-if="exportError" class="mt-3 flex items-start justify-between gap-3 rounded-(--inlay-radius) border border-(--inlay-danger)/30 bg-(--inlay-danger-surface) px-3 py-2.5 text-sm text-(--inlay-danger)" data-slot="export-error" role="alert">
        <span>{{ exportError }}</span>
        <button aria-label="Dismiss export error" class="shrink-0 rounded px-1 hover:bg-(--inlay-danger)/10" type="button" @click="exportError = null">×</button>
      </div>
      <div v-if="exportQueued" class="mt-3 flex items-start justify-between gap-3 rounded-(--inlay-radius) border border-(--inlay-accent)/25 bg-(--inlay-accent)/8 px-3 py-2.5 text-sm text-(--inlay-text)" data-slot="export-queued" role="status">
        <span>{{ exportQueued }}</span>
        <button aria-label="Dismiss export status" class="shrink-0 rounded px-1 hover:bg-(--inlay-hover)" type="button" @click="exportQueued = null">×</button>
      </div>
    </div>
    <div
      v-if="columnsOpen"
      :class="resource.columnManager?.layout === 'modal' ? 'fixed inset-0 z-50 grid place-items-center bg-(--inlay-scrim) p-4 backdrop-blur-[1px]' : ''"
      :data-slot="resource.columnManager?.layout === 'modal' ? 'column-manager-overlay' : undefined"
      @click.self="resource.columnManager?.layout === 'modal' && closeColumns()"
    >
    <div
      :id="`${resource.name}-columns`"
      :aria-label="resource.columnManager?.layout === 'modal' ? undefined : 'Table columns'"
      :aria-labelledby="resource.columnManager?.layout === 'modal' ? `${resource.name}-columns-heading` : undefined"
      :aria-modal="resource.columnManager?.layout === 'modal' ? true : undefined"
      :class="resource.columnManager?.layout === 'modal'
        ? 'max-h-[min(42rem,calc(100dvh-2rem))] w-full max-w-3xl overflow-y-auto rounded-(--inlay-radius) bg-(--inlay-surface) p-5 shadow-2xl ring-1 ring-(--inlay-border)'
        : 'mt-4 rounded-(--inlay-radius) bg-(--inlay-surface) p-4 ring-1 ring-(--inlay-border)'"
      data-slot="column-manager"
      :role="resource.columnManager?.layout === 'modal' ? 'dialog' : 'region'"
      @keydown.esc="resource.columnManager?.layout === 'modal' && closeColumns()"
    >
      <div class="mb-4 flex items-center justify-between gap-3">
        <h3 :id="`${resource.name}-columns-heading`" class="text-base font-semibold text-(--inlay-text)">Manage columns</h3>
        <div class="flex items-center gap-2">
          <button v-if="(resource.columnManager?.resetActionPosition ?? 'header') === 'header'" :class="secondaryButton" type="button" @click="resetColumns">Reset columns</button>
          <button v-if="resource.columnManager?.layout === 'modal'" aria-label="Close column manager" autofocus :class="`${smallButton} min-w-(--inlay-icon-button-size) px-2`" type="button" @click="closeColumns">×</button>
        </div>
      </div>
      <div :class="`grid gap-2 ${columnManagerGridClass(resource.columnManager?.columns ?? 1)}`">
        <div v-for="(name, index) in draftColumnOrder" :key="name" class="flex items-center gap-2 text-sm">
          <template v-if="columnsByName.get(name) && (columnsByName.get(name)!.toggleable || resource.columnManager?.reorderable)">
            <label v-if="columnsByName.get(name)!.toggleable" class="flex min-w-0 flex-1 items-center gap-2">
              <input :checked="draftColumnVisibility[name] ?? columnsByName.get(name)!.visible" class="size-4 accent-(--inlay-accent)" type="checkbox" @change="changeColumnVisibility(name, ($event.target as HTMLInputElement).checked)" />
              <span class="truncate">{{ columnsByName.get(name)!.label }}</span>
            </label>
            <span v-else class="min-w-0 flex-1 truncate">{{ columnsByName.get(name)!.label }}</span>
            <span v-if="resource.columnManager?.reorderable" class="flex gap-1">
              <button :aria-label="`Move ${columnsByName.get(name)!.label} up`" :disabled="index === 0" type="button" @click="moveColumn(name, -1)">↑</button>
              <button :aria-label="`Move ${columnsByName.get(name)!.label} down`" :disabled="index === draftColumnOrder.length - 1" type="button" @click="moveColumn(name, 1)">↓</button>
            </span>
          </template>
        </div>
      </div>
      <div v-if="resource.columnManager?.deferred || resource.columnManager?.resetActionPosition === 'footer'" class="mt-4 flex items-center justify-between gap-3 border-t border-(--inlay-border) pt-4">
        <button v-if="resource.columnManager?.resetActionPosition === 'footer'" :class="secondaryButton" type="button" @click="resetColumns">Reset columns</button>
        <span v-else />
        <button v-if="resource.columnManager?.deferred" :class="primaryButton" type="button" @click="commitColumns(draftColumnVisibility, draftColumnOrder); columnsOpen = false">Apply columns</button>
      </div>
    </div>
    </div>
    <div
      v-if="filtersLayout === 'modal' && filtersPanelVisible()"
      aria-hidden="true"
      class="fixed inset-0 z-40 bg-(--inlay-overlay) backdrop-blur-[2px]"
      data-slot="filters-modal-backdrop"
      @click.self="filtersOpen = false"
    />
    <div
      v-if="filtersPanelVisible() && filtersLayout !== 'below-content'"
      :id="`${resource.name}-filters`"
      aria-label="Table filters"
      :aria-modal="filtersLayout === 'modal' ? 'true' : undefined"
      :class="`${filtersLayout === 'modal' ? 'fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-h-[calc(100dvh-2rem)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto shadow-2xl' : 'mt-3'} rounded-(--inlay-radius) bg-(--inlay-surface-muted) p-4 ring-1 ring-(--inlay-border) ${panelWidthClass(resource.filtersFormWidth)} ${classNames?.filtersPanel ?? ''}`.trim()"
      data-slot="filters-panel"
      :role="filtersLayout === 'modal' ? 'dialog' : 'region'"
      :style="filtersPanelStyle()"
    >
      <div v-if="filtersResetActionPosition === 'header' || filtersLayout === 'modal'" :class="`mb-4 flex ${filtersLayout === 'modal' ? 'items-center justify-between' : 'justify-end'}`" data-slot="filter-header-actions">
        <h2 v-if="filtersLayout === 'modal'" class="text-base font-semibold text-(--inlay-text)">Filters</h2>
        <div class="flex items-center gap-2">
          <button
            v-if="filtersResetActionPosition === 'header'"
            :class="`${secondaryButton} ${classNames?.resetButton ?? ''}`"
            data-slot="filters-reset"
            type="button"
            @click="resetFilters"
          >Reset</button>
          <button
            v-if="filtersLayout === 'modal'"
            aria-label="Close filters"
            :class="secondaryButton"
            data-slot="filters-close"
            type="button"
            @click="filtersOpen = false"
          >Close</button>
        </div>
      </div>
      <div
        class="grid grid-cols-1 gap-4 sm:[grid-template-columns:repeat(var(--inlay-filter-columns),minmax(0,1fr))]"
        data-slot="filters"
        :style="{ '--inlay-filter-columns': filtersFormColumns }"
      >
        <div
          v-for="filter in resource.filters"
          :key="filter.name"
          class="min-w-0 sm:[grid-column:span_var(--inlay-filter-span)]"
          data-slot="filter-cell"
          :style="{ '--inlay-filter-span': Math.min(filter.columnSpan ?? 1, filtersFormColumns) }"
          ><component
            v-if="filterRenderer(filter)"
            :is="rawComponent(filterRenderer(filter))"
            :filter="filter"
            :value="draftFilters[filter.name]"
            :class-names="classNames"
            :on-change="
              (value: unknown) => changeFilter(filter.name, value)
            " />
          <QueryBuilderControl v-else-if="filter.type === 'query-builder'" :filter="filter" :value="draftFilters[filter.name]" @change="changeFilter(filter.name, $event)" />
          <fieldset
            v-else-if="filter.type === 'schema-filter'"
            :class="`grid gap-1.5 ${classNames?.filterGroup ?? ''}`"
            :data-filter="filter.name"
            data-slot="filter-group"
          >
            <legend class="text-sm font-medium text-(--inlay-text)" data-slot="filter-label">{{ filter.label }}</legend>
            <SchemaRenderer
              :columns="filter.formColumns ?? 1"
              :errors="{}"
              :live-blur="() => undefined"
              :live-change="() => undefined"
              :schema="(filter.schema ?? []) as never"
              :update="(path: string, next: unknown) => changeFilter(filter.name, { ...schemaFilterValues(filter.name), [path]: next })"
              :values="schemaFilterValues(filter.name)"
            />
          </fieldset>
          <label
            v-else-if="filter.type === 'boolean-filter'"
            :class="`flex items-center gap-2 text-base sm:text-sm ${classNames?.filterGroup ?? ''}`"
            :data-filter="filter.name"
            data-slot="filter-group"
            ><input
              :checked="booleanValue(draftFilters[filter.name])"
              :class="`size-5 accent-(--inlay-accent) sm:size-4 ${classNames?.filterControl ?? ''}`"
              data-slot="filter-control"
              type="checkbox"
              @change="
                changeFilter(
                  filter.name,
                  ($event.target as HTMLInputElement).checked,
                )
              "
            />{{ filter.label }}</label
          ><!-- A searchable filter loads its options from the same authorized query the table uses. -->
          <div
            v-else-if="filter.type === 'select-filter' && filter.remoteOptions?.endpoint"
            :class="`grid min-w-0 gap-1.5 text-sm font-medium text-(--inlay-text) ${classNames?.filterGroup ?? ''}`"
            :data-filter="filter.name"
            data-slot="filter-group"
          >
            <span data-slot="filter-label">{{ filter.label }}</span>
            <input
              :aria-label="`Search ${filter.label}`"
              :class="controlClass"
              data-slot="filter-search"
              type="search"
              :value="filterSearches[filter.name] ?? ''"
              @input="searchFilterOptions(filter, ($event.target as HTMLInputElement).value)"
            >
            <select
              :aria-label="filter.label"
              :class="`${controlClass} ${filter.multiple ? 'min-h-28' : ''} font-normal ${classNames?.filterControl ?? ''}`"
              data-slot="filter-control"
              :multiple="filter.multiple"
              :value="draftFilters[filter.name] ?? (filter.multiple ? [] : '')"
              @change="changeFilter(filter.name, filter.multiple ? Array.from(($event.target as HTMLSelectElement).selectedOptions).map(option => option.value) : ($event.target as HTMLSelectElement).value)"
            >
              <option v-if="!filter.multiple" value="">All</option>
              <option v-for="option in (remoteFilterOptions[filter.name] ?? filter.options ?? [])" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div          ><div
            v-else-if="
              filter.type === 'select-filter' ||
              filter.type === 'ternary-filter'
            "
            :class="`grid min-w-0 gap-1.5 text-sm font-medium text-(--inlay-text) ${classNames?.filterGroup ?? ''}`"
            :data-filter="filter.name"
            data-slot="filter-group"
            ><span data-slot="filter-label">{{ filter.label }}</span
            ><InlaySelect
              v-if="!filter.multiple"
              data-slot="filter-control"
              :aria-label="filter.label"
              :button-class-name="`font-normal ${classNames?.filterControl ?? ''}`"
              class-name="w-full"
              :model-value="String(draftFilters[filter.name] ?? '')"
              :options="filter.type === 'ternary-filter' ? [{ value: '', label: 'All' }, { value: '1', label: filter.trueLabel ?? 'Yes' }, { value: '0', label: filter.falseLabel ?? 'No' }] : [{ value: '', label: 'All' }, ...(filter.options ?? [])]"
              @update:model-value="value => changeFilter(filter.name, Array.isArray(value) ? value[0] ?? '' : value)"
            /><select
              v-else
              :class="`${controlClass} min-h-28 font-normal ${classNames?.filterControl ?? ''}`"
              data-slot="filter-control"
              multiple
              :value="draftFilters[filter.name] ?? []"
              @change="changeFilter(filter.name, filterValue($event, true))"
            ><template v-if="filter.type === 'ternary-filter'"><option value="1">{{ filter.trueLabel }}</option><option value="0">{{ filter.falseLabel }}</option></template><template v-else><option v-for="option in filter.options" :key="option.value" :value="option.value">{{ option.label }}</option></template></select></div
          ><label
            v-else
            :class="`grid min-w-0 gap-1.5 text-sm font-medium text-(--inlay-text) ${classNames?.filterGroup ?? ''}`"
            :data-filter="filter.name"
            data-slot="filter-group"
            ><span data-slot="filter-label">{{ filter.label }}</span
            ><input
              :class="`${controlClass} font-normal ${classNames?.filterControl ?? ''}`"
              data-slot="filter-control"
              :type="
                filter.type === 'date-filter'
                  ? 'date'
                  : filter.type === 'numeric-filter'
                    ? 'number'
                    : 'text'
              "
              :value="String(draftFilters[filter.name] ?? '')"
              @input="
                changeFilter(
                  filter.name,
                  ($event.target as HTMLInputElement).value,
                )
              " /></label
        ></div>
      </div>
      <div
        :class="`mt-4 flex flex-wrap justify-end gap-2 ${classNames?.filterActions ?? ''}`"
        data-slot="filter-actions"
      >
        <button
          v-if="filtersResetActionPosition === 'footer'"
          :class="`${secondaryButton} ${classNames?.resetButton ?? ''}`"
          data-slot="filters-reset"
          type="button"
          @click="resetFilters"
        >
          Reset</button
        ><button
          v-if="resource.deferFilters"
          :class="`${primaryButton} ${classNames?.applyButton ?? ''}`"
          data-slot="filters-apply"
          type="button"
          @click="applyFilters"
        >
          Apply filters
        </button>
      </div>
    </div>
    <div
      v-if="resource.aggregates?.length"
      :class="`mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 ${classNames?.aggregates ?? ''}`"
      data-slot="aggregates"
    >
      <div
        v-for="aggregate in resource.aggregates"
        :key="aggregate.name"
        class="rounded-(--inlay-radius) bg-(--inlay-surface) p-3 ring-1 ring-(--inlay-border)"
        data-slot="aggregate"
      >
        <p class="text-sm text-(--inlay-muted)">{{ aggregate.label }}</p>
        <p class="text-lg font-semibold">{{ summaryValue(aggregate) }}</p>
      </div>
    </div>
    <div
      v-if="indicators.length"
      :class="`mt-3 flex flex-wrap gap-2 ${classNames?.filterIndicators ?? ''}`"
      data-slot="filter-indicators"
    >
      <span
        v-for="indicator in indicators"
        :key="indicator.field"
        :class="`inline-flex items-center gap-1 rounded-full bg-(--inlay-surface-muted) py-1 pl-2.5 pr-1 text-sm text-(--inlay-muted) ring-1 ring-(--inlay-border) ${classNames?.filterIndicator ?? ''}`"
        data-slot="filter-indicator"
      >
        {{ indicator.label }}
        <button
          :aria-label="`Remove ${indicator.label}`"
          class="grid size-5 place-items-center rounded-full hover:bg-(--inlay-hover) hover:text-(--inlay-foreground)"
          type="button"
          @click="removeIndicator(indicator)"
        >
          <span aria-hidden="true">×</span>
        </button>
      </span>
    </div>
    <p v-if="resource.selectable" :id="`${resource.name}-selection-status`" aria-live="polite" class="sr-only">{{ selectedCount }} records selected<span v-if="selectionMaximum !== null">; maximum {{ selectionMaximum }}</span>.</p>
    <div v-if="!allMatchingSelected && allSelectableSelected && resource.selection?.selectAllMode === 'query' && (resource.selection.total ?? 0) > selectableKeys.length" class="mt-3 flex items-center justify-center gap-2 rounded-(--inlay-radius) bg-(--inlay-surface-muted) px-3 py-2 text-sm"><span>All {{ selectableKeys.length }} records on this page are selected.</span><button class="font-semibold text-(--inlay-accent) underline underline-offset-2 disabled:opacity-50" :disabled="selectionMaximum !== null && (resource.selection?.total ?? 0) > selectionMaximum" type="button" @click="allMatchingSelected = true; excluded = []; selected = []">Select all {{ resource.selection.total }} matching records</button></div>
    <div
      v-if="selectedCount > 0 && resource.bulkActions.length"
      :class="`mt-4 flex flex-wrap items-center gap-3 rounded-(--inlay-radius) bg-(--inlay-surface-muted) p-3 ring-1 ring-(--inlay-border) ${classNames?.bulkActions ?? ''}`"
      data-slot="bulk-actions"
    >
      <p class="text-base sm:text-sm">{{ selectedCount }} selected</p>
      <template v-for="definition in resource.bulkActions" :key="definition.instanceKey ?? definition.name">
        <TableBulkActionTree
          v-if="isActionGroup(definition)"
          :complete="completeAction"
          :definition="definition"
          :execute="(action, context) => execute(action, selectedRows(), context)"
          :record-keys="selectedRows().map(keyFor)"
          :registries="registries"
          :renderers="renderers"
          :rows="selectedRows()"
          :selection-reason="selectionReason"
        />
        <TableAction v-else :action="definition" :disabled="selectionReason(definition) !== null" :disabled-reason="selectionReason(definition)" :executor="(context) => execute(definition, selectedRows(), context)" :record-keys="selectedRows().map(keyFor)" :registries="registries" :renderers="renderers" :rows="selectedRows()" @success="completeAction(definition)" />
      </template>
      <button :class="secondaryButton" type="button" @click="selected = []; allMatchingSelected = false; excluded = []">Clear selection</button>
    </div>
    <div
      :class="`-mx-4 -my-2 mt-4 overflow-x-auto whitespace-nowrap sm:-mx-6 lg:-mx-8 ${classNames?.tableShell ?? ''}`"
      data-slot="table-scroll"
    >
      <div
        class="inline-block min-w-full px-4 py-2 align-middle sm:px-6 lg:px-8"
      >
        <table :class="`${gridLayout || customLayout ? 'block' : stackedLayout ? 'block sm:table' : `${fixedTableLayout ? 'table-fixed' : 'table-auto'} w-max min-w-full`} border-separate border-spacing-0 ${classNames?.table ?? ''}`" data-slot="table">
          <thead :class="`${gridLayout || customLayout ? 'hidden' : stackedLayout ? 'hidden bg-(--inlay-surface-subtle) sm:table-header-group' : 'bg-(--inlay-surface-subtle)'} ${classNames?.head ?? ''}`" data-slot="table-head">
            <tr>
              <th v-if="resource.actions?.length && actionsPosition === 'before-cells'" class="w-32 min-w-32 max-w-48 whitespace-nowrap border-b border-(--inlay-border) bg-(--inlay-surface-subtle) h-(--inlay-table-row-height) px-(--inlay-space-table-x) align-middle text-right text-[11px] font-semibold text-(--inlay-muted) lg:sticky lg:right-0 lg:z-20" :rowspan="hasColumnGroups ? 2 : undefined">
                <span class="sr-only">Actions</span>
              </th>
              <th v-if="reordering" class="w-32 border-b border-(--inlay-border) py-2.5" :rowspan="hasColumnGroups ? 2 : undefined"><span class="sr-only">Reorder controls</span></th>
              <th v-if="resource.selectable" class="w-12 border-b border-(--inlay-border) py-2.5 pr-3" :rowspan="hasColumnGroups ? 2 : undefined">
                <input
                  aria-label="Select all rows"
                  :aria-describedby="`${resource.name}-selection-status`"
                  :checked="allSelectableSelected"
                  class="size-5 accent-(--inlay-accent) sm:size-4"
                  :disabled="selectableKeys.length === 0"
                  :indeterminate="someSelectableSelected && !allSelectableSelected"
                  type="checkbox"
                  @change="
                    selectAll(($event.target as HTMLInputElement).checked)
                  "
                />
              </th>
              <th v-if="resource.actions?.length && actionsPosition === 'before-columns'" class="w-32 min-w-32 max-w-48 whitespace-nowrap border-b border-(--inlay-border) bg-(--inlay-surface-subtle) h-(--inlay-table-row-height) px-(--inlay-space-table-x) align-middle text-right text-[11px] font-semibold text-(--inlay-muted) lg:sticky lg:right-0 lg:z-20" :rowspan="hasColumnGroups ? 2 : undefined">
                <span class="sr-only">Actions</span>
              </th>
              <template v-if="hasColumnGroups">
                <template v-for="(segment, index) in headerSegments" :key="`${segment.group?.label ?? segment.columns[0].name}-${index}`">
                  <th v-if="segment.group" :class="`${segment.group.wrapHeader ? 'whitespace-normal' : 'whitespace-nowrap'} border-b border-(--inlay-border) bg-(--inlay-surface-subtle) h-(--inlay-table-row-height) px-(--inlay-space-table-x) align-middle text-[11px] font-semibold text-(--inlay-muted) ${alignmentClass(segment.group.alignment)}`" :colspan="segment.columns.length" scope="colgroup" :title="segment.group.tooltip ?? undefined">{{ segment.group.label }}</th>
                  <TableColumnHeader v-else :column="segment.columns[0]" :query="query" :row-span="2" :search-debounce="resource.searchDebounce" :search-on-blur="resource.searchOnBlur" @search="searchColumn" @sort="sortColumn" />
                </template>
              </template>
              <TableColumnHeader v-else v-for="column in columns" :key="column.name" :column="column" :query="query" :search-debounce="resource.searchDebounce" :search-on-blur="resource.searchOnBlur" @search="searchColumn" @sort="sortColumn" />
              <th v-if="resource.actions?.length && actionsPosition === 'after-columns'" class="w-32 min-w-32 max-w-48 whitespace-nowrap border-b border-(--inlay-border) bg-(--inlay-surface-subtle) h-(--inlay-table-row-height) px-(--inlay-space-table-x) align-middle text-right text-[11px] font-semibold text-(--inlay-muted) lg:sticky lg:right-0 lg:z-20" :rowspan="hasColumnGroups ? 2 : undefined">
                <span class="sr-only">Actions</span>
              </th>
            </tr>
            <tr v-if="hasColumnGroups">
              <template v-for="(segment, index) in headerSegments" :key="`members-${segment.group?.label ?? index}-${index}`">
                <template v-if="segment.group">
                  <TableColumnHeader v-for="column in segment.columns" :key="column.name" :column="column" :query="query" :search-debounce="resource.searchDebounce" :search-on-blur="resource.searchOnBlur" @search="searchColumn" @sort="sortColumn" />
                </template>
              </template>
            </tr>
          </thead>
            <tbody :class="gridLayout ? `grid gap-4 p-4 ${contentGridClass(resource.layout?.contentGrid ?? {})}` : customLayout ? 'grid gap-3 p-3' : stackedLayout ? 'block p-3 sm:table-row-group sm:p-0' : undefined">
              <template v-for="item in displayRows" :key="item.key">
              <tr v-if="item.kind === 'group'" class="bg-(--inlay-surface-muted)" data-slot="group-header">
                <th class="whitespace-normal h-(--inlay-table-row-height) px-(--inlay-space-table-x) align-middle text-left" :colspan="columns.length + (reordering ? 1 : 0) + (resource.selectable ? 1 : 0) + (resource.actions?.length ? 1 : 0)" scope="rowgroup">
                  <button class="flex w-full items-start justify-between gap-4 text-left" :disabled="!resource.grouping?.active?.collapsible" type="button" @click="toggleGroup(item.bucket.key)">
                    <span><span class="font-semibold text-(--inlay-text)">{{ item.bucket.title }}</span><span v-if="item.bucket.description" class="mt-0.5 block text-sm font-normal text-(--inlay-muted)">{{ item.bucket.description }}</span></span>
                    <span class="text-sm font-normal text-(--inlay-muted)">{{ summaryText(item.bucket.summaries) }} {{ resource.grouping?.active?.collapsible ? (collapsedGroups.includes(item.bucket.key) ? '▾' : '▴') : '' }}</span>
                  </button>
                </th>
              </tr>
              <tr
                v-else
                :key="item.key"
                :class="`group transition-colors hover:bg-(--inlay-surface-subtle) focus-within:bg-(--inlay-surface-subtle) ${resource.striped && rowIndexFor(item.row) % 2 === 1 ? 'bg-(--inlay-surface-muted)' : ''} ${resource.rowClasses?.[String(keyFor(item.row))] ?? ''} ${reordering && String(dragTargetKey) === String(keyFor(item.row)) ? 'bg-(--inlay-surface-subtle) outline-2 -outline-offset-2 outline-(--inlay-accent)' : ''} ${gridLayout || customLayout ? 'block h-auto rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-3 shadow-xs' : stackedLayout ? 'mb-3 block h-auto rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-2 shadow-xs sm:mb-0 sm:table-row sm:rounded-none sm:border-0 sm:p-0 sm:shadow-none' : ''} ${recordUrl(item.row) ? 'cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--inlay-focus-ring-color)' : ''} ${classNames?.row ?? ''}`"
                :data-drag-target="reordering && String(dragTargetKey) === String(keyFor(item.row)) ? 'true' : undefined"
                :data-row-key="keyFor(item.row)"
                data-slot="table-row"
                :role="recordUrl(item.row) ? 'link' : undefined"
                :tabindex="recordUrl(item.row) ? 0 : undefined"
                @click="visitRecord(item.row, $event)"
                @dragover.prevent="draggedRecordKey !== null && (dragTargetKey = keyFor(item.row))"
                @drop.prevent="dropRecord(item.row)"
                @keydown.enter.prevent="visitRecord(item.row, $event)"
                @keydown.space.prevent="visitRecord(item.row, $event)"
              >
              <TableRowActionsCell v-if="resource.actions?.length && actionsPosition === 'before-cells'" :actions="resource.actions ?? []" :class-names="classNames" :card-layout="cardLayout" :complete="completeAction" :execute="execute" :record-key="keyFor(item.row)" :registries="registries" :renderers="renderers" :row="item.row" :visible="actionVisible" />
              <td v-if="reordering" class="w-32 py-2 pr-2"><div class="flex justify-center gap-1"><button :aria-label="`Drag row ${keyFor(item.row)}`" class="min-h-8 cursor-grab rounded-(--inlay-radius) px-2 ring-1 ring-(--inlay-border) hover:bg-(--inlay-hover) active:cursor-grabbing" draggable="true" type="button" @click.stop @dragend="stopDragging" @dragstart.stop="startDragging(item.row, $event)">⋮⋮</button><button :aria-label="`Move row ${keyFor(item.row)} up`" class="min-h-8 rounded-(--inlay-radius) px-2 ring-1 ring-(--inlay-border) hover:bg-(--inlay-hover) disabled:opacity-40" :disabled="orderedRows[0] === item.row" type="button" @click="moveRecord(item.row, -1)">↑</button><button :aria-label="`Move row ${keyFor(item.row)} down`" class="min-h-8 rounded-(--inlay-radius) px-2 ring-1 ring-(--inlay-border) hover:bg-(--inlay-hover) disabled:opacity-40" :disabled="orderedRows[orderedRows.length - 1] === item.row" type="button" @click="moveRecord(item.row, 1)">↓</button></div></td>
              <td v-if="resource.selectable" :class="`${cardLayout ? 'block w-full px-2 py-2 sm:w-auto' : 'w-12 h-(--inlay-table-row-height) px-(--inlay-space-table-x) align-middle'} ${classNames?.cell ?? ''}`">
                <input
                  :aria-describedby="`${resource.name}-selection-status`"
                  :aria-label="`Select row ${keyFor(item.row)}`"
                  :checked="isKeySelected(keyFor(item.row))"
                  class="size-5 accent-(--inlay-accent) sm:size-4"
                  :disabled="!selectableKeys.includes(keyFor(item.row)) || (!isKeySelected(keyFor(item.row)) && selectionMaximum !== null && selectedCount >= selectionMaximum)"
                  :title="!selectableKeys.includes(keyFor(item.row)) ? 'This record cannot be selected.' : !isKeySelected(keyFor(item.row)) && selectionMaximum !== null && selectedCount >= selectionMaximum ? `You can select at most ${selectionMaximum} records.` : undefined"
                  type="checkbox"
                  @change="
                    selectRow(item.row, ($event.target as HTMLInputElement).checked)
                  "
                />
              </td>
              <TableRowActionsCell v-if="resource.actions?.length && actionsPosition === 'before-columns'" :actions="resource.actions ?? []" :class-names="classNames" :card-layout="cardLayout" :complete="completeAction" :execute="execute" :record-key="keyFor(item.row)" :registries="registries" :renderers="renderers" :row="item.row" :visible="actionVisible" />
              <td v-if="customLayout" class="block p-2" :colspan="columns.length">
                <div class="grid gap-3" data-slot="column-layout">
                  <ColumnLayoutRenderer v-for="(component, index) in resource.columnLayout ?? []" :key="index" :component="component" :registries="registries" :renderers="renderers" :row="item.row" @change="(column, value) => handleCellChange(item.row, column, value)" />
                </div>
              </td>
              <template v-else>
              <td
                v-for="column in columns"
                :key="column.name"
                v-bind="cellAttributesFor(item.row, column)"
                :data-no-record-click="column.disabledClick ? 'true' : undefined"
                data-slot="table-cell"
                :class="`${cardLayout ? `grid grid-cols-[minmax(7rem,0.4fr)_1fr] items-center gap-3 px-2 py-2 ${stackedLayout && !gridLayout ? 'sm:table-cell sm:h-(--inlay-table-row-height) sm:px-(--inlay-space-table-x) sm:align-middle' : ''}` : 'min-w-0 overflow-hidden h-(--inlay-table-row-height) px-(--inlay-space-table-x)'} text-xs text-(--inlay-muted-strong) ${column.numeric || column.money ? 'tabular-nums' : ''} ${alignmentClass(column.alignment)} ${verticalAlignmentClass(column.verticalAlignment)} ${responsiveColumnClass(column)} ${column.wrap ? 'whitespace-normal' : ''} ${classNames?.cell ?? ''}`"
                :style="columnDimensionStyle(column)"
              >
                <span v-if="cardLayout" :class="`text-left text-xs font-medium text-(--inlay-muted) ${stackedLayout && !gridLayout ? 'sm:hidden' : ''}`">{{ column.label }}</span>
                <span v-bind="contentAttributesFor(item.row, column)" :class="`${column.grow === false ? 'grow-0' : 'min-w-0 grow'} grid gap-1`"><span
                  v-if="column.actions?.length"
                  class="relative grid gap-1"
                  data-slot="column-action-group"
                ><button
                  :aria-expanded="openColumnActions === cellKey(item.row, column)"
                  aria-haspopup="menu"
                  :aria-label="`${column.label} actions`"
                  class="w-full cursor-pointer rounded-sm text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)"
                  data-slot="column-action"
                  type="button"
                  @click.stop="toggleColumnActions(item.row, column)"
                ><TableCell
                  :column="column"
                  :disabled="updatingCells.includes(cellKey(item.row, column))"
                  :error="cellErrors[cellKey(item.row, column)]"
                  :registries="registries"
                  :renderers="renderers"
                  :row="item.row"
                  @change="handleCellChange(item.row, column, $event)"
                /></button><span
                  v-if="openColumnActions === cellKey(item.row, column)"
                  class="absolute left-0 top-full z-30 mt-1 grid min-w-40 gap-0.5 rounded-(--inlay-radius-md) border border-(--inlay-border) bg-(--inlay-surface) p-1.5 shadow-(--inlay-shadow-md)"
                  data-slot="column-actions"
                  role="menu"
                ><button
                  v-for="action in column.actions"
                  :key="action.instanceKey ?? action.name"
                  class="flex min-h-9 items-center rounded-(--inlay-radius-sm) px-2.5 py-2 text-left text-sm text-(--inlay-fg-strong) transition-colors hover:bg-(--inlay-surface-subtle) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-focus-ring-color)"
                  role="menuitem"
                  type="button"
                  @click.stop="openColumnActions = null; execute(action, [item.row])"
                >{{ action.label }}</button></span></span><button
                  v-else-if="column.action"
                  class="w-full cursor-pointer rounded-sm text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)"
                  data-slot="column-action"
                  type="button"
                  @click.stop="execute(column.action, [item.row])"
                ><TableCell
                  :column="column"
                  :disabled="updatingCells.includes(cellKey(item.row, column))"
                  :error="cellErrors[cellKey(item.row, column)]"
                  :registries="registries"
                  :renderers="renderers"
                  :row="item.row"
                  @change="handleCellChange(item.row, column, $event)"
                /></button><TableCell
                  v-else
                  :column="column"
                  :disabled="updatingCells.includes(cellKey(item.row, column))"
                  :error="cellErrors[cellKey(item.row, column)]"
                  :registries="registries"
                  :renderers="renderers"
                  :row="item.row"
                  @change="handleCellChange(item.row, column, $event)"
                /><span v-if="cellErrors[cellKey(item.row, column)]" class="text-xs text-(--inlay-danger)" role="alert">{{ cellErrors[cellKey(item.row, column)] }}</span></span>
              </td>
              </template>
              <TableRowActionsCell v-if="resource.actions?.length && actionsPosition === 'after-columns'" :actions="resource.actions ?? []" :class-names="classNames" :card-layout="cardLayout" :complete="completeAction" :execute="execute" :record-key="keyFor(item.row)" :registries="registries" :renderers="renderers" :row="item.row" :visible="actionVisible" />
            </tr>
            </template>
          </tbody>
          <tfoot v-if="hasSummaryRows" class="bg-(--inlay-surface-muted)" data-slot="summaries">
            <tr>
              <td v-if="reordering" />
              <td v-if="resource.selectable" />
              <td v-for="column in columns" :key="column.name" :class="`min-w-0 whitespace-normal border-t border-(--inlay-border) h-(--inlay-table-row-height) px-(--inlay-space-table-x) align-middle text-sm ${column.numeric || column.money ? 'tabular-nums' : ''} ${alignmentClass(column.alignment)} ${classNames?.cell ?? ''}`">
                <template v-if="summaryQueryVisible">
                  <div v-for="(summary, index) in summaryQuery[column.name] ?? []" :key="`${summary.type}-${index}`" class="grid min-w-0 gap-0.5">
                    <span class="font-medium text-(--inlay-text)">{{ summary.label }}: {{ summaryValue(summary) }}</span>
                    <span v-if="pageSummary(column.name, summary) && summaryPageVisible" class="text-xs text-(--inlay-muted)">Page: {{ summaryValue(pageSummary(column.name, summary)!) }}</span>
                  </div>
                </template>
                <template v-else-if="summaryPageVisible">
                  <div v-for="(summary, index) in summaryPage[column.name] ?? []" :key="`${summary.type}-${index}`" class="grid min-w-0 gap-0.5">
                    <span class="font-medium text-(--inlay-text)">Page: {{ summaryValue(summary) }}</span>
                  </div>
                </template>
              </td>
              <td v-if="resource.actions?.length" />
            </tr>
          </tfoot>
        </table>
        <div v-if="!isLoading && !resource.rows.length" class="py-12 text-center" data-slot="empty-state">
          <h3 class="font-semibold">{{ resource.emptyState.heading }}</h3>
          <p
            v-if="resource.emptyState.description"
            class="mt-1 text-base text-(--inlay-muted) sm:text-sm"
          >
            {{ resource.emptyState.description }}
          </p>

          <div v-if="resource.emptyState.actions?.length" class="mt-4 flex flex-wrap justify-center gap-2" data-slot="empty-state-actions">
            <TableAction
              v-for="action in resource.emptyState.actions"
              :key="action.instanceKey ?? action.name"
              :action="action"
              :executor="(context) => execute(action, [], context)"
              :record-keys="[]"
              :registries="registries"
              :renderers="renderers"
              :rows="[]"
              @success="completeAction(action)"
            />
          </div>
        </div>
        <p
          v-if="isLoading"
          class="py-12 text-center text-base text-(--inlay-muted) sm:text-sm"
          role="status"
        >
          Loading…
        </p>
      </div>
    </div>
        <div
      v-if="filtersLayout === 'below-content' && resource.filters.length"
      :id="`${resource.name}-filters`"
      :class="`mt-3 rounded-(--inlay-radius) bg-(--inlay-surface-muted) p-4 ring-1 ring-(--inlay-border) ${panelWidthClass(resource.filtersFormWidth)} ${classNames?.filtersPanel ?? ''}`.trim()"
      data-slot="filters-panel"
      :style="filtersPanelStyle()"
    >
      <div v-if="filtersResetActionPosition === 'header'" class="mb-4 flex justify-end" data-slot="filter-header-actions">
        <button
          :class="`${secondaryButton} ${classNames?.resetButton ?? ''}`"
          data-slot="filters-reset"
          type="button"
          @click="resetFilters"
        >Reset</button>
      </div>
      <div
        class="grid grid-cols-1 gap-4 sm:[grid-template-columns:repeat(var(--inlay-filter-columns),minmax(0,1fr))]"
        data-slot="filters"
        :style="{ '--inlay-filter-columns': filtersFormColumns }"
      >
        <div
          v-for="filter in resource.filters"
          :key="filter.name"
          class="min-w-0 sm:[grid-column:span_var(--inlay-filter-span)]"
          data-slot="filter-cell"
          :style="{ '--inlay-filter-span': Math.min(filter.columnSpan ?? 1, filtersFormColumns) }"
          ><component
            v-if="filterRenderer(filter)"
            :is="rawComponent(filterRenderer(filter))"
            :filter="filter"
            :value="draftFilters[filter.name]"
            :class-names="classNames"
            :on-change="
              (value: unknown) => changeFilter(filter.name, value)
            " />
          <QueryBuilderControl v-else-if="filter.type === 'query-builder'" :filter="filter" :value="draftFilters[filter.name]" @change="changeFilter(filter.name, $event)" />
          <fieldset
            v-else-if="filter.type === 'schema-filter'"
            :class="`grid gap-1.5 ${classNames?.filterGroup ?? ''}`"
            :data-filter="filter.name"
            data-slot="filter-group"
          >
            <legend class="text-sm font-medium text-(--inlay-text)" data-slot="filter-label">{{ filter.label }}</legend>
            <SchemaRenderer
              :columns="filter.formColumns ?? 1"
              :errors="{}"
              :live-blur="() => undefined"
              :live-change="() => undefined"
              :schema="(filter.schema ?? []) as never"
              :update="(path: string, next: unknown) => changeFilter(filter.name, { ...schemaFilterValues(filter.name), [path]: next })"
              :values="schemaFilterValues(filter.name)"
            />
          </fieldset>
          <label
            v-else-if="filter.type === 'boolean-filter'"
            :class="`flex items-center gap-2 text-base sm:text-sm ${classNames?.filterGroup ?? ''}`"
            :data-filter="filter.name"
            data-slot="filter-group"
            ><input
              :checked="booleanValue(draftFilters[filter.name])"
              :class="`size-5 accent-(--inlay-accent) sm:size-4 ${classNames?.filterControl ?? ''}`"
              data-slot="filter-control"
              type="checkbox"
              @change="
                changeFilter(
                  filter.name,
                  ($event.target as HTMLInputElement).checked,
                )
              "
            />{{ filter.label }}</label
          ><!-- A searchable filter loads its options from the same authorized query the table uses. -->
          <div
            v-else-if="filter.type === 'select-filter' && filter.remoteOptions?.endpoint"
            :class="`grid min-w-0 gap-1.5 text-sm font-medium text-(--inlay-text) ${classNames?.filterGroup ?? ''}`"
            :data-filter="filter.name"
            data-slot="filter-group"
          >
            <span data-slot="filter-label">{{ filter.label }}</span>
            <input
              :aria-label="`Search ${filter.label}`"
              :class="controlClass"
              data-slot="filter-search"
              type="search"
              :value="filterSearches[filter.name] ?? ''"
              @input="searchFilterOptions(filter, ($event.target as HTMLInputElement).value)"
            >
            <select
              :aria-label="filter.label"
              :class="`${controlClass} ${filter.multiple ? 'min-h-28' : ''} font-normal ${classNames?.filterControl ?? ''}`"
              data-slot="filter-control"
              :multiple="filter.multiple"
              :value="draftFilters[filter.name] ?? (filter.multiple ? [] : '')"
              @change="changeFilter(filter.name, filter.multiple ? Array.from(($event.target as HTMLSelectElement).selectedOptions).map(option => option.value) : ($event.target as HTMLSelectElement).value)"
            >
              <option v-if="!filter.multiple" value="">All</option>
              <option v-for="option in (remoteFilterOptions[filter.name] ?? filter.options ?? [])" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div          ><div
            v-else-if="
              filter.type === 'select-filter' ||
              filter.type === 'ternary-filter'
            "
            :class="`grid min-w-0 gap-1.5 text-sm font-medium text-(--inlay-text) ${classNames?.filterGroup ?? ''}`"
            :data-filter="filter.name"
            data-slot="filter-group"
            ><span data-slot="filter-label">{{ filter.label }}</span
            ><InlaySelect
              v-if="!filter.multiple"
              data-slot="filter-control"
              :aria-label="filter.label"
              :button-class-name="`font-normal ${classNames?.filterControl ?? ''}`"
              class-name="w-full"
              :model-value="String(draftFilters[filter.name] ?? '')"
              :options="filter.type === 'ternary-filter' ? [{ value: '', label: 'All' }, { value: '1', label: filter.trueLabel ?? 'Yes' }, { value: '0', label: filter.falseLabel ?? 'No' }] : [{ value: '', label: 'All' }, ...(filter.options ?? [])]"
              @update:model-value="value => changeFilter(filter.name, Array.isArray(value) ? value[0] ?? '' : value)"
            /><select
              v-else
              :class="`${controlClass} min-h-28 font-normal ${classNames?.filterControl ?? ''}`"
              data-slot="filter-control"
              multiple
              :value="draftFilters[filter.name] ?? []"
              @change="changeFilter(filter.name, filterValue($event, true))"
            ><template v-if="filter.type === 'ternary-filter'"><option value="1">{{ filter.trueLabel }}</option><option value="0">{{ filter.falseLabel }}</option></template><template v-else><option v-for="option in filter.options" :key="option.value" :value="option.value">{{ option.label }}</option></template></select></div
          ><label
            v-else
            :class="`grid min-w-0 gap-1.5 text-sm font-medium text-(--inlay-text) ${classNames?.filterGroup ?? ''}`"
            :data-filter="filter.name"
            data-slot="filter-group"
            ><span data-slot="filter-label">{{ filter.label }}</span
            ><input
              :class="`${controlClass} font-normal ${classNames?.filterControl ?? ''}`"
              data-slot="filter-control"
              :type="
                filter.type === 'date-filter'
                  ? 'date'
                  : filter.type === 'numeric-filter'
                    ? 'number'
                    : 'text'
              "
              :value="String(draftFilters[filter.name] ?? '')"
              @input="
                changeFilter(
                  filter.name,
                  ($event.target as HTMLInputElement).value,
                )
              " /></label
        ></div>
      </div>
      <div
        :class="`mt-4 flex flex-wrap justify-end gap-2 ${classNames?.filterActions ?? ''}`"
        data-slot="filter-actions"
      >
        <button
          v-if="filtersResetActionPosition === 'footer'"
          :class="`${secondaryButton} ${classNames?.resetButton ?? ''}`"
          data-slot="filters-reset"
          type="button"
          @click="resetFilters"
        >
          Reset</button
        ><button
          v-if="resource.deferFilters"
          :class="`${primaryButton} ${classNames?.applyButton ?? ''}`"
          data-slot="filters-apply"
          type="button"
          @click="applyFilters"
        >
          Apply filters
        </button>
      </div>
    </div>
    <nav
      v-if="resource.pagination && (!reordering || resource.reordering?.paginatedWhileReordering)"
      aria-label="Pagination"
      :class="`mt-4 flex items-center justify-between ${classNames?.pagination ?? ''}`"
      data-slot="pagination"
    >
      <p class="text-sm tabular-nums text-(--inlay-muted)">{{ paginationSummary() }}</p>
      <div
        v-if="perPageOptions().length > 0"
        class="flex items-center gap-2 text-sm text-(--inlay-muted)"
        data-slot="pagination-per-page"
      >
        <span>Per page</span>
        <InlaySelect aria-label="Per page" button-class-name="text-sm" class-name="min-w-20" :model-value="String(resource.pagination.perPage ?? resource.pagination.defaultPerPage ?? '')" :options="perPageOptions().map(option => ({ value: String(option), label: option === 'all' ? 'All' : String(option) }))" @update:model-value="value => changePerPage(Array.isArray(value) ? value[0] ?? '' : value)" />
      </div>
      <button
        v-if="paginationMode() !== 'none'"
        :class="`${secondaryButton} font-medium`"
        :disabled="paginationMode() === 'cursor' ? !resource.pagination.previousCursor : currentPage() <= 1"
        type="button"
        @click="previousPage"
      >
        Previous
      </button>
      <div v-if="paginationMode() === 'length-aware'" class="hidden gap-1 sm:flex" data-slot="pagination-pages">
        <button v-if="resource.extremePaginationLinks" aria-label="First page" :class="`${smallButton} min-w-8 px-2 disabled:opacity-40`" data-slot="pagination-first" :disabled="currentPage() <= 1" type="button" @click="changeQuery({ page: 1, cursor: null })">«</button>
        <button
          v-for="page in lastPage()"
          :key="page"
          :aria-current="
            page === currentPage() ? 'page' : undefined
          "
          :class="`${smallButton} min-w-9 px-2 aria-current:bg-(--inlay-accent) aria-current:text-(--inlay-accent-foreground)`"
          type="button"
          @click="changeQuery({ page, cursor: null })"
        >
          {{ page }}
        </button>
        <button v-if="resource.extremePaginationLinks" aria-label="Last page" :class="`${smallButton} min-w-8 px-2 disabled:opacity-40`" data-slot="pagination-last" :disabled="currentPage() >= lastPage()" type="button" @click="changeQuery({ page: lastPage(), cursor: null })">»</button>
      </div>
      <button
        :class="`${secondaryButton} font-medium`"
        v-if="paginationMode() !== 'none'"
        :disabled="paginationMode() === 'cursor' ? !resource.pagination.nextCursor : paginationMode() === 'simple' ? !resource.pagination.hasMorePages : currentPage() >= lastPage()"
        type="button"
        @click="nextPage"
      >
        Next
      </button>
    </nav>
  </section>
</template>
