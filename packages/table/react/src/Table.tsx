import { router } from "@inertiajs/react";
import { isSafeUrl } from "@inlayphp/core";
import { customThemeVariables, recipeVariables, themeToken } from "@inlayphp/theme";
import type { ThemeSource } from "@inlayphp/theme";
import { ActionDialog, useActionRuntime } from "@inlayphp/actions-react";
import { ActionForm, SchemaRenderer } from "@inlayphp/forms-react";
import { downloadAction, executeActionEndpoint, interpolateActionUrl, matchesActionKeyBinding } from "@inlayphp/actions";
import { Select, buttonBaseClass, buttonDangerClass, buttonPrimaryClass, buttonSecondaryClass, controlClass, resolveIcon } from "@inlayphp/ui-react";
import { Fragment, useEffect, useRef, useState } from "react";
import type { ComponentType, CSSProperties, ReactNode } from "react";
import type {
  Action,
  BulkActionDefinition,
  Column,
  CellPresentation,
  ColumnGroup,
  ColumnLayout,
  Filter,
  FilterIndicator,
  QueryConstraint,
  QueryGroup,
  QueryRule,
  QueryState,
  SummaryResult,
  TableResource,
  TableRow,
  ColumnUpdater,
} from "./types";
import { QueryBuilderControl } from './QueryBuilderControl';
import { ColumnUpdateError, updateColumnOnServer } from './columnUpdate';

export type ColumnRendererProps = {
  column: Column;
  row: TableRow;
  rawValue: unknown;
  value: unknown;
  onChange: (value: unknown) => void;
  disabled?: boolean;
  error?: string | null;
};

export type FilterRendererProps = {
  filter: Filter;
  value: unknown;
  onChange: (value: unknown) => void;
  classNames?: TableClassNames;
};

export type ActionRendererProps = {
  action: Action;
  rows: TableRow[];
  onExecute: () => void;
  disabled?: boolean;
  disabledReason?: string | null;
};

export type IconRendererProps = {
  name: string;
};

export type ColumnRenderer = ComponentType<ColumnRendererProps>;
export type FilterRenderer = ComponentType<FilterRendererProps>;
export type ActionRenderer = ComponentType<ActionRendererProps>;
export type IconRenderer = ComponentType<IconRendererProps>;

export type RendererLookup<TRenderer> = {
  get: (type: string) => TRenderer | undefined;
};

export type TableRendererRegistries = {
  column?: RendererLookup<ColumnRenderer>;
  filter?: RendererLookup<FilterRenderer>;
  action?: RendererLookup<ActionRenderer>;
  icon?: RendererLookup<IconRenderer>;
};

export type TableRenderers = {
  column?: Record<string, ColumnRenderer>;
  filter?: Record<string, FilterRenderer>;
  action?: Record<string, ActionRenderer>;
  icon?: Record<string, IconRenderer>;
};

export type TableProps = {
  resource: TableResource;
  loading?: boolean;
  className?: string;
  classNames?: TableClassNames;
  theme?: TableTheme;
  renderers?: TableRenderers;
  registries?: TableRendererRegistries;
  onQueryChange?: (query: QueryState) => void;
  onAction?: (action: Action, records: TableRow[], selection?: BulkSelectionState) => void;
  onCellChange?: (row: TableRow, column: Column, value: unknown) => void;
  onCellUpdateError?: (error: Error, row: TableRow, column: Column) => void;
  columnUpdater?: ColumnUpdater;
  onReorder?: (records: Array<string | number>, startPosition: number) => void | Promise<void>;
  onRefresh?: () => void;
};

export type BulkSelectionState =
  | { mode: 'page'; records: Array<string | number>; query?: QueryState }
  | { mode: 'query'; excluded: Array<string | number>; query: QueryState };

export type TableTheme = ThemeSource;

export type TableClassNames = Partial<
  Record<
    | "root"
    | "toolbar"
    | "filtersTrigger"
    | "filtersPanel"
    | "filterGroup"
    | "filterControl"
    | "aggregates"
    | "filterIndicators"
    | "filterIndicator"
    | "filterActions"
    | "resetButton"
    | "applyButton"
    | "headerActions"
    | "bulkActions"
    | "tableShell"
    | "table"
    | "head"
    | "row"
    | "cell"
    | "rowActions"
    | "pagination",
    string
  >
>;

const buttonBase = `${buttonBaseClass} gap-1.5 font-semibold`;
const secondaryButton = `${buttonSecondaryClass} gap-1.5 font-semibold`;
const primaryButton = `${buttonPrimaryClass} gap-1.5 font-semibold`;
const dangerButton = `${buttonDangerClass} gap-1.5 font-semibold`;
const actionButtonBase = `${buttonBaseClass} gap-1.5 font-semibold`;
const actionColors: Record<string, string> = {
  default: "border-(--inlay-control-border) bg-(--inlay-surface) text-(--inlay-text) hover:bg-(--inlay-hover)",
  primary: "border-(--inlay-accent) bg-(--inlay-accent) text-(--inlay-accent-foreground) hover:brightness-95",
  danger: "border-(--inlay-danger)/25 bg-(--inlay-danger-surface) text-(--inlay-danger) hover:border-(--inlay-danger)/45",
  success: "border-(--inlay-success)/25 bg-(--inlay-success-surface) text-(--inlay-success) hover:brightness-95",
  warning: "border-(--inlay-warning)/25 bg-(--inlay-warning-surface) text-(--inlay-warning) hover:brightness-95",
  info: "border-(--inlay-info)/25 bg-(--inlay-info-surface) text-(--inlay-info) hover:brightness-95",
  gray: "border-(--inlay-control-border) bg-(--inlay-surface-muted) text-(--inlay-muted) hover:text-(--inlay-text)",
};
const actionOutlines: Record<string, string> = {
  ...actionColors,
  primary: "border-(--inlay-accent) bg-transparent text-(--inlay-accent) hover:bg-(--inlay-hover)",
  danger: "border-(--inlay-danger)/35 bg-transparent text-(--inlay-danger) hover:bg-(--inlay-danger-surface)",
};
const actionLinks: Record<string, string> = {
  default: "border-transparent bg-transparent text-(--inlay-text) hover:text-(--inlay-accent)",
  primary: "border-transparent bg-transparent text-(--inlay-accent) hover:brightness-90",
  danger: "border-transparent bg-transparent text-(--inlay-danger) hover:brightness-90",
  success: "border-transparent bg-transparent text-(--inlay-success) hover:bg-(--inlay-success-surface)",
  warning: "border-transparent bg-transparent text-(--inlay-warning) hover:bg-(--inlay-warning-surface)",
  info: "border-transparent bg-transparent text-(--inlay-info) hover:bg-(--inlay-info-surface)",
  gray: "border-transparent bg-transparent text-(--inlay-muted) hover:text-(--inlay-text)",
};
const actionBadges: Record<string, string> = {
  default: "border-(--inlay-control-border) bg-(--inlay-surface-muted) text-(--inlay-text)",
  primary: "border-(--inlay-accent)/20 bg-(--inlay-accent)/10 text-(--inlay-accent)",
  danger: "border-(--inlay-danger)/20 bg-(--inlay-danger-surface) text-(--inlay-danger)",
  success: "border-(--inlay-success)/20 bg-(--inlay-success-surface) text-(--inlay-success)",
  warning: "border-(--inlay-warning)/20 bg-(--inlay-warning-surface) text-(--inlay-warning)",
  info: "border-(--inlay-info)/20 bg-(--inlay-info-surface) text-(--inlay-info)",
  gray: "border-(--inlay-control-border) bg-(--inlay-surface-muted) text-(--inlay-muted)",
};
const actionGroupPlacements: Record<string, string> = {
  "top-start": "bottom-full left-0 mb-2",
  top: "bottom-full left-1/2 mb-2 -translate-x-1/2",
  "top-end": "bottom-full right-0 mb-2",
  "bottom-start": "left-0 top-full mt-2",
  bottom: "left-1/2 top-full mt-2 -translate-x-1/2",
  "bottom-end": "right-0 top-full mt-2",
  "left-start": "right-full top-0 mr-2",
  left: "right-full top-1/2 mr-2 -translate-y-1/2",
  "left-end": "bottom-0 right-full mr-2",
  "right-start": "left-full top-0 ml-2",
  right: "left-full top-1/2 ml-2 -translate-y-1/2",
  "right-end": "bottom-0 left-full ml-2",
};
const actionGroupWidths: Record<string, string> = {
  xs: "w-40",
  sm: "w-48",
  md: "w-56",
  lg: "w-64",
  xl: "w-72",
  "2xl": "w-80",
  "3xl": "w-96",
  "4xl": "w-[28rem]",
  "5xl": "w-[32rem]",
  "6xl": "w-[36rem]",
  "7xl": "w-[40rem]",
};

export function Table({
  resource,
  loading = false,
  className,
  classNames,
  theme,
  renderers,
  registries,
  onQueryChange,
  onAction,
  onCellChange,
  onCellUpdateError,
  columnUpdater = updateColumnOnServer,
  onReorder,
  onRefresh,
}: TableProps) {
  const [query, setQuery] = useState<QueryState>(
    resource.query ?? {
      search: "",
      columnSearches: {},
      sort: null,
      direction: "asc",
      page: resource.pagination?.currentPage ?? 1,
      cursor: null,
      filters: defaults(resource),
      loaded: !resource.deferLoading,
      view: resource.activeView ?? null,
    },
  );
  const [draftFilters, setDraftFilters] = useState<Record<string, unknown>>(
    query.filters,
  );
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [columnsOpen, setColumnsOpen] = useState(false);
  const [collapsedGroups, setCollapsedGroups] = useState<string[]>(
    () => resource.grouping?.collapsedByDefault ? (resource.grouping.buckets.map((bucket) => bucket.key)) : [],
  );
  const initialColumns = initialColumnState(resource);
  const [columnVisibility, setColumnVisibility] = useState<Record<string, boolean>>(
    () => initialColumns.visibility,
  );
  const [draftColumnVisibility, setDraftColumnVisibility] = useState<Record<string, boolean>>(
    () => initialColumns.visibility,
  );
  const [columnOrder, setColumnOrder] = useState<string[]>(() => initialColumns.order);
  const [draftColumnOrder, setDraftColumnOrder] = useState<string[]>(() => initialColumns.order);
  const [selected, setSelected] = useState<Array<string | number>>([]);
  const [allMatchingSelected, setAllMatchingSelected] = useState(false);
  const [excluded, setExcluded] = useState<Array<string | number>>([]);
  const [reordering, setReordering] = useState(false);
  const [reorderSubmitting, setReorderSubmitting] = useState(false);
  const [reorderError, setReorderError] = useState<string | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);
  const [exportQueued, setExportQueued] = useState<string | null>(null);
  const [orderedRows, setOrderedRows] = useState<TableRow[]>(() => resource.rows);
  const [updatingCells, setUpdatingCells] = useState<string[]>([]);
  const [cellErrors, setCellErrors] = useState<Record<string, string>>({});
  const [draggedRecordKey, setDraggedRecordKey] = useState<string | number | null>(null);
  const [dragTargetKey, setDragTargetKey] = useState<string | number | null>(null);
  const [reorderAnnouncement, setReorderAnnouncement] = useState("");
  const [viewEditorOpen, setViewEditorOpen] = useState(false);
  const [viewNameDraft, setViewNameDraft] = useState("");
  const [viewLabelDraft, setViewLabelDraft] = useState("");
  const [viewDescriptionDraft, setViewDescriptionDraft] = useState("");
  const [viewSaving, setViewSaving] = useState(false);
  const [viewError, setViewError] = useState<string | null>(null);
  const selectAllRef = useRef<HTMLInputElement>(null);
  const activeActionRef = useRef<Action | null>(null);
  const actionLockedRef = useRef(false);
  const restoredQueryRef = useRef(false);
  const cellRequestsRef = useRef(new Map<string, AbortController>());
  const token = (names: string | string[], fallback: string) => themeToken(theme, names, fallback) ?? fallback;
  const themeStyle = {
    ...customThemeVariables(theme),
    ...recipeVariables(theme),
    "--inlay-accent": token("accent", "var(--inlay-default-accent, #4f46e5)"),
    "--inlay-accent-foreground": token("accent-foreground", "var(--inlay-panel-accent-foreground, #ffffff)"),
    "--inlay-radius": token("radius", "var(--inlay-panel-radius, 0.75rem)"),
    "--inlay-surface":
      token("surface", "var(--inlay-default-surface, #ffffff)"),
    "--inlay-surface-muted":
      token(["surface-muted", "muted-surface"],
      "var(--inlay-default-surface-muted, color-mix(in srgb, var(--inlay-surface) 94%, var(--inlay-text)))",
      ),
    "--inlay-hover":
      token(["table-row-hover", "row-hover", "hover"],
      "var(--inlay-table-row-hover, var(--inlay-panel-hover, color-mix(in srgb, var(--inlay-accent) 6%, var(--inlay-surface))))",
      ),
    "--inlay-foreground": token(["foreground", "text"], "var(--inlay-default-foreground, #18181b)"),
    "--inlay-text": "var(--inlay-foreground)",
    "--inlay-muted": token("muted", "var(--inlay-default-muted, #71717a)"),
    "--inlay-border":
      token("border", "var(--inlay-default-border, rgb(24 24 27 / 0.12))"),
    "--inlay-control-border":
      token(["control-border", "border"],
      "var(--inlay-panel-control-border, #d4d4d8)",
      ),
    "--inlay-focus-ring":
      "color-mix(in srgb, var(--inlay-focus-ring-color) 22%, transparent)",
    "--inlay-danger":
      token("danger", "var(--inlay-default-danger, #dc2626)"),
    "--inlay-danger-surface":
      token("danger-surface", "var(--inlay-default-danger-surface, color-mix(in srgb, var(--inlay-danger) 8%, var(--inlay-surface)))"),
    "--inlay-success": token("success", "var(--inlay-default-success, #16a34a)"),
    "--inlay-success-surface": token("success-surface", "var(--inlay-default-success-surface, rgb(22 163 74 / 0.08))"),
    "--inlay-warning": token("warning", "var(--inlay-default-warning, #d97706)"),
    "--inlay-warning-surface": token("warning-surface", "var(--inlay-default-warning-surface, rgb(217 119 6 / 0.1))"),
    "--inlay-info": token("info", "var(--inlay-default-info, #0284c7)"),
    "--inlay-info-surface": token("info-surface", "var(--inlay-default-info-surface, rgb(2 132 199 / 0.08))"),
    "--inlay-overlay": token("overlay", "var(--inlay-panel-overlay, rgb(24 24 27 / 0.55))"),
    "--inlay-scrim": token("scrim", "var(--inlay-panel-scrim, rgb(0 0 0 / 0.3))"),
    "--inlay-control-height":
      token("control-height", "var(--inlay-panel-control-height, 2.5rem)"),
    "--inlay-button-height":
      token("button-height", "var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))"),
    "--inlay-button-xs-height":
      token(["button-xs-height", "button-extra-small-height"], "var(--inlay-panel-button-xs-height, 2rem)"),
    "--inlay-button-sm-height":
      token(["button-sm-height", "button-small-height"], "var(--inlay-panel-button-sm-height, 2.25rem)"),
    "--inlay-button-lg-height":
      token(["button-lg-height", "button-large-height"], "var(--inlay-panel-button-lg-height, 2.75rem)"),
    "--inlay-icon-button-size":
      token("icon-button-size", "var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))"),
    "--inlay-shadow": token("shadow", "var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))"),
  } as CSSProperties;
  const columnsByName = new Map(resource.columns.map((column) => [column.name, column]));
  const orderedColumns = columnOrder.map((name) => columnsByName.get(name)).filter((column): column is Column => column != null);
  const columns = orderedColumns.filter((column) => columnVisibility[column.name] ?? column.visible);
  // Let content choose sensible widths by default. Explicit dimensions are the
  // opt-in contract for deterministic fixed sizing, while the scroll shell
  // still protects narrow viewports from overflowing the page.
  const fixedTableLayout = columns.some((column) => Boolean(column.columnWidth || column.minWidth || column.maxWidth));
  const headerSegments = columnHeaderSegments(columns, resource.columnGroups ?? []);
  const hasColumnGroups = headerSegments.some((segment) => segment.group !== null);
  const gridLayout = Boolean(resource.layout?.contentGrid);
  const stackedLayout = Boolean(resource.layout?.stackedOnMobile);
  const customLayout = Boolean(resource.columnLayout?.length);
  const cardLayout = gridLayout || stackedLayout || customLayout;
  const keyFor = (row: TableRow) => row[resource.primaryKey] as string | number;
  const selectableKeys = resource.selection?.recordKeys ?? resource.rows.map(keyFor);
  const selectionMaximum = resource.selection?.maximum ?? null;
  const selectAllKeys = selectableKeys.slice(0, selectionMaximum ?? selectableKeys.length);
  const isKeySelected = (key: string | number) => allMatchingSelected ? !excluded.includes(key) : selected.includes(key);
  const selectedRows = resource.rows.filter((row) => isKeySelected(keyFor(row)));
  const selectedCount = allMatchingSelected ? Math.max(0, (resource.selection?.total ?? 0) - excluded.length) : selected.length;
  const allSelectableSelected = selectAllKeys.length > 0 && selectAllKeys.every(isKeySelected);
  const someSelectableSelected = selectableKeys.some(isKeySelected);
  const selectionPayload = (): BulkSelectionState => allMatchingSelected
    ? { mode: 'query', excluded, query }
    : { mode: 'page', records: selected };
  const activeFilters = resource.filters.filter((filter) =>
    isActiveFilter(query.filters[filter.name]),
  );
  const indicators: FilterIndicator[] = resource.filterIndicatorsHidden
    ? []
    : resource.filterIndicators
      ?? activeFilters.map((filter) => ({
        filter: filter.name,
        field: filter.name,
        label: `${filter.label}: ${filterDisplayValue(filter, query.filters[filter.name])}`,
      }));
  const removeIndicator = (indicator: FilterIndicator) => {
    const [name, ...path] = indicator.field.split(".");
    const next = { ...query.filters };
    if (path.length === 0) delete next[name];
    else {
      const branch = { ...(next[name] as Record<string, unknown>) };
      delete branch[path.join(".")];
      if (Object.keys(branch).length === 0) delete next[name];
      else next[name] = branch;
    }
    setDraftFilters(next);
    changeQuery({ filters: next, page: 1 });
  };

  const actionRuntime = useActionRuntime<unknown | void>((context) => {
    const { input, url } = context;
    const action = activeActionRef.current;
    if (!action) return;
    const rows = [...input.records] as TableRow[];
    const bulkSelection = action.bulk ? input.data.selection as BulkSelectionState | undefined : undefined;
    if (onAction) return bulkSelection?.mode === 'query'
      ? onAction(action, rows, bulkSelection)
      : onAction(action, rows);
    if (!url) return;
    const data = {
      ...input.data,
      ...(action.bulk && bulkSelection?.mode !== 'query' ? { records: rows.map(keyFor) } : {}),
    };
    if (action.lifecycle) {
      return executeActionEndpoint({
        ...context,
        input: { ...input, data },
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
          setExportQueued(typeof result.message === 'string' && result.message.trim() !== '' ? result.message : 'Export queued.');
        }
        return result;
      }).catch((error: unknown) => {
        setExportError(error instanceof Error ? error.message : "The export could not be downloaded.");
        throw error;
      });
    }
    return router.visit(url, { method: action.method, data: data as never });
  });

  useEffect(() => {
    if (!resource.query) return;
    setQuery(resource.query);
    setDraftFilters(resource.query.filters);
    // A server response may advance the query without going through
    // `changeQuery()` (for example, an Inertia visit initiated by a parent or
    // a saved-view redirect). Selection is scoped to the query that produced
    // the rows, so carrying it across a new search/filter/sort would let a
    // bulk action operate on a stale selection descriptor.
    setSelected([]);
    setAllMatchingSelected(false);
    setExcluded([]);
  }, [resource.query]);

  useEffect(() => {
    if (!filtersOpen || resource.filtersLayout !== "modal") return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") setFiltersOpen(false);
    };
    window.addEventListener("keydown", onKeyDown);

    return () => window.removeEventListener("keydown", onKeyDown);
  }, [filtersOpen, resource.filtersLayout]);

  useEffect(() => {
    if (actionRuntime.state.phase !== "idle") return;
    actionLockedRef.current = false;
    activeActionRef.current = null;
  }, [actionRuntime.state.phase]);

  useEffect(() => {
    if (actionRuntime.state.phase === "succeeded" && activeActionRef.current?.deselectRecordsAfterCompletion) {
      setSelected([]);
      setAllMatchingSelected(false);
      setExcluded([]);
    }
    if (actionRuntime.state.phase === "succeeded" && activeActionRef.current?.lifecycle) {
      router.reload();
    }
  }, [actionRuntime.state.phase]);

  useEffect(() => {
    setSelected((current) => current.filter((key) => selectableKeys.includes(key)));
  }, [resource.rows, resource.selection]);

  useEffect(() => {
    if (!reordering) setOrderedRows(resource.rows);
  }, [resource.rows, reordering]);

  useEffect(() => {
    setCellErrors({});
    for (const controller of cellRequestsRef.current.values()) controller.abort();
    cellRequestsRef.current.clear();
    setUpdatingCells([]);
  }, [resource.name, resource.rows]);

  useEffect(() => () => {
    for (const controller of cellRequestsRef.current.values()) controller.abort();
    cellRequestsRef.current.clear();
  }, []);

  useEffect(() => {
    if (selectAllRef.current) selectAllRef.current.indeterminate = someSelectableSelected && !allSelectableSelected;
  }, [someSelectableSelected, allSelectableSelected]);

  // A search only leaves the browser when PHP says it should: immediately, after
  // the configured debounce, or when the field is left or Enter is pressed.
  const [searchDraft, setSearchDraft] = useState(query.search);
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => setSearchDraft(query.search), [query.search]);
  useEffect(() => () => { if (searchTimer.current) clearTimeout(searchTimer.current); }, []);

  const commitSearch = (search: string) => {
    if (searchTimer.current) clearTimeout(searchTimer.current);
    if (search !== query.search) changeQuery({ search, page: 1 });
  };

  const queueSearch = (search: string) => {
    if (searchTimer.current) clearTimeout(searchTimer.current);
    const debounce = resource.searchDebounce ?? 0;
    if (debounce <= 0) {
      commitSearch(search);
      return;
    }
    searchTimer.current = setTimeout(() => commitSearch(search), debounce);
  };

  const changeQuery = (patch: Partial<QueryState>) => {
    const resetCursor = ['search', 'columnSearches', 'sort', 'filters', 'group', 'groupDirection', 'view'].some((key) => Object.hasOwn(patch, key));
    if (resetCursor) {
      setSelected([]);
      setAllMatchingSelected(false);
      setExcluded([]);
    }
    const next = { ...query, ...(resetCursor ? { cursor: null } : {}), ...patch };
    setQuery(next);
    persistQueryState(resource, next);
    if (onQueryChange) onQueryChange(next);
    else
      router.get(
        window.location.pathname,
        flattenQuery(resource.name, next) as never,
        {
          preserveState: true,
          preserveScroll: true,
          queryStringArrayFormat: "indices",
          replace: true,
        },
      );
  };

  const chooseView = (name: string) => {
    const view = resource.views?.find((item) => item.name === name);
    const preset = view?.query ?? {};
    changeQuery({
      view: view?.name ?? null,
      search: typeof preset.search === "string" ? preset.search : "",
      columnSearches: preset.columnSearches ?? {},
      sort: typeof preset.sort === "string" ? preset.sort : null,
      direction: preset.direction === "desc" ? "desc" : "asc",
      filters: preset.filters && typeof preset.filters === "object" && !Array.isArray(preset.filters) ? preset.filters as Record<string, unknown> : defaults(resource),
      group: typeof preset.group === "string" ? preset.group : null,
      groupDirection: preset.groupDirection === "desc" ? "desc" : "asc",
      perPage: preset.perPage ?? null,
      page: 1,
      cursor: null,
    });
  };

  const activePersonalView = resource.views?.find((view) => view.name === query.view && view.personal === true);
  const viewManagement = resource.viewManagement;
  const personalViewQuery = () => ({
    search: query.search,
    columnSearches: query.columnSearches ?? {},
    sort: query.sort,
    direction: query.direction,
    filters: query.filters,
    group: query.group ?? null,
    groupDirection: query.groupDirection ?? "asc",
    perPage: query.perPage ?? null,
  });
  const savePersonalView = () => {
    if (!viewManagement || viewNameDraft.trim() === '' || viewLabelDraft.trim() === '') return;
    setViewSaving(true);
    setViewError(null);
    const editing = activePersonalView !== undefined;
    const payload = {
      _inlay_table_view: "save",
      table: resource.name,
      name: viewNameDraft.trim(),
      originalName: editing ? activePersonalView.name : null,
      label: viewLabelDraft.trim(),
      description: viewDescriptionDraft.trim() || null,
      query: personalViewQuery(),
    };
    const finish = () => { setViewSaving(false); setViewEditorOpen(false); setViewNameDraft(""); setViewLabelDraft(""); setViewDescriptionDraft(""); router.reload(); };
    const fail = () => { setViewSaving(false); setViewError("The view could not be saved."); };
    router.post(viewManagement.url, payload as never, { preserveScroll: true, onSuccess: finish, onError: fail });
  };
  const deletePersonalView = () => {
    if (!viewManagement || !activePersonalView || !window.confirm(`Delete ${activePersonalView.label}?`)) return;
    const endpoint = `${viewManagement.url}${viewManagement.url.includes("?") ? "&" : "?"}${new URLSearchParams({ _inlay_table_view: "delete", table: resource.name, name: activePersonalView.name }).toString()}`;
    router.delete(endpoint, { preserveScroll: true, onSuccess: () => router.reload() });
  };

  useEffect(() => {
    if (restoredQueryRef.current) return;
    restoredQueryRef.current = true;
    const restored = restoredQueryState(resource);
    if (restored) changeQuery({ ...restored, page: 1, cursor: null });
  }, []);

  useEffect(() => {
    if (!resource.deferLoading || query.loaded) return;
    changeQuery({ loaded: true });
  }, [resource.deferLoading, query.loaded]);

  // A table polls only while the tab is being looked at: one left open in a
  // background tab would otherwise keep asking the server forever. A host that
  // supplied `onRefresh` owns the request, so the table does not also reload.
  useEffect(() => {
    if (!resource.pollIntervalMs || query.loaded === false) return;
    const timer = window.setInterval(() => {
      if (document.hidden) return;
      if (onRefresh) onRefresh();
      else router.reload();
    }, resource.pollIntervalMs);

    return () => window.clearInterval(timer);
  }, [onRefresh, query.loaded, resource.pollIntervalMs]);

  const recordUrl = (row: TableRow) => resource.recordUrls?.[String(keyFor(row))];
  const visitRecord = (row: TableRow) => {
    const url = recordUrl(row);
    if (!url || !isSafeUrl(url)) return;
    if (resource.openRecordUrlInNewTab) {
      window.open(url, "_blank", "noopener,noreferrer");
    } else {
      router.visit(url);
    }
  };
  const rowHasInteractiveTarget = (target: EventTarget | null) =>
    target instanceof Element && target.closest("a, button, input, select, textarea, label, [data-no-record-click]") !== null;
  const isLoading = loading || Boolean(resource.deferLoading && !query.loaded);

  const execute = (action: Action, rows: TableRow[]) => {
    if (actionLockedRef.current) return;
    actionLockedRef.current = true;
    activeActionRef.current = action;
    if (action.download) {
      setExportError(null);
      setExportQueued(null);
    }
    const runtimeAction = onAction ? { ...action, url: null } : action;
    const confirmationRequired =
      runtimeAction.requiresConfirmation || runtimeAction.modal != null;
    const actionData = action.bulk
      ? action.download
        ? { selection: allMatchingSelected ? selectionPayload() : { mode: 'page' as const, records: rows.map((row) => keyFor(row)), query } }
        : allMatchingSelected
          ? { selection: selectionPayload() }
          : { records: rows.map((row) => keyFor(row)) }
      : {};
    void actionRuntime
      .trigger(runtimeAction, { parameters: rows[0] ?? {}, records: rows, data: actionData })
      .then(() => {
        if (!confirmationRequired) {
          actionRuntime.close();
          actionLockedRef.current = false;
          activeActionRef.current = null;
        }
      });
  };

  const changeFilter = (name: string, value: unknown) => {
    const filters = {
      ...(resource.deferFilters ? draftFilters : query.filters),
      [name]: value,
    };
    setDraftFilters(filters);
    if (!resource.deferFilters) changeQuery({ filters, page: 1 });
  };

  const applyFilters = () => {
    const filters = normalizeQueryBuilderFilters(resource.filters, draftFilters);
    setDraftFilters(filters);
    changeQuery({ filters, page: 1 });
    setFiltersOpen(false);
  };

  const resetFilters = () => {
    const filters = defaults(resource);
    setDraftFilters(filters);
    changeQuery({ filters, page: 1 });
  };
  const filtersLayout = resource.filtersLayout ?? "dropdown";
  const chipFilters = resource.filters.filter((filter) => filter.type === "select-filter" && (filter.options?.length ?? 0) > 0);
  const chipSelected = (filter: Filter, value: string | number) => {
    const selected = query.filters[filter.name];
    if (value === "") return selected == null || selected === "" || (Array.isArray(selected) && selected.length === 0);
    return Array.isArray(selected) ? selected.some((item) => String(item) === String(value)) : String(selected) === String(value);
  };
  const chooseChip = (filter: Filter, value: string | number) => {
    const next = { ...query.filters };
    if (value === "") delete next[filter.name];
    else next[filter.name] = value;
    setDraftFilters(next);
    changeQuery({ filters: next, page: 1 });
  };
  const filtersFormColumns = Math.min(Math.max(resource.filtersFormColumns ?? 3, 1), 6);
  const filtersResetActionPosition = resource.filtersResetActionPosition ?? "header";
  // PHP validates the name against one shared list, so an unknown width here
  // would be a contract break rather than an author's typo.
  const panelWidthClass = (width?: string | null) => width == null ? "" : ({
    xs: "max-w-xs", sm: "max-w-sm", md: "max-w-md", lg: "max-w-lg", xl: "max-w-xl",
    "2xl": "max-w-2xl", "3xl": "max-w-3xl", "4xl": "max-w-4xl", "5xl": "max-w-5xl",
    "6xl": "max-w-6xl", "7xl": "max-w-7xl", screen: "max-w-full",
  }[width] ?? "");

  const filtersPanel = resource.filters.length ? (
    <>
      {filtersLayout === "modal" ? (
        <div
          aria-hidden="true"
          className="fixed inset-0 z-40 bg-(--inlay-overlay) backdrop-blur-[2px]"
          data-slot="filters-modal-backdrop"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) setFiltersOpen(false);
          }}
        />
      ) : null}
      <div
        aria-label="Table filters"
        aria-modal={filtersLayout === "modal" ? "true" : undefined}
        className={`${filtersLayout === "modal" ? "fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-h-[calc(100dvh-2rem)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto shadow-2xl" : "mt-4"} rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface-muted) p-4 shadow-xs sm:p-5 ${panelWidthClass(resource.filtersFormWidth)} ${classNames?.filtersPanel ?? ""}`}
        data-slot="filters-panel"
        id={`${resource.name}-filters`}
        role={filtersLayout === "modal" ? "dialog" : "region"}
        style={resource.filtersFormMaxHeight ? { maxHeight: resource.filtersFormMaxHeight, overflowY: "auto" } : undefined}
      >
          {filtersResetActionPosition === "header" || filtersLayout === "modal" ? (
            <div className={`mb-4 flex ${filtersLayout === "modal" ? "items-center justify-between" : "justify-end"}`} data-slot="filter-header-actions">
              {filtersLayout === "modal" ? <h2 className="text-base font-semibold text-(--inlay-text)">Filters</h2> : null}
              <div className="flex items-center gap-2">
                {filtersResetActionPosition === "header" ? (
                  <button
                    className={`${secondaryButton} ${classNames?.resetButton ?? ""}`}
                    data-slot="filters-reset"
                    onClick={resetFilters}
                    type="button"
                  >
                    Reset
                  </button>
                ) : null}
                {filtersLayout === "modal" ? (
                  <button
                    aria-label="Close filters"
                    className={secondaryButton}
                    data-slot="filters-close"
                    onClick={() => setFiltersOpen(false)}
                    type="button"
                  >
                    Close
                  </button>
                ) : null}
              </div>
            </div>
          ) : null}
          <div
            className="grid grid-cols-1 gap-x-5 gap-y-4 sm:[grid-template-columns:repeat(var(--inlay-filter-columns),minmax(0,1fr))]"
            data-slot="filters"
            style={{ "--inlay-filter-columns": filtersFormColumns } as CSSProperties}
          >
            {resource.filters.map((filter) => (
              <div
                className="min-w-0 sm:[grid-column:span_var(--inlay-filter-span)]"
                data-slot="filter-cell"
                key={filter.name}
                style={{ "--inlay-filter-span": Math.min(filter.columnSpan ?? 1, filtersFormColumns) } as CSSProperties}
              >
                <FilterControl
                  classNames={classNames}
                  filter={filter}
                  onChange={(value) => changeFilter(filter.name, value)}
                  registries={registries}
                  renderers={renderers}
                  value={draftFilters[filter.name]}
                />
              </div>
            ))}
          </div>
          <div
            className={`mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-(--inlay-border) pt-4 ${classNames?.filterActions ?? ""}`}
            data-slot="filter-actions"
          >
            {filtersResetActionPosition === "footer" ? (
              <button
                className={`${secondaryButton} ${classNames?.resetButton ?? ""}`}
                data-slot="filters-reset"
                onClick={resetFilters}
                type="button"
              >
                Reset
              </button>
            ) : <span />}
            {resource.deferFilters ? (
              <button
                className={`${primaryButton} ${classNames?.applyButton ?? ""}`}
                data-slot="filters-apply"
                onClick={applyFilters}
                type="button"
              >
                Apply filters
              </button>
            ) : null}
          </div>
      </div>
    </>
  ) : null;

  const commitColumns = (visibility: Record<string, boolean>, order = draftColumnOrder) => {
    setColumnVisibility(visibility);
    setColumnOrder(order);
    if (resource.columnManager?.persistInSession && typeof window !== "undefined") {
      try {
        window.sessionStorage.setItem(columnStorageKey(resource), JSON.stringify({ visibility, order }));
      } catch {
        // Storage can be unavailable in hardened browser contexts; visibility still applies in memory.
      }
    }
  };

  const changeColumnVisibility = (name: string, visible: boolean) => {
    const next = { ...draftColumnVisibility, [name]: visible };
    setDraftColumnVisibility(next);
    if (!resource.columnManager?.deferred) commitColumns(next, draftColumnOrder);
  };

  const moveColumn = (name: string, offset: -1 | 1) => {
    const index = draftColumnOrder.indexOf(name);
    const target = index + offset;
    if (index < 0 || target < 0 || target >= draftColumnOrder.length) return;
    const next = [...draftColumnOrder];
    [next[index], next[target]] = [next[target], next[index]];
    setDraftColumnOrder(next);
    if (!resource.columnManager?.deferred) commitColumns(draftColumnVisibility, next);
  };

  const resetColumns = () => {
    const visibility = Object.fromEntries(resource.columns.map((column) => [column.name, column.visible]));
    const order = resource.columns.map((column) => column.name);
    setDraftColumnVisibility(visibility);
    setDraftColumnOrder(order);
    if (!resource.columnManager?.deferred) commitColumns(visibility, order);
  };

  const openColumns = () => {
    setDraftColumnVisibility(columnVisibility);
    setDraftColumnOrder(columnOrder);
    setColumnsOpen(true);
  };

  const closeColumns = () => {
    setDraftColumnVisibility(columnVisibility);
    setDraftColumnOrder(columnOrder);
    setColumnsOpen(false);
  };

  const moveRecord = (row: TableRow, offset: -1 | 1) => {
    const index = orderedRows.findIndex((candidate) => keyFor(candidate) === keyFor(row));
    const target = index + offset;
    if (index < 0 || target < 0 || target >= orderedRows.length) return;
    const next = [...orderedRows];
    [next[index], next[target]] = [next[target], next[index]];
    setOrderedRows(next);
    setReorderAnnouncement(`Moved row ${keyFor(row)} to position ${target + 1}.`);
  };

  const dropRecord = (targetRow: TableRow) => {
    if (draggedRecordKey === null) return;
    const source = orderedRows.findIndex((row) => String(keyFor(row)) === String(draggedRecordKey));
    const target = orderedRows.findIndex((row) => String(keyFor(row)) === String(keyFor(targetRow)));
    if (source < 0 || target < 0 || source === target) {
      setDraggedRecordKey(null);
      setDragTargetKey(null);
      return;
    }
    const next = [...orderedRows];
    const [moved] = next.splice(source, 1);
    next.splice(target, 0, moved);
    setOrderedRows(next);
    setReorderAnnouncement(`Moved row ${draggedRecordKey} to position ${target + 1}.`);
    setDraggedRecordKey(null);
    setDragTargetKey(null);
  };

  const cancelReordering = () => {
    setOrderedRows(resource.rows);
    setReordering(false);
    setReorderError(null);
    setDraggedRecordKey(null);
    setDragTargetKey(null);
    setReorderAnnouncement("");
  };

  const saveReordering = async () => {
    const records = orderedRows.map(keyFor);
    const startPosition = resource.pagination?.from ?? 1;
    setReorderError(null);
    setReorderSubmitting(true);
    if (onReorder) {
      try {
        await onReorder(records, startPosition);
        setReordering(false);
      } catch (error) {
        setReorderError(error instanceof Error ? error.message : "The table order could not be saved.");
      } finally {
        setReorderSubmitting(false);
      }
      return;
    }
    if (!resource.reordering?.url) {
      setReorderSubmitting(false);
      return;
    }
    router.patch(resource.reordering.url, { table: resource.name, records, startPosition, version: resource.reordering.version ?? null }, {
      preserveScroll: true,
      onSuccess: () => { setReorderError(null); setReordering(false); },
      onError: (errors) => {
        const message = Object.values(errors as Record<string, unknown>)
          .flatMap((value) => Array.isArray(value) ? value : [value])
          .find((value): value is string => typeof value === "string" && value.trim() !== "");
        setReorderError(message ?? "The table order could not be saved. Reload the table and try again.");
      },
      onFinish: () => setReorderSubmitting(false),
    });
  };

  const cellKey = (row: TableRow, column: Column) => `${String(keyFor(row))}:${column.name}`;
  const handleCellChange = (row: TableRow, column: Column, state: unknown) => {
    if (!resource.editableColumns?.url || !column.editable) {
      onCellChange?.(row, column, state);
      return;
    }

    const key = cellKey(row, column);
    const previous = getColumnState(row, column.name);
    cellRequestsRef.current.get(key)?.abort();
    const controller = new AbortController();
    cellRequestsRef.current.set(key, controller);
    setOrderedRows((current) => updateRowColumn(current, resource.primaryKey, keyFor(row), column.name, state));
    setCellErrors((current) => {
      const next = { ...current };
      delete next[key];
      return next;
    });
    setUpdatingCells((current) => current.includes(key) ? current : [...current, key]);

    void columnUpdater({ resource, row, column, state, signal: controller.signal })
      .then((response) => {
        if (cellRequestsRef.current.get(key) !== controller) return;
        setOrderedRows((current) => updateRowColumn(current, resource.primaryKey, keyFor(row), column.name, response.state));
        onCellChange?.(updateRowColumn([row], resource.primaryKey, keyFor(row), column.name, response.state)[0], column, response.state);
      })
      .catch((error: unknown) => {
        if (controller.signal.aborted || cellRequestsRef.current.get(key) !== controller) return;
        setOrderedRows((current) => updateRowColumn(current, resource.primaryKey, keyFor(row), column.name, previous));
        const resolved = error instanceof Error ? error : new Error('Column update failed.');
        const message = error instanceof ColumnUpdateError
          ? error.errors.state?.[0] ?? error.message
          : resolved.message;
        setCellErrors((current) => ({ ...current, [key]: message }));
        onCellUpdateError?.(resolved, row, column);
      })
      .finally(() => {
        if (cellRequestsRef.current.get(key) !== controller) return;
        cellRequestsRef.current.delete(key);
        setUpdatingCells((current) => current.filter((item) => item !== key));
      });
  };

  // The API distinguishes four positions; nothing follows the data columns
  // here, so `after-cells` renders where `after-columns` does rather than being
  // refused for a table ported from the documented contract.
  const actionsPosition = resource.actionsPosition === "after-cells" ? "after-columns" : resource.actionsPosition ?? "after-columns";
  const actionsAt = (slot: string, row: TableRow) => resource.actions.length && actionsPosition === slot ? <td className={`${cardLayout ? "block px-2 py-2" : "w-max min-w-32 whitespace-nowrap border-l border-(--inlay-border) bg-(--inlay-surface) px-3 py-3 group-hover:bg-(--inlay-hover) group-focus-within:bg-(--inlay-hover) lg:sticky lg:right-0 lg:z-10 lg:shadow-[-8px_0_12px_-12px_rgb(0_0_0_/_0.35)]"} text-right ${classNames?.cell ?? ""}`}><div className={`flex items-center justify-end gap-1.5 whitespace-nowrap ${classNames?.rowActions ?? ""}`} data-slot="row-actions">
        {resource.actions.filter((action) => actionVisible(action.visibleWhen, row)).map((action) => <ActionButton action={action} key={action.instanceKey ?? action.name} onClick={() => execute(action, [row])} processing={actionRuntime.state.phase === "executing"} registries={registries} renderers={renderers} rows={[row]} />)}
      </div></td> : null;

  const actionsHeaderAt = (slot: string) => resource.actions.length && actionsPosition === slot ? (<th
                    className="w-max min-w-32 whitespace-nowrap border-b border-l border-(--inlay-border) bg-(--inlay-surface-muted) px-3 py-2.5 text-right text-xs font-semibold tracking-wide text-(--inlay-muted) uppercase lg:sticky lg:right-0 lg:z-20 lg:shadow-[-8px_0_12px_-12px_rgb(0_0_0_/_0.35)]"
                    rowSpan={hasColumnGroups ? 2 : undefined}
                    scope="col"
                  >
                    <span className="sr-only">Actions</span>
                  </th>) : null;

  const summaryPageVisible = resource.summaries?.pageVisible !== false;
  const summaryQueryVisible = resource.summaries?.queryVisible !== false;
  const summaryPage = summaryPageVisible ? resource.summaries?.page ?? {} : {};
  const summaryQuery = summaryQueryVisible ? resource.summaries?.query ?? {} : {};
  const hasSummaryRows = Object.keys(summaryPage).length > 0 || Object.keys(summaryQuery).length > 0;

  const renderRow = (row: TableRow) => {
    const rowIndex = orderedRows.findIndex((candidate) => keyFor(candidate) === keyFor(row));

    return (
    <tr
      className={`group transition-colors hover:bg-(--inlay-hover) focus-within:bg-(--inlay-hover) data-[drag-target=true]:bg-(--inlay-hover) data-[drag-target=true]:outline-2 data-[drag-target=true]:-outline-offset-2 data-[drag-target=true]:outline-(--inlay-accent) ${resource.striped && rowIndex % 2 === 1 ? "bg-(--inlay-surface-muted)" : ""} ${resource.rowClasses?.[String(keyFor(row))] ?? ""} ${gridLayout || customLayout ? "block rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-3 shadow-xs" : stackedLayout ? "mb-3 block rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-2 shadow-xs sm:mb-0 sm:table-row sm:rounded-none sm:border-0 sm:p-0 sm:shadow-none" : "h-[66px] [&:not(:last-child)>td]:border-b [&:not(:last-child)>td]:border-(--inlay-border)"} ${recordUrl(row) ? "cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--inlay-accent)" : ""} ${classNames?.row ?? ""}`}
      data-drag-target={reordering && String(dragTargetKey) === String(keyFor(row)) ? "true" : undefined}
      data-row-key={keyFor(row)}
      data-slot="table-row"
      key={keyFor(row)}
      onClick={(event) => { if (!rowHasInteractiveTarget(event.target)) visitRecord(row); }}
      onKeyDown={(event) => {
        if ((event.key === "Enter" || event.key === " ") && !rowHasInteractiveTarget(event.target)) {
          event.preventDefault(); visitRecord(row);
        }
      }}
      onDragOver={(event) => {
        if (!reordering || draggedRecordKey === null) return;
        event.preventDefault();
        setDragTargetKey(keyFor(row));
      }}
      onDrop={(event) => {
        if (!reordering) return;
        event.preventDefault();
        dropRecord(row);
      }}
      role={recordUrl(row) ? "link" : undefined}
      tabIndex={recordUrl(row) ? 0 : undefined}
    >
      {actionsAt('before-cells', row)}
      {reordering ? <td className="w-32 px-2 py-2"><div className="flex justify-center gap-1"><button aria-label={`Drag row ${keyFor(row)}`} className={`${secondaryButton} min-h-8 cursor-grab px-2 active:cursor-grabbing`} draggable onClick={(event) => event.stopPropagation()} onDragEnd={() => { setDraggedRecordKey(null); setDragTargetKey(null); }} onDragStart={(event) => { event.stopPropagation(); event.dataTransfer.effectAllowed = "move"; event.dataTransfer.setData("text/plain", String(keyFor(row))); setDraggedRecordKey(keyFor(row)); }} type="button">⋮⋮</button><button aria-label={`Move row ${keyFor(row)} up`} className={`${secondaryButton} min-h-8 px-2`} disabled={orderedRows[0] === row} onClick={() => moveRecord(row, -1)} type="button">↑</button><button aria-label={`Move row ${keyFor(row)} down`} className={`${secondaryButton} min-h-8 px-2`} disabled={orderedRows[orderedRows.length - 1] === row} onClick={() => moveRecord(row, 1)} type="button">↓</button></div></td> : null}
      {resource.selectable ? <td className={`${cardLayout ? "block w-full px-2 py-2 sm:w-auto" : "w-12 px-4 py-2.5"} ${classNames?.cell ?? ""}`}>
        <input aria-describedby={`${resource.name}-selection-status`} aria-label={`Select row ${keyFor(row)}`} checked={isKeySelected(keyFor(row))} className="size-5 rounded accent-(--inlay-accent) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent) sm:size-4" disabled={!selectableKeys.includes(keyFor(row)) || (!isKeySelected(keyFor(row)) && selectionMaximum !== null && selectedCount >= selectionMaximum)} onChange={(event) => { const key = keyFor(row); if (allMatchingSelected) setExcluded(event.target.checked ? excluded.filter(item => item !== key) : [...excluded, key]); else setSelected(event.target.checked ? [...selected, key] : selected.filter(item => item !== key)); }} title={!selectableKeys.includes(keyFor(row)) ? 'This record cannot be selected.' : !isKeySelected(keyFor(row)) && selectionMaximum !== null && selectedCount >= selectionMaximum ? `You can select at most ${selectionMaximum} records.` : undefined} type="checkbox" />
      </td> : null}
      {actionsAt('before-columns', row)}
      {customLayout ? <td className="block p-2" colSpan={columns.length}><div className="grid gap-3" data-slot="column-layout">{resource.columnLayout?.map((component, index) => <ColumnLayoutRenderer component={component} key={index} onChange={(column, value) => handleCellChange(row, column, value)} registries={registries} renderers={renderers} row={row} />)}</div></td> : columns.map((column) => <td {...safeAttributes(cellAttributesFor(row, column))} data-no-record-click={column.disabledClick ? "true" : undefined} className={`${cardLayout ? `grid grid-cols-[minmax(7rem,0.4fr)_1fr] items-center gap-3 px-2 py-2 ${stackedLayout && !gridLayout ? "sm:table-cell sm:px-4 sm:py-4" : ""}` : "min-w-0 overflow-hidden px-3 py-4 lg:px-4"} text-sm leading-5 text-(--inlay-text) ${alignmentClass(column.alignment)} ${verticalAlignmentClass(column.verticalAlignment)} ${responsiveColumnClass(column)} ${column.wrap ? "whitespace-normal" : ""} ${classNames?.cell ?? ""}`} data-slot="table-cell" key={column.name} style={columnDimensionStyle(column)}>
        {cardLayout ? <span className={`text-left text-xs font-medium text-(--inlay-muted) ${stackedLayout && !gridLayout ? "sm:hidden" : ""}`}>{column.label}</span> : null}<span {...safeAttributes(contentAttributesFor(row, column))} className={`${column.grow === false ? "grow-0" : "min-w-0 grow"} grid gap-1`}>{column.actions?.length ? <ColumnActionGroup actions={column.actions} column={column} execute={execute} row={row}><Cell column={column} disabled={updatingCells.includes(cellKey(row, column))} error={cellErrors[cellKey(row, column)]} onChange={(value) => handleCellChange(row, column, value)} registries={registries} renderers={renderers} row={row} /></ColumnActionGroup> : column.action ? <button className="w-full cursor-pointer rounded-sm text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" data-slot="column-action" onClick={(event) => { event.stopPropagation(); execute(column.action as Action, [row]); }} type="button"><Cell column={column} disabled={updatingCells.includes(cellKey(row, column))} error={cellErrors[cellKey(row, column)]} onChange={(value) => handleCellChange(row, column, value)} registries={registries} renderers={renderers} row={row} /></button> : <Cell column={column} disabled={updatingCells.includes(cellKey(row, column))} error={cellErrors[cellKey(row, column)]} onChange={(value) => handleCellChange(row, column, value)} registries={registries} renderers={renderers} row={row} />}{cellErrors[cellKey(row, column)] ? <span className="text-xs text-(--inlay-danger)" role="alert">{cellErrors[cellKey(row, column)]}</span> : null}</span>
      </td>)}
      {actionsAt('after-columns', row)}
    </tr>
    );
  };

  return (
    <section
      aria-busy={isLoading}
      aria-label={resource.name}
      className={`antialiased isolate min-w-0 max-w-full overflow-x-hidden ${classNames?.root ?? ""} ${className ?? ""}`}
      data-contract={resource.contract}
      data-slot="root"
      style={themeStyle}
    >
      <p aria-live="polite" className="sr-only" data-slot="reorder-status">{reorderAnnouncement}</p>
      {resource.heading || resource.description ? (
        <div className="mb-4" data-slot="table-heading">
          {resource.heading ? <h2 className="text-lg font-semibold text-(--inlay-text)">{resource.heading}</h2> : null}
          {resource.description ? <p className="mt-1 text-base text-(--inlay-muted) sm:text-sm">{resource.description}</p> : null}
        </div>
      ) : null}
      <div
        className={`flex flex-col gap-3 border-b border-(--inlay-border) pb-4 lg:flex-row lg:items-start lg:justify-between ${classNames?.toolbar ?? ""}`}
        data-slot="toolbar"
      >
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-3">
          {(resource.searchable ?? resource.columns.some((column) => column.searchable)) ? (
          <label className="w-full max-w-[250px] flex-none">
              <span className="sr-only">Search</span>
              <input
                aria-label="Search"
                className={`${controlClass} w-full`}
                data-slot="search"
                onBlur={() => resource.searchOnBlur && commitSearch(searchDraft)}
                onChange={(event) => {
                  setSearchDraft(event.target.value);
                  if (!resource.searchOnBlur) queueSearch(event.target.value);
                }}
                onKeyDown={(event) => {
                  if (event.key === "Enter") {
                    event.preventDefault();
                    commitSearch(searchDraft);
                  }
                }}
                placeholder={resource.searchPlaceholder}
                type="search"
                value={searchDraft}
              />
          </label>
        ) : null}
          {filtersLayout === "chips" && chipFilters.length ? <div aria-label="Table filters" className="flex flex-wrap gap-1.5" data-slot="filter-chips" role="group">{chipFilters.flatMap((filter) => [{ value: "", label: "All" }, ...(filter.options ?? [])].map((option) => <button aria-pressed={chipSelected(filter, option.value)} className={`inline-flex min-h-(--inlay-control-height) items-center justify-center gap-1.5 rounded-full border px-2.5 py-1 text-xs text-(--inlay-muted) transition ${chipSelected(filter, option.value) ? 'border-(--inlay-accent)/30 bg-(--inlay-accent)/10 font-semibold text-(--inlay-accent)' : 'border-(--inlay-border) bg-(--inlay-surface) hover:border-(--inlay-control-border) hover:text-(--inlay-text)'}`} key={`${filter.name}:${option.value}`} onClick={() => chooseChip(filter, option.value)} type="button">{option.label}</button>))}</div> : null}
          {resource.views?.length ? (
            <div className="min-w-0 flex-[1_1_12rem]">
              <span className="sr-only">Saved view</span>
              <Select ariaLabel="Saved view" attributes={{ 'data-slot': 'views' }} className="w-full" onValueChange={chooseView} options={[{ value: '', label: 'All records' }, ...resource.views.map((view) => ({ value: view.name, label: view.label }))]} placeholder="All records" value={query.view ?? ""} />
            </div>
          ) : null}
          {viewManagement ? (
            <div className="flex items-center gap-2" data-slot="view-actions">
              <button className={`${secondaryButton} shrink-0`} onClick={() => { setViewNameDraft(activePersonalView?.name ?? ""); setViewLabelDraft(activePersonalView?.label ?? ""); setViewDescriptionDraft(activePersonalView?.description ?? ""); setViewError(null); setViewEditorOpen(true); }} type="button">{activePersonalView ? "Edit view" : "Save view"}</button>
              {activePersonalView ? <button className={`${secondaryButton} shrink-0`} onClick={deletePersonalView} type="button">Delete view</button> : null}
            </div>
          ) : null}
          {resource.filters.length && filtersLayout !== "chips" && (filtersLayout === "dropdown" || filtersLayout === "above-content-collapsible" || filtersLayout === "modal") ? (
            <button
              aria-controls={`${resource.name}-filters`}
              aria-expanded={filtersOpen}
              className={`${triggerButtonClass(resource.triggers?.filters)} min-h-(--inlay-control-height) shrink-0 ${classNames?.filtersTrigger ?? ""}`}
              data-slot="filters-trigger"
              onClick={() => setFiltersOpen((open) => !open)}
              type="button"
            >
              {resource.triggers?.filters?.icon ? <NamedIcon fallback="◆" name={resource.triggers.filters.icon} registries={registries} renderers={renderers} /> : null}
              {resource.triggers?.filters?.label ?? "Filters"}
              {activeFilters.length ? (
                <span
                  aria-label={`${activeFilters.length} active filters`}
                  className="rounded-full bg-(--inlay-accent) px-1.5 py-0.5 text-xs text-(--inlay-accent-foreground)"
                >
                  {activeFilters.length}
                </span>
              ) : null}
            </button>
          ) : null}
          {resource.grouping && !resource.grouping.settingsHidden && resource.grouping.groups.length ? (
            <div className="min-w-0 flex-[1_1_12rem]">
              <span className="sr-only">Group records</span>
              <Select ariaLabel="Group records" className="w-full" onValueChange={(value) => changeQuery({ group: value || null, page: 1 })} options={[{ value: '', label: 'No grouping' }, ...resource.grouping.groups.map((group) => ({ value: group.name, label: group.label }))]} placeholder="No grouping" value={query.group ?? ""} />
            </div>
          ) : null}
          {resource.grouping?.active && !resource.grouping.directionSettingHidden ? (
            <button className={`${secondaryButton} shrink-0`} onClick={() => changeQuery({ groupDirection: query.groupDirection === "desc" ? "asc" : "desc", page: 1 })} type="button">
              Group {query.groupDirection === "desc" ? "descending" : "ascending"}
            </button>
          ) : null}
        </div>
        <div
          className={`flex w-full flex-wrap items-center gap-2 lg:w-auto lg:justify-end ${classNames?.headerActions ?? ""}`}
          data-slot="header-actions"
        >
          {resource.reordering?.enabled ? reordering ? <><button className={primaryButton} disabled={reorderSubmitting} onClick={() => void saveReordering()} type="button">{reorderSubmitting ? 'Saving…' : 'Save order'}</button><button className={secondaryButton} disabled={reorderSubmitting} onClick={cancelReordering} type="button">Cancel</button></> : <button className={`${triggerButtonClass(resource.triggers?.reordering)} ${classNames?.headerActions ?? ''}`} disabled={Boolean(resource.grouping?.active) || resource.rows.length < 2} onClick={() => { setOrderedRows(resource.rows); setReorderError(null); setReorderAnnouncement("Drag a row handle or use its move up and move down buttons."); setReordering(true); }} title={resource.grouping?.active ? 'Remove grouping before reordering records.' : undefined} type="button">{resource.triggers?.reordering?.icon ? <NamedIcon fallback="◆" name={resource.triggers.reordering.icon} registries={registries} renderers={renderers} /> : null}{resource.triggers?.reordering?.label ?? 'Reorder records'}</button> : null}
          {resource.columnManager && (resource.columnManager.reorderable || resource.columns.some((column) => column.toggleable)) ? (
            <button
              aria-controls={`${resource.name}-columns`}
              aria-expanded={columnsOpen}
              className={triggerButtonClass(resource.triggers?.columnManager)}
              data-slot="columns-trigger"
              onClick={() => columnsOpen ? closeColumns() : openColumns()}
              type="button"
            >
              {resource.triggers?.columnManager?.icon ? <NamedIcon fallback="◆" name={resource.triggers.columnManager.icon} registries={registries} renderers={renderers} /> : null}
              {resource.triggers?.columnManager?.label ?? "Columns"}
            </button>
          ) : null}
          {resource.headerActions.map((action) => (
            <ActionButton
              action={action}
              key={action.instanceKey ?? action.name}
              onClick={() => execute(action, [])}
              processing={actionRuntime.state.phase === "executing"}
              registries={registries}
              renderers={renderers}
              rows={[]}
            />
          ))}
        </div>
        {viewEditorOpen ? <form className="mt-3 flex flex-wrap items-end gap-2 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface-muted) p-3" data-slot="view-editor" onSubmit={(event) => { event.preventDefault(); savePersonalView(); }}>
          <label className="min-w-40 flex-1 text-sm font-medium text-(--inlay-text)"><span className="mb-1 block">View key</span><input aria-label="View key" className={`${controlClass} w-full`} onChange={(event) => setViewNameDraft(event.target.value)} pattern="[a-z][a-z0-9_-]{0,63}" required value={viewNameDraft} /></label>
          <label className="min-w-40 flex-1 text-sm font-medium text-(--inlay-text)"><span className="mb-1 block">Label</span><input aria-label="View label" className={`${controlClass} w-full`} onChange={(event) => setViewLabelDraft(event.target.value)} required value={viewLabelDraft} /></label>
          <label className="min-w-52 flex-1 text-sm font-medium text-(--inlay-text)"><span className="mb-1 block">Description</span><input aria-label="View description" className={`${controlClass} w-full`} onChange={(event) => setViewDescriptionDraft(event.target.value)} value={viewDescriptionDraft} /></label>
          <button className={primaryButton} disabled={viewSaving || viewNameDraft.trim() === "" || viewLabelDraft.trim() === ""} type="submit">{viewSaving ? "Saving…" : "Save"}</button>
          <button className={secondaryButton} disabled={viewSaving} onClick={() => setViewEditorOpen(false)} type="button">Cancel</button>
          {viewError ? <p className="basis-full text-sm text-(--inlay-danger)" role="alert">{viewError}</p> : null}
        </form> : null}
      </div>

      {reorderError ? (
        <div className="mt-3 flex items-start justify-between gap-3 rounded-(--inlay-radius) border border-(--inlay-danger)/30 bg-(--inlay-danger-surface) px-3 py-2.5 text-sm text-(--inlay-danger)" data-slot="reorder-error" role="alert">
          <span>{reorderError}</span>
          <button aria-label="Dismiss reorder error" className="shrink-0 rounded px-1 hover:bg-(--inlay-danger)/10" onClick={() => setReorderError(null)} type="button">×</button>
        </div>
      ) : null}
      {exportError ? (
        <div className="mt-3 flex items-start justify-between gap-3 rounded-(--inlay-radius) border border-(--inlay-danger)/30 bg-(--inlay-danger-surface) px-3 py-2.5 text-sm text-(--inlay-danger)" data-slot="export-error" role="alert">
          <span>{exportError}</span>
          <button aria-label="Dismiss export error" className="shrink-0 rounded px-1 hover:bg-(--inlay-danger)/10" onClick={() => setExportError(null)} type="button">×</button>
        </div>
      ) : null}
      {exportQueued ? (
        <div className="mt-3 flex items-start justify-between gap-3 rounded-(--inlay-radius) border border-(--inlay-accent)/25 bg-(--inlay-accent)/8 px-3 py-2.5 text-sm text-(--inlay-text)" data-slot="export-queued" role="status">
          <span>{exportQueued}</span>
          <button aria-label="Dismiss export status" className="shrink-0 rounded px-1 hover:bg-(--inlay-hover)" onClick={() => setExportQueued(null)} type="button">×</button>
        </div>
      ) : null}

      {columnsOpen ? (
        <div
          className={resource.columnManager?.layout === "modal" ? "fixed inset-0 z-50 grid place-items-center bg-(--inlay-scrim) p-4 backdrop-blur-[1px]" : ""}
          data-slot={resource.columnManager?.layout === "modal" ? "column-manager-overlay" : undefined}
          onMouseDown={(event) => {
            if (resource.columnManager?.layout === "modal" && event.target === event.currentTarget) closeColumns();
          }}
        >
        <div
          aria-label={resource.columnManager?.layout === "modal" ? undefined : "Table columns"}
          aria-labelledby={resource.columnManager?.layout === "modal" ? `${resource.name}-columns-heading` : undefined}
          aria-modal={resource.columnManager?.layout === "modal" ? true : undefined}
          className={resource.columnManager?.layout === "modal"
            ? "max-h-[min(42rem,calc(100dvh-2rem))] w-full max-w-3xl overflow-y-auto rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-5 shadow-2xl"
            : "mt-4 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-4 shadow-xs"}
          data-slot="column-manager"
          id={`${resource.name}-columns`}
          onKeyDown={(event) => {
            if (event.key === "Escape" && resource.columnManager?.layout === "modal") closeColumns();
          }}
          role={resource.columnManager?.layout === "modal" ? "dialog" : "region"}
        >
          <div className="mb-4 flex items-center justify-between gap-3">
            <h3 className="text-base font-semibold text-(--inlay-text)" id={`${resource.name}-columns-heading`}>Manage columns</h3>
            <div className="flex items-center gap-2">
              {(resource.columnManager?.resetActionPosition ?? "header") === "header" ? <button className={secondaryButton} onClick={resetColumns} type="button">Reset columns</button> : null}
              {resource.columnManager?.layout === "modal" ? <button aria-label="Close column manager" autoFocus className={`${secondaryButton} min-w-9 px-2`} onClick={closeColumns} type="button">×</button> : null}
            </div>
          </div>
          <div className={`grid gap-2 ${columnManagerGridClass(resource.columnManager?.columns ?? 1)}`}>
            {draftColumnOrder.map((name, index) => {
              const column = columnsByName.get(name);
              if (!column || (!column.toggleable && !resource.columnManager?.reorderable)) return null;
              return <div className="flex items-center gap-2 text-sm" key={column.name}>
                {column.toggleable ? <label className="flex min-w-0 flex-1 items-center gap-2">
                  <input checked={draftColumnVisibility[column.name] ?? column.visible} className="size-4 accent-(--inlay-accent)" onChange={(event) => changeColumnVisibility(column.name, event.target.checked)} type="checkbox" />
                  <span className="truncate">{column.label}</span>
                </label> : <span className="min-w-0 flex-1 truncate">{column.label}</span>}
                {resource.columnManager?.reorderable ? <span className="flex gap-1">
                  <button aria-label={`Move ${column.label} up`} className={secondaryButton} disabled={index === 0} onClick={() => moveColumn(column.name, -1)} type="button">↑</button>
                  <button aria-label={`Move ${column.label} down`} className={secondaryButton} disabled={index === draftColumnOrder.length - 1} onClick={() => moveColumn(column.name, 1)} type="button">↓</button>
                </span> : null}
              </div>;
            })}
          </div>
          {resource.columnManager?.deferred || resource.columnManager?.resetActionPosition === "footer" ? (
            <div className="mt-4 flex items-center justify-between gap-3 border-t border-(--inlay-border) pt-4">
              {resource.columnManager?.resetActionPosition === "footer" ? <button className={secondaryButton} onClick={resetColumns} type="button">Reset columns</button> : <span />}
              {resource.columnManager?.deferred ? <button
                className={primaryButton}
                onClick={() => { commitColumns(draftColumnVisibility, draftColumnOrder); setColumnsOpen(false); }}
                type="button"
              >
                Apply columns
              </button> : null}
            </div>
          ) : null}
        </div>
        </div>
      ) : null}

      {filtersLayout === "above-content" || ((filtersLayout === "dropdown" || filtersLayout === "above-content-collapsible" || filtersLayout === "modal") && filtersOpen) ? filtersPanel : null}

      {resource.aggregates?.length ? (
        <div
          className={`mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 ${classNames?.aggregates ?? ""}`}
          data-slot="aggregates"
        >
          {resource.aggregates.map((aggregate) => (
            <div
              className="rounded-(--inlay-radius) bg-(--inlay-surface) p-3 ring-1 ring-(--inlay-border)"
              data-slot="aggregate"
              key={aggregate.name}
            >
              <p className="text-sm text-(--inlay-muted)">{aggregate.label}</p>
              <p className="text-lg font-semibold">{summaryValue(aggregate)}</p>
            </div>
          ))}
        </div>
      ) : null}

      {indicators.length ? (
        <div
          className={`mt-3 flex flex-wrap gap-2 ${classNames?.filterIndicators ?? ""}`}
          data-slot="filter-indicators"
        >
          {indicators.map((indicator) => (
            <span
              className={`inline-flex items-center gap-1 rounded-full bg-(--inlay-surface-muted) py-1 pl-2.5 pr-1 text-sm text-(--inlay-muted) ring-1 ring-(--inlay-border) ${classNames?.filterIndicator ?? ""}`}
              data-slot="filter-indicator"
              key={indicator.field}
            >
              {indicator.label}
              <button
                aria-label={`Remove ${indicator.label}`}
                className="grid size-5 place-items-center rounded-full hover:bg-(--inlay-hover) hover:text-(--inlay-foreground)"
                onClick={() => removeIndicator(indicator)}
                type="button"
              >
                <span aria-hidden="true">×</span>
              </button>
            </span>
          ))}
        </div>
      ) : null}

      {resource.selectable ? <p aria-live="polite" className="sr-only" id={`${resource.name}-selection-status`}>{selectedCount} records selected{selectionMaximum !== null ? `; maximum ${selectionMaximum}` : ''}.</p> : null}
      {!allMatchingSelected && allSelectableSelected && resource.selection?.selectAllMode === 'query' && (resource.selection.total ?? 0) > selectableKeys.length ? <div className="mt-3 flex items-center justify-center gap-2 rounded-(--inlay-radius) bg-(--inlay-surface-muted) px-3 py-2 text-sm"><span>All {selectableKeys.length} records on this page are selected.</span><button className="font-semibold text-(--inlay-accent) underline underline-offset-2" disabled={selectionMaximum !== null && (resource.selection.total ?? 0) > selectionMaximum} onClick={() => { setAllMatchingSelected(true); setExcluded([]); setSelected([]); }} type="button">Select all {resource.selection.total} matching records</button></div> : null}
      {selectedCount > 0 && resource.bulkActions.length ? (
        <div
          className={`mt-4 flex flex-wrap items-center gap-2 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface-muted) px-3 py-2.5 ${classNames?.bulkActions ?? ""}`}
          data-slot="bulk-actions"
        >
          <p className="mr-1 text-sm font-medium tabular-nums text-(--inlay-muted)">
            {selectedCount} selected
          </p>
          {resource.bulkActions.map((definition) => isActionGroup(definition)
            ? <BulkActionGroupMenu count={selectedCount} definition={definition} execute={execute} key={definition.instanceKey ?? definition.name} processing={actionRuntime.state.phase === "executing"} registries={registries} renderers={renderers} rows={selectedRows} />
            : <BulkActionControl action={definition} count={selectedCount} execute={execute} key={definition.instanceKey ?? definition.name} processing={actionRuntime.state.phase === "executing"} registries={registries} renderers={renderers} rows={selectedRows} />)}
          <button className={`${secondaryButton} min-h-8 px-2.5 py-1 text-sm`} onClick={() => { setSelected([]); setAllMatchingSelected(false); setExcluded([]); }} type="button">Clear selection</button>
        </div>
      ) : null}

      <div
        className={`-mx-4 -my-2 mt-4 overflow-x-auto whitespace-nowrap sm:-mx-6 lg:-mx-8 ${classNames?.tableShell ?? ""}`}
        data-slot="table-scroll"
      >
        <div className="inline-block min-w-full px-4 py-2 align-middle sm:px-6 lg:px-8">
          <table
            className={`${gridLayout || customLayout ? "block" : stackedLayout ? "block sm:table" : `${fixedTableLayout ? "table-fixed" : "table-auto"} w-max min-w-full`} border-separate border-spacing-0 ${classNames?.table ?? ""}`}
            data-slot="table"
          >
            <thead
              className={`${gridLayout || customLayout ? "hidden" : stackedLayout ? "hidden bg-(--inlay-surface-muted) sm:table-header-group" : "bg-(--inlay-surface-muted)"} ${classNames?.head ?? ""}`}
              data-slot="table-head"
            >
              <tr>
                {actionsHeaderAt('before-cells')}
                {reordering ? <th className="w-32 border-b border-(--inlay-border) px-2 py-2.5" rowSpan={hasColumnGroups ? 2 : undefined}><span className="sr-only">Reorder controls</span></th> : null}
                {resource.selectable ? (
                  <th className="w-12 border-b border-(--inlay-border) px-4 py-2.5" rowSpan={hasColumnGroups ? 2 : undefined}>
                    <input
                      aria-label="Select all rows"
                      aria-describedby={`${resource.name}-selection-status`}
                      checked={allSelectableSelected}
                      className="size-5 rounded accent-(--inlay-accent) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent) sm:size-4"
                      disabled={selectableKeys.length === 0}
                      onChange={(event) => { setAllMatchingSelected(false); setExcluded([]); setSelected(event.target.checked ? selectAllKeys : []); }}
                      ref={selectAllRef}
                      type="checkbox"
                    />
                  </th>
                ) : null}
                {actionsHeaderAt('before-columns')}
                {hasColumnGroups ? headerSegments.map((segment, index) => segment.group ? <th className={`border-b border-(--inlay-border) px-4 py-2.5 text-xs font-semibold tracking-wide text-(--inlay-muted) uppercase ${alignmentClass(segment.group.alignment)} ${segment.group.wrapHeader ? 'whitespace-normal' : 'whitespace-nowrap'}`} colSpan={segment.columns.length} key={`${segment.group.label}-${index}`} scope="colgroup" title={segment.group.tooltip ?? undefined}>{segment.group.label}</th> : <ColumnHeaderCell column={segment.columns[0]} key={segment.columns[0].name} onQueryChange={changeQuery} query={query} rowSpan={2} searchDebounce={resource.searchDebounce} searchOnBlur={resource.searchOnBlur} />) : columns.map((column) => <ColumnHeaderCell column={column} key={column.name} onQueryChange={changeQuery} query={query} searchDebounce={resource.searchDebounce} searchOnBlur={resource.searchOnBlur} />)}
                {actionsHeaderAt('after-columns')}
              </tr>
              {hasColumnGroups ? <tr>{headerSegments.flatMap((segment) => segment.group ? segment.columns.map((column) => <ColumnHeaderCell column={column} key={column.name} onQueryChange={changeQuery} query={query} searchDebounce={resource.searchDebounce} searchOnBlur={resource.searchOnBlur} />) : [])}</tr> : null}
            </thead>
            <tbody className={gridLayout ? `grid gap-4 p-4 ${contentGridClass(resource.layout?.contentGrid ?? {})}` : customLayout ? "grid gap-3 p-3" : stackedLayout ? "block p-3 sm:table-row-group sm:p-0" : undefined}>
              {resource.grouping?.active ? resource.grouping.buckets.map((bucket) => {
                const collapsed = collapsedGroups.includes(bucket.key);
                const bucketRows = orderedRows.filter((row) => bucket.rowKeys.includes(String(keyFor(row))));
                return <Fragment key={bucket.key}>
                  <tr className="bg-(--inlay-surface-muted)" data-slot="group-header"><th className="whitespace-normal px-4 py-3 text-left" colSpan={columns.length + (reordering ? 1 : 0) + (resource.selectable ? 1 : 0) + (resource.actions.length ? 1 : 0)} scope="rowgroup">
                    <button className="flex w-full items-start justify-between gap-4 text-left" disabled={!resource.grouping?.active?.collapsible} onClick={() => setCollapsedGroups(collapsed ? collapsedGroups.filter((key) => key !== bucket.key) : [...collapsedGroups, bucket.key])} type="button">
                      <span><span className="font-semibold text-(--inlay-text)">{bucket.title}</span>{bucket.description ? <span className="mt-0.5 block text-sm font-normal text-(--inlay-muted)">{bucket.description}</span> : null}</span>
                      <span className="text-sm font-normal text-(--inlay-muted)">{summaryText(bucket.summaries)}{resource.grouping?.active?.collapsible ? ` ${collapsed ? "▾" : "▴"}` : ""}</span>
                    </button>
                  </th></tr>
                  {!collapsed && !resource.grouping?.groupsOnly ? bucketRows.map(renderRow) : null}
                </Fragment>;
              }) : orderedRows.map(renderRow)}
            </tbody>
            {hasSummaryRows ? <tfoot className="bg-(--inlay-surface-muted)" data-slot="summaries"><tr>
              {reordering ? <td /> : null}
              {resource.selectable ? <td /> : null}
              {columns.map((column) => <td className={`min-w-0 whitespace-normal border-t border-(--inlay-border) px-3 py-3 text-sm lg:px-4 ${alignmentClass(column.alignment)}`} key={column.name}>{summaryItems(summaryQuery[column.name], summaryPage[column.name], !summaryQueryVisible && summaryPageVisible)}</td>)}
              {resource.actions.length ? <td /> : null}
            </tr></tfoot> : null}
          </table>
          {!isLoading && !resource.rows.length ? (
            <div className="py-12 text-center" data-slot="empty-state">
              <h3 className="font-semibold text-(--inlay-text)">
                {resource.emptyState.heading}
              </h3>
              {resource.emptyState.description ? (
                <p className="mt-1 text-base text-(--inlay-muted) sm:text-sm">
                  {resource.emptyState.description}
                </p>
              ) : null}
              {resource.emptyState.actions?.length ? (
                <div className="mt-4 flex flex-wrap justify-center gap-2" data-slot="empty-state-actions">
                  {resource.emptyState.actions.map((action) => (
                    <ActionButton
                      action={action}
                      key={action.instanceKey ?? action.name}
                      onClick={() => execute(action, [])}
                      processing={actionRuntime.state.phase === "executing"}
                      registries={registries}
                      renderers={renderers}
                      rows={[]}
                    />
                  ))}
                </div>
              ) : null}
            </div>
          ) : null}
          {isLoading ? (
            <p
              className="py-12 text-center text-base text-(--inlay-muted) sm:text-sm"
              role="status"
            >
              Loading…
            </p>
          ) : null}
        </div>
      </div>
      {filtersLayout === "below-content" ? filtersPanel : null}
      {resource.pagination && (!reordering || resource.reordering?.paginatedWhileReordering) ? (
        <Pagination
          className={classNames?.pagination}
          extremeLinks={resource.extremePaginationLinks}
          pagination={resource.pagination}
          setQuery={changeQuery}
        />
      ) : null}
      <ActionDialog runtime={actionRuntime}>{dialogRuntime => <ActionForm runtime={dialogRuntime} />}</ActionDialog>
    </section>
  );
}

function ColumnLayoutRenderer({ component, row, onChange, renderers, registries }: { component: Column | ColumnLayout; row: TableRow; onChange: (column: Column, value: unknown) => void; renderers?: TableRenderers; registries?: TableRendererRegistries }) {
  const [collapsed, setCollapsed] = useState('schema' in component && component.type === 'panel-layout' ? (component.collapsed ?? true) : false);
  if (!('schema' in component)) return <div className={`${responsiveColumnClass(component)} ${component.grow === false ? 'grow-0' : 'min-w-0 grow'}`} data-column={component.name}><Cell column={component} onChange={(value) => onChange(component, value)} registries={registries} renderers={renderers} row={row} /></div>;
  const responsive = responsiveLayoutClass(component);
  if (component.type === 'panel-layout') return <div className={`rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface-muted) p-3 ${responsive}`} data-layout="panel">
    {component.collapsible ? <button aria-expanded={!collapsed} className="mb-2 inline-flex min-h-8 items-center gap-2 rounded-md px-2 text-sm font-medium hover:bg-(--inlay-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" onClick={() => setCollapsed(!collapsed)} type="button">{collapsed ? 'Show details' : 'Hide details'} <span aria-hidden="true">{collapsed ? '▾' : '▴'}</span></button> : null}
    {!collapsed ? <div className="grid gap-2">{component.schema.map((child, index) => <ColumnLayoutRenderer component={child} key={index} onChange={onChange} registries={registries} renderers={renderers} row={row} />)}</div> : null}
  </div>;
  const classes = component.type === 'split-layout'
    ? `flex flex-col gap-3 ${splitFromClass(component.from)}`
    : `flex flex-col ${stackAlignmentClass(component.alignment)} ${stackSpaceClass(component.space)}`;
  return <div className={`${classes} ${responsive}`} data-layout={component.type === 'split-layout' ? 'split' : 'stack'}>{component.schema.map((child, index) => <ColumnLayoutRenderer component={child} key={index} onChange={onChange} registries={registries} renderers={renderers} row={row} />)}</div>;
}

function Cell({
  column,
  row,
  onChange,
  renderers,
  registries,
  disabled = false,
  error,
}: {
  column: Column;
  row: TableRow;
  onChange: (value: unknown) => void;
  renderers?: TableRenderers;
  registries?: TableRendererRegistries;
  disabled?: boolean;
  error?: string | null;
}) {
  const presentation = ((row.__inlay as { columns?: Record<string, CellPresentation> } | undefined)?.columns?.[column.name]);
  const raw = presentation ? presentation.state : getAtPath(row, column.name);
  const hasFormattedState = presentation !== undefined && Object.prototype.hasOwnProperty.call(presentation, 'formattedState');
  const displayRaw = hasFormattedState ? presentation?.formattedState : raw;
  const value = format(displayRaw, column, hasFormattedState);
  const copyable = presentation?.copyable ?? column.copyable;
  const copyMessage = presentation?.copyMessage ?? column.copyMessage ?? "Copied";
  const copyMessageDuration = presentation?.copyMessageDuration ?? column.copyMessageDuration ?? 2000;
  const copyValue = presentation && Object.prototype.hasOwnProperty.call(presentation, "copyableState") ? presentation.copyableState : raw;
  const [copied, setCopied] = useState(false);
  const [listExpanded, setListExpanded] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(String(copyValue ?? ''));
      setCopied(true);
      window.setTimeout(() => setCopied(false), copyMessageDuration);
    } catch {
      setCopied(false);
    }
  };
  const Renderer =
    renderers?.column?.[column.type] ?? registries?.column?.get(column.type);
  if (Renderer)
    return (
      <Renderer
        column={column}
        onChange={onChange}
        rawValue={raw}
        row={row}
        value={value}
        disabled={disabled}
        error={error}
      />
    );
  if (column.type === "image-column") {
    const fallbackUrl = presentation && Object.prototype.hasOwnProperty.call(presentation, "fallbackUrl") ? presentation.fallbackUrl : column.fallbackUrl;
    const imageAlt = presentation?.alt ?? column.alt ?? column.label;
    const circular = presentation?.circular ?? column.circular;
    const square = presentation?.square ?? column.square;
    const stacked = presentation?.stacked ?? column.stacked;
    const imageSize = presentation?.size ?? column.size ?? 40;
    const imageWidth = presentation?.width ?? column.width ?? imageSize;
    const imageHeight = presentation?.height ?? column.height ?? imageSize;
    const ring = presentation?.ring ?? column.ring ?? 3;
    const overlap = presentation?.overlap ?? column.overlap ?? 4;
    const imageLimit = presentation?.limit ?? column.limit;
    const wrapImages = presentation?.wrap ?? column.wrap;
    const showRemaining = presentation?.limitedRemainingText ?? column.limitedRemainingText;
    const source = Array.isArray(raw) ? raw : [raw ?? fallbackUrl];
    const images = source.map(String).filter(url => url !== '' && isSafeUrl(url));
    const visible = imageLimit ? images.slice(0, imageLimit) : images;
    const remaining = images.length - visible.length;
    return <div aria-label={column.label} className={`flex items-center ${wrapImages ? 'flex-wrap' : 'flex-nowrap'} ${stacked ? 'isolate' : 'gap-2'}`} role={images.length > 1 ? 'group' : undefined}>
      {visible.map((url, index) => <img alt={images.length > 1 ? `${imageAlt} ${index + 1}` : imageAlt} className={`object-cover ${circular ? 'rounded-full' : square ? 'rounded-none' : 'rounded-md'}`} height={imageHeight} key={`${url}-${index}`} loading="lazy" src={url} style={{ boxShadow: stacked && ring > 0 ? `0 0 0 ${ring}px var(--inlay-surface)` : undefined, marginInlineStart: stacked && index > 0 ? `${-overlap * 2}px` : undefined, zIndex: visible.length - index }} width={square ? imageHeight : imageWidth} />)}
      {remaining > 0 && showRemaining ? <span aria-label={`${remaining} more images`} className="text-xs font-medium text-(--inlay-muted)">+{remaining}</span> : null}
    </div>;
  }
  if (column.type === "color-column")
    return (
      <span
        aria-label={`${column.label}: ${value}`}
        className="inline-block size-6 rounded-sm ring-1 ring-(--inlay-border)"
        style={{ backgroundColor: String(raw ?? "transparent") }}
      />
    );
  if (["boolean-column", "icon-column"].includes(column.type))
    return (
      <span aria-label={Boolean(raw) ? "Yes" : "No"} className={Boolean(raw) ? 'text-(--inlay-success)' : 'text-(--inlay-danger)'}>
        <NamedIcon fallback={Boolean(raw) ? '✓' : '×'} name={Boolean(raw) ? (column.trueIcon ?? 'check') : (column.falseIcon ?? 'x')} registries={registries} renderers={renderers} />
      </span>
    );
  if (column.type === "badge-column")
    return (
      <span
        className={`rounded-full px-2 py-1 text-base font-medium sm:text-sm ${badgeColor(column.colors?.[String(raw)])}`}
      >
        {String(column.labels?.[String(raw)] ?? value)}
      </span>
    );
  if (column.type === "select-column")
    return (
      <select
        aria-invalid={Boolean(error)}
        aria-label={`${column.label} for ${row.id}`}
        className={controlClass}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value)}
        value={String(raw ?? "")}
      >
        {column.options?.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    );
  if (["toggle-column", "checkbox-column"].includes(column.type))
    return (
      <input
        aria-invalid={Boolean(error)}
        aria-label={`${column.label} for ${row.id}`}
        checked={Boolean(raw)}
        className="size-5 rounded accent-(--inlay-accent) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent) sm:size-4"
        disabled={disabled}
        onChange={(event) => onChange(event.target.checked)}
        type="checkbox"
      />
    );
  if (column.type === "text-input-column")
    return (
      <input
        aria-invalid={Boolean(error)}
        aria-label={`${column.label} for ${row.id}`}
        className={controlClass}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value)}
        type={column.inputType ?? "text"}
        value={String(raw ?? "")}
      />
    );
  const textColor = presentation?.color ?? column.color;
  const textIcon = presentation?.icon ?? column.icon;
  const textIconColor = presentation?.iconColor ?? column.iconColor;
  const badge = presentation?.badge ?? column.badge;
  const bulleted = presentation?.bulleted ?? column.bulleted;
  const listWithLineBreaks = presentation?.listWithLineBreaks ?? column.listWithLineBreaks;
  const listLimit = presentation?.listLimit ?? column.listLimit;
  const expandableLimitedList = presentation?.expandableLimitedList ?? column.expandableLimitedList;
  const wrap = presentation?.wrap ?? column.wrap;
  const richText = presentation?.html ?? column.html ?? presentation?.markdown ?? column.markdown;
  const characterLimit = presentation?.limit ?? column.limit;
  const characterLimitEnd = presentation?.limitEnd ?? column.limitEnd ?? "…";
  const wordLimit = presentation?.words ?? column.words;
  const wordLimitEnd = presentation?.wordsEnd ?? column.wordsEnd ?? "…";
  const prefix = presentation?.prefix ?? column.prefix;
  const suffix = presentation?.suffix ?? column.suffix;
  const textSize = presentation?.textSize ?? column.textSize;
  const lineClamp = presentation?.lineClamp ?? column.lineClamp;
  const textClasses = `${textSizeClass(textSize)} ${textWeightClass(column.fontWeight)} ${textFamilyClass(column.fontFamily)} ${semanticTextClass(textColor)}`;
  const textStyle = semanticTextStyle(textColor, badge);
  const icon = textIcon ? <NamedIcon className={`shrink-0 ${semanticTextClass(textIconColor)}`} fallback="◆" name={textIcon} registries={registries} renderers={renderers} style={semanticTextStyle(textIconColor, false)} /> : null;
  if (Array.isArray(displayRaw) && listWithLineBreaks) {
    const allItems = displayRaw.map(item => format(item, column, hasFormattedState));
    const shownItems = listLimit && !listExpanded ? allItems.slice(0, listLimit) : allItems;
    const remaining = allItems.length - shownItems.length;
    return <span className="inline-grid min-w-0 gap-1" title={presentation?.tooltip ?? column.tooltip ?? undefined}>
      {(presentation?.description ?? column.description) && column.descriptionPosition === 'above' ? <span className="text-xs text-(--inlay-muted)">{presentation?.description ?? column.description}</span> : null}
      <ul className={bulleted ? 'list-inside list-disc space-y-0.5' : 'grid list-none gap-0.5'}>{shownItems.map((item, index) => <li className={`${bulleted ? '' : 'flex items-start gap-1.5'} ${textClasses}`} data-color={textColor ?? undefined} key={index} style={textStyle}><span className={`${badge ? `${textBadgeClass(textColor)} rounded-full px-2 py-0.5` : ''} inline-flex items-start gap-1.5`}>{column.iconPosition !== 'after' ? icon : null}<span className={wrap ? 'whitespace-normal' : 'whitespace-nowrap'}>{String(item)}</span>{column.iconPosition === 'after' ? icon : null}</span></li>)}</ul>
      {listLimit && expandableLimitedList && allItems.length > listLimit ? <button aria-expanded={listExpanded} className="justify-self-start text-xs font-medium text-(--inlay-accent) hover:underline" onClick={() => setListExpanded(current => !current)} type="button">{listExpanded ? 'Show less' : `Show ${remaining} more`}</button> : remaining > 0 ? <span className="text-xs text-(--inlay-muted)">+{remaining} more</span> : null}
      {(presentation?.description ?? column.description) && column.descriptionPosition !== 'above' ? <span className="text-xs text-(--inlay-muted)">{presentation?.description ?? column.description}</span> : null}
    </span>;
  }
  const truncated = wordLimit
    ? String(value ?? "").split(/\s+/).slice(0, wordLimit).join(" ") +
      (String(value ?? "").split(/\s+/).length > wordLimit ? wordLimitEnd : "")
    : characterLimit && String(value).length > characterLimit
      ? `${String(value).slice(0, characterLimit)}${characterLimitEnd}`
      : String(value ?? "");
  const content = value === null || value === undefined || value === ""
    ? truncated
    : `${prefix ?? ""}${truncated}${suffix ?? ""}`;
  const hrefTemplate = presentation && Object.prototype.hasOwnProperty.call(presentation, 'url') ? presentation.url : column.url;
  const href = hrefTemplate ? interpolate(hrefTemplate, row) : null;
  const openUrlInNewTab = presentation?.openUrlInNewTab ?? column.openUrlInNewTab;
  const empty = displayRaw === null || displayRaw === undefined || displayRaw === '';
  const display = empty && column.placeholder ? column.placeholder : content;
  const description = presentation?.description ?? column.description;
  const tooltip = presentation?.tooltip ?? column.tooltip;
  const contentClass = `${wrap ? 'min-w-0 max-w-full whitespace-normal break-words' : 'block min-w-0 max-w-full flex-1 truncate'} ${lineClamp ? 'overflow-hidden' : ''}`;
  const contentStyle = lineClamp ? { display: '-webkit-box', WebkitBoxOrient: 'vertical', WebkitLineClamp: lineClamp } as CSSProperties : undefined;
  const main = isSafeUrl(href) ? (
      <a
        className={`${textColor ? 'text-inherit' : 'text-(--inlay-accent)'} ${contentClass} underline decoration-current/30 underline-offset-2 focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)`}
        href={href}
        rel={openUrlInNewTab ? "noreferrer" : undefined}
        style={contentStyle}
        target={openUrlInNewTab ? "_blank" : undefined}
      >
        {richText && !empty ? null : display}
        {richText && !empty ? <span dangerouslySetInnerHTML={{ __html: String(display) }} /> : null}
      </a>
    ) : <span className={`${empty && column.placeholder ? 'text-(--inlay-muted)' : ''} ${contentClass}`} style={contentStyle}>{richText && !empty ? <span dangerouslySetInnerHTML={{ __html: String(display) }} /> : display}</span>;
  return <span className="grid min-w-0 w-full max-w-full gap-0.5 overflow-hidden" title={tooltip ?? undefined}>
    {description && column.descriptionPosition === 'above' ? <span className="truncate text-xs text-(--inlay-muted)">{description}</span> : null}
    <span className="inline-flex min-w-0 max-w-full items-center gap-1.5 overflow-hidden"><span className={`${textClasses} ${badge ? `${textBadgeClass(textColor)} rounded-full px-2 py-0.5` : ''} inline-flex min-w-0 max-w-full flex-1 items-center gap-1.5 overflow-hidden`} data-color={textColor ?? undefined} style={textStyle}>{column.iconPosition !== 'after' ? icon : null}{main}{column.iconPosition === 'after' ? icon : null}</span>{copyable ? <button aria-label={`Copy ${column.label}`} className="shrink-0 rounded-sm p-1 text-(--inlay-muted) hover:bg-(--inlay-hover) hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" onClick={() => void copy()} title={copyMessage} type="button"><span aria-hidden="true">⎘</span></button> : null}</span>
    {description && column.descriptionPosition !== 'above' ? <span className="truncate text-xs text-(--inlay-muted)">{description}</span> : null}
    {copied ? <span aria-live="polite" className="text-xs text-(--inlay-success)" role="status">{copyMessage}</span> : null}
  </span>;
}

function actionVisible(condition: Action["visibleWhen"], row: TableRow): boolean {
  if (!condition) return true;
  if ("logic" in condition) {
    if (condition.logic === "all")
      return condition.conditions.every((child) => actionVisible(child, row));
    if (condition.logic === "any")
      return condition.conditions.some((child) => actionVisible(child, row));
    return condition.conditions.length === 1 && !actionVisible(condition.conditions[0], row);
  }
  const value = condition.path.split(".").reduce<unknown>(
    (current, segment) =>
      current && typeof current === "object"
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

function FilterControl({
  filter,
  value,
  onChange,
  classNames,
  renderers,
  registries,
}: {
  filter: Filter;
  value: unknown;
  onChange: (value: unknown) => void;
  classNames?: TableClassNames;
  renderers?: TableRenderers;
  registries?: TableRendererRegistries;
}) {
  const Renderer =
    renderers?.filter?.[filter.type] ?? registries?.filter?.get(filter.type);
  if (Renderer)
    return (
      <Renderer
        classNames={classNames}
        filter={filter}
        onChange={onChange}
        value={value}
      />
    );
  if (filter.type === 'query-builder')
    return <QueryBuilderControl filter={filter} onChange={onChange} value={value} />;
  if (filter.type === 'schema-filter') {
    const values = (value && typeof value === 'object' && !Array.isArray(value) ? value : {}) as Record<string, unknown>;
    return (
      <fieldset className={`grid gap-1.5 ${classNames?.filterGroup ?? ""}`} data-filter={filter.name} data-slot="filter-group">
        <legend className="text-sm font-medium text-(--inlay-text)" data-slot="filter-label">{filter.label}</legend>
        <SchemaRenderer
          columns={filter.formColumns ?? 1}
          errors={{}}
          liveChange={() => undefined}
          schema={(filter.schema ?? []) as never}
          update={(path, next) => onChange({ ...values, [path]: next })}
          values={values}
        />
      </fieldset>
    );
  }
  if (filter.type === "boolean-filter")
    return (
      <label
        className={`flex min-h-(--inlay-control-height) items-center gap-2 rounded-(--inlay-radius) border border-(--inlay-control-border) bg-(--inlay-surface) px-3 py-2 text-base shadow-xs transition sm:text-sm ${classNames?.filterGroup ?? ""}`}
        data-filter={filter.name}
        data-slot="filter-group"
      >
        <input
          checked={booleanValue(value)}
          className={`size-5 rounded accent-(--inlay-accent) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent) sm:size-4 ${classNames?.filterControl ?? ""}`}
          data-slot="filter-control"
          name={filter.name}
          onChange={(event) => onChange(event.target.checked)}
          type="checkbox"
        />
        {filter.label}
      </label>
    );
  if (filter.type === "select-filter" || filter.type === "ternary-filter") {
    // A searchable filter loads its options from the same authorized query the
    // table uses, so nothing is listed until the visitor asks for it.
    if (filter.remoteOptions?.endpoint) {
      return <RemoteFilterOptions classNames={classNames} filter={filter} onChange={onChange} value={value} />;
    }
    const options = filter.type === "ternary-filter"
      ? [
          { value: "", label: "All" },
          { value: "1", label: filter.trueLabel ?? "Yes" },
          { value: "0", label: filter.falseLabel ?? "No" },
        ]
      : [{ value: "", label: "All" }, ...(filter.options ?? [])];
    return (
      <label
        className={`grid min-w-0 gap-1.5 text-sm font-medium text-(--inlay-text) ${classNames?.filterGroup ?? ""}`}
        data-filter={filter.name}
        data-slot="filter-group"
      >
        <span data-slot="filter-label">{filter.label}</span>
        {filter.multiple ? <span className="min-w-0">
          <select
            className={`${controlClass} min-h-28 font-normal ${classNames?.filterControl ?? ""}`}
            data-slot="filter-control"
            multiple
            name={filter.name}
            onChange={(event) =>
              onChange(
                filter.multiple
                  ? [...event.target.selectedOptions].map(
                      (option) => option.value,
                    )
                  : event.target.value,
              )
            }
            value={
              Array.isArray(value) ? value.map(String) : []
            }
          >
            {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </span> : <Select ariaLabel={filter.label} buttonClassName={`font-normal ${classNames?.filterControl ?? ""}`} className="w-full" name={filter.name} onValueChange={onChange} options={options} value={String(value ?? "")} />}
      </label>
    );
  }
  return (
    <label
      className={`grid min-w-0 gap-1.5 text-sm font-medium text-(--inlay-text) ${classNames?.filterGroup ?? ""}`}
      data-filter={filter.name}
      data-slot="filter-group"
    >
      <span data-slot="filter-label">{filter.label}</span>
      <input
        className={`${controlClass} font-normal ${classNames?.filterControl ?? ""}`}
        data-slot="filter-control"
        name={filter.name}
        onChange={(event) => onChange(event.target.value)}
        type={
          filter.type === "date-filter"
            ? "date"
            : filter.type === "numeric-filter"
              ? "number"
              : "text"
        }
        value={String(value ?? "")}
      />
    </label>
  );
}

type ActionGroupPosition = "first" | "middle" | "last" | "single";

function ActionButton({
  action,
  rows,
  onClick,
  processing,
  renderers,
  registries,
  disabled = false,
  disabledReason,
  groupPosition,
}: {
  action: Action;
  rows: TableRow[];
  onClick: () => void;
  processing: boolean;
  renderers?: TableRenderers;
  registries?: TableRendererRegistries;
  disabled?: boolean;
  disabledReason?: string | null;
  groupPosition?: ActionGroupPosition;
}) {
  const type = action.type ?? action.name;
  const Renderer = renderers?.action?.[type] ?? registries?.action?.get(type);
  const trigger = useRef<HTMLButtonElement>(null);
  const refused = processing || disabled || Boolean(action.disabled);
  // A row action is rendered once per record. Registering the same global
  // shortcut for every copy would execute the whole visible page at once.
  const keyboardEnabled = !action.download && (rows.length === 0 || Boolean(action.bulk));
  useEffect(() => {
    if (refused || !keyboardEnabled || !action.keyBindings?.length) return;
    const listener = (event: KeyboardEvent) => {
      if (!matchesActionKeyBinding(event, action.keyBindings)) return;
      event.preventDefault();
      onClick();
    };
    document.addEventListener("keydown", listener);
    return () => document.removeEventListener("keydown", listener);
  }, [action.keyBindings, keyboardEnabled, onClick, refused]);

  if (Renderer)
    return <Renderer action={action} disabled={refused} disabledReason={disabledReason ?? null} onExecute={refused ? () => {} : onClick} rows={rows} />;

  const style = action.triggerStyle ?? "button";
  const toneSet = style === "link" ? actionLinks : style === "badge" ? actionBadges : action.outlined ? actionOutlines : actionColors;
  const tone = toneSet[action.color ?? "default"] ?? toneSet.default ?? actionColors.default;
  const size = style === "icon-button"
    ? ({ "extra-small": "size-(--inlay-button-xs-height) min-h-0 text-xs", small: "size-(--inlay-button-sm-height) min-h-0 text-sm", medium: "size-(--inlay-icon-button-size) min-h-0 text-sm", large: "size-(--inlay-button-lg-height) min-h-0 text-sm" }[action.size ?? "medium"] ?? "size-(--inlay-icon-button-size) min-h-0 text-sm")
    : style === "link"
      ? "min-h-0 p-0 text-sm"
      : style === "badge"
        ? "min-h-6 px-2 py-0.5 text-xs"
        : ({ "extra-small": "min-h-(--inlay-button-xs-height) px-2 py-1 text-xs", small: "min-h-(--inlay-button-sm-height) px-2.5 py-1 text-sm", medium: "min-h-(--inlay-button-height) px-3 py-1.5 text-sm", large: "min-h-(--inlay-button-lg-height) px-3.5 py-2 text-sm" }[action.size ?? "medium"] ?? "min-h-(--inlay-button-height) px-3 py-1.5 text-sm");
  const icon = action.icon
    ? <NamedIcon className="shrink-0" fallback="◆" name={action.icon} registries={registries} renderers={renderers} />
    : null;
  const downloadUrl = action.download && action.url
    ? interpolateActionUrl(action.url, rows[0] ?? {})
    : null;
  const content = <>
      {style === "icon-button" ? <span aria-hidden="true" className="pointer-fine:hidden absolute left-1/2 top-1/2 size-[max(100%,3rem)] -translate-1/2" /> : null}
      {action.iconPosition === "after" ? null : icon}
      {style === "icon-button" ? <span className="sr-only">{action.label}</span> : action.label}
      {action.iconPosition === "after" ? icon : null}
      {action.badge == null ? null : <span className={`${style === "icon-button" ? "absolute -right-1 -top-1 min-w-4" : "ml-1"} rounded-full border px-1.5 text-xs font-semibold ${actionBadges[action.badgeColor ?? "default"] ?? actionBadges.default}`} data-color={action.badgeColor ?? "default"} data-slot="action-badge">{action.badge}</span>}
    </>;
  const className = `${actionButtonBase} relative ${groupPosition ? groupPosition === "single" ? "rounded-(--inlay-radius)" : groupPosition === "first" ? "rounded-l-(--inlay-radius) rounded-r-none" : groupPosition === "last" ? "rounded-l-none rounded-r-(--inlay-radius)" : "rounded-none" : style === "icon-button" ? "rounded-full p-0" : style === "link" ? "rounded-sm shadow-none underline-offset-4 hover:underline" : style === "badge" ? "rounded-full shadow-none" : "rounded-(--inlay-radius)"} ${size} ${tone}`;
  if (downloadUrl && rows.length === 0) {
    return (
      <a
        aria-disabled={refused || undefined}
        aria-label={style === "icon-button" ? action.label : undefined}
        className={`${className} ${refused ? "pointer-events-none opacity-50" : ""}`}
        data-color={action.color ?? "default"}
        data-outlined={action.outlined ? "true" : undefined}
        data-size={action.size ?? "medium"}
        data-trigger-style={style}
        data-slot="action-trigger"
        download
        href={downloadUrl}
        title={disabledReason ?? action.tooltip ?? undefined}
        onClick={refused ? (event) => event.preventDefault() : undefined}
      >
        {content}
      </a>
    );
  }
  return (
    <button
      aria-disabled={refused || undefined}
      aria-keyshortcuts={keyboardEnabled ? ariaKeyShortcuts(action.keyBindings) : undefined}
      aria-label={style === "icon-button" ? action.label : undefined}
      className={className}
      data-color={action.color ?? "default"}
      data-outlined={action.outlined ? "true" : undefined}
      data-size={action.size ?? "medium"}
      data-trigger-style={style}
      data-slot="action-trigger"
      disabled={refused}
      onClick={refused ? undefined : onClick}
      ref={trigger}
      title={disabledReason ?? action.tooltip ?? undefined}
      type="button"
    >
      {content}
    </button>
  );
}

function ariaKeyShortcuts(bindings: readonly string[] | undefined): string | undefined {
  if (!bindings?.length) return undefined;
  return bindings.flatMap((binding) => {
    const value = binding.split("+").map((part) => part.length === 1 ? part.toUpperCase() : part[0]!.toUpperCase() + part.slice(1)).join("+");
    return binding.startsWith("mod+") ? [value.replace("Mod+", "Meta+"), value.replace("Mod+", "Control+")] : [value];
  }).join(" ");
}

function isActionGroup(definition: BulkActionDefinition): definition is Extract<BulkActionDefinition, { type: 'action-group' }> {
  return definition.type === 'action-group' && 'actions' in definition;
}

function selectionReason(action: Action, count: number) {
  const minimum = action.minimumSelection ?? 1;
  if (count < minimum) return `Select at least ${minimum} records.`;
  if (action.maximumSelection != null && count > action.maximumSelection) return `Select no more than ${action.maximumSelection} records.`;
  return null;
}

function BulkActionControl({ action, rows, count, execute, processing, renderers, registries, groupPosition }: { action: Action; rows: TableRow[]; count: number; execute: (action: Action, rows: TableRow[]) => void; processing: boolean; renderers?: TableRenderers; registries?: TableRendererRegistries; groupPosition?: ActionGroupPosition }) {
  const reason = selectionReason(action, count);
  return <ActionButton action={action} disabled={reason !== null} disabledReason={reason} groupPosition={groupPosition} onClick={() => execute(action, rows)} processing={processing} registries={registries} renderers={renderers} rows={rows} />;
}

function BulkActionGroupItems({ definition, rows, count, execute, processing, renderers, registries, grouped = false }: { definition: Extract<BulkActionDefinition, { type: 'action-group' }>; rows: TableRow[]; count: number; execute: (action: Action, rows: TableRow[]) => void; processing: boolean; renderers?: TableRenderers; registries?: TableRendererRegistries; grouped?: boolean }) {
  return <>
    {definition.actions.map((action, index) => {
      const groupPosition: ActionGroupPosition | undefined = grouped
        ? definition.actions.length === 1 ? "single" : index === 0 ? "first" : index === definition.actions.length - 1 ? "last" : "middle"
        : undefined;
      return isActionGroup(action)
        ? <BulkActionGroupMenu count={count} definition={action} execute={execute} groupPosition={groupPosition} key={action.instanceKey ?? action.name} nested processing={processing} registries={registries} renderers={renderers} rows={rows} />
        : <BulkActionControl action={action} count={count} execute={execute} groupPosition={groupPosition} key={action.instanceKey ?? action.name} processing={processing} registries={registries} renderers={renderers} rows={rows} />;
    })}
  </>;
}

function BulkActionGroupMenu({ definition, rows, count, execute, processing, renderers, registries, nested = false, groupPosition }: { definition: Extract<BulkActionDefinition, { type: 'action-group' }>; rows: TableRow[]; count: number; execute: (action: Action, rows: TableRow[]) => void; processing: boolean; renderers?: TableRenderers; registries?: TableRendererRegistries; nested?: boolean; groupPosition?: ActionGroupPosition }) {
  const details = useRef<HTMLDetailsElement>(null);
  const refused = processing || Boolean(definition.disabled);
  useEffect(() => {
    if (refused || !definition.keyBindings?.length) return;
    const listener = (event: KeyboardEvent) => {
      if (!matchesActionKeyBinding(event, definition.keyBindings)) return;
      event.preventDefault();
      if (details.current) details.current.open = !details.current.open;
    };
    document.addEventListener("keydown", listener);
    return () => document.removeEventListener("keydown", listener);
  }, [definition.keyBindings, refused]);

  const style = definition.triggerStyle ?? "button";
  const toneSet = style === "link" ? actionLinks : style === "badge" ? actionBadges : definition.outlined ? actionOutlines : actionColors;
  const tone = toneSet[definition.color ?? "default"] ?? toneSet.default ?? actionColors.default;
  const size = style === "icon-button"
    ? ({ "extra-small": "size-(--inlay-button-xs-height) min-h-0 text-xs", small: "size-(--inlay-button-sm-height) min-h-0 text-sm", medium: "size-(--inlay-icon-button-size) min-h-0 text-sm", large: "size-(--inlay-button-lg-height) min-h-0 text-sm" }[definition.size ?? "medium"] ?? "size-(--inlay-icon-button-size) min-h-0 text-sm")
    : style === "link" ? "min-h-0 p-0 text-sm"
      : style === "badge" ? "min-h-6 px-2 py-0.5 text-xs"
        : ({ "extra-small": "min-h-(--inlay-button-xs-height) px-2 py-1 text-xs", small: "min-h-(--inlay-button-sm-height) px-2.5 py-1 text-sm", medium: "min-h-(--inlay-button-height) px-3 py-1.5 text-sm", large: "min-h-(--inlay-button-lg-height) px-3.5 py-2 text-sm" }[definition.size ?? "medium"] ?? "min-h-(--inlay-button-height) px-3 py-1.5 text-sm");
  const icon = definition.icon ? <NamedIcon fallback="◆" name={definition.icon} registries={registries} renderers={renderers} /> : null;
  const placement = actionGroupPlacements[definition.dropdownPlacement ?? "top-start"] ?? actionGroupPlacements["top-start"];
  const width = actionGroupWidths[definition.dropdownWidth ?? "sm"] ?? actionGroupWidths.sm;

  if (definition.buttonGroup) {
    return <div aria-label={definition.label} className="inline-flex max-w-full -space-x-px overflow-x-auto" data-slot="action-button-group" role="group">
      <BulkActionGroupItems count={count} definition={definition} execute={execute} grouped processing={processing} registries={registries} renderers={renderers} rows={rows} />
    </div>;
  }

  if (definition.dropdown === false) {
    return <div className={`${nested ? "mt-1 border-t border-(--inlay-border) pt-1" : "flex flex-wrap items-center gap-1"}`} data-slot="action-group-section" role={nested ? "group" : undefined}>
      {nested ? <span className="px-2 py-1 text-xs font-semibold uppercase tracking-wide text-(--inlay-muted)">{definition.label}</span> : null}
      <BulkActionGroupItems count={count} definition={definition} execute={execute} processing={processing} registries={registries} renderers={renderers} rows={rows} />
    </div>;
  }

  return <details className="group relative" data-slot="bulk-action-group" ref={details}>
    <summary
      aria-disabled={refused || undefined}
      aria-keyshortcuts={ariaKeyShortcuts(definition.keyBindings)}
      aria-label={style === "icon-button" ? definition.label : undefined}
      className={`${actionButtonBase} relative cursor-pointer list-none marker:hidden ${nested && !groupPosition ? "w-full justify-between shadow-none" : ""} ${refused ? "pointer-events-none opacity-50" : ""} ${groupPosition ? groupPosition === "single" ? "rounded-(--inlay-radius)" : groupPosition === "first" ? "rounded-l-(--inlay-radius) rounded-r-none" : groupPosition === "last" ? "rounded-l-none rounded-r-(--inlay-radius)" : "rounded-none" : style === "icon-button" ? "rounded-full p-0" : style === "link" ? "rounded-sm shadow-none underline-offset-4 hover:underline" : style === "badge" ? "rounded-full shadow-none" : "rounded-(--inlay-radius)"} ${size} ${tone}`}
      data-color={definition.color}
      data-size={definition.size ?? "medium"}
      data-trigger-style={style}
      data-slot="action-trigger"
      onClick={(event) => { if (refused) event.preventDefault(); }}
      title={definition.tooltip ?? undefined}
    >
      {definition.iconPosition === "after" ? null : icon}
      {style === "icon-button" ? <span className="sr-only">{definition.label}</span> : definition.label}
      {definition.iconPosition === "after" ? icon : null}
      {style === "icon-button" ? null : <span aria-hidden="true">⌄</span>}
      {definition.badge == null ? null : <span className={`${style === "icon-button" ? "absolute -right-1 -top-1 min-w-4" : "ml-1"} rounded-full border px-1.5 text-xs font-semibold ${actionBadges[definition.badgeColor ?? "default"] ?? actionBadges.default}`} data-color={definition.badgeColor ?? "default"} data-slot="action-group-badge">{definition.badge}</span>}
    </summary>
    <div className={`absolute z-20 grid max-w-[calc(100vw-2rem)] gap-1 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-1.5 shadow-lg ${placement} ${width}`} data-placement={definition.dropdownPlacement ?? "top-start"} data-slot="action-group-menu">
      <BulkActionGroupItems count={count} definition={definition} execute={execute} processing={processing} registries={registries} renderers={renderers} rows={rows} />
    </div>
  </details>;
}

function NamedIcon({ name, fallback, className, style, renderers, registries }: { name: string; fallback: string; className?: string; style?: CSSProperties; renderers?: TableRenderers; registries?: TableRendererRegistries }) {
  const Renderer = resolveIcon<IconRenderer>(name, renderers?.icon, registries?.icon);
  const paths = tableIconPaths[name];
  return <span aria-hidden="true" className={`inline-flex size-4 shrink-0 items-center justify-center ${className ?? ''}`.trim()} data-icon={name} style={style}>{Renderer ? <Renderer name={name} /> : paths ? <svg aria-hidden="true" className="size-4" fill="none" viewBox="0 0 24 24"><>{paths.map((path) => <path d={path} key={path} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" />)}</></svg> : fallback}</span>;
}

const tableIconPaths: Record<string, string[]> = {
  funnel: ['M4 5h16l-6.5 7.2V18l-3 1v-6.8z'],
  columns: ['M5 4h14v16H5z', 'M10 4v16', 'M15 4v16'],
  'arrows-up-down': ['M8 5v14', 'm5 8 3-3 3 3', 'm5 16 3 3 3-3', 'M16 5v14', 'm13 8 3-3 3 3', 'm13 16 3 3 3-3'],
  check: ['m5 12 4 4L19 6'],
  x: ['m6 6 12 12', 'm18 6-12 12'],
};

// A cell offering several actions opens them in a menu, but each one still
// runs through the row-action boundary the single-action cell uses.
function ColumnActionGroup({ actions, column, row, execute, children }: { actions: Action[]; column: Column; row: TableRow; execute: (action: Action, rows: TableRow[]) => void; children: ReactNode }) {
  const [open, setOpen] = useState(false);

  return (
    <span className="relative grid gap-1" data-slot="column-action-group">
      <button
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label={`${column.label} actions`}
        className="w-full cursor-pointer rounded-sm text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)"
        data-slot="column-action"
        onClick={(event) => { event.stopPropagation(); setOpen((current) => !current); }}
        type="button"
      >
        {children}
      </button>
      {open ? (
        <span className="absolute left-0 top-full z-30 mt-1 grid min-w-40 gap-0.5 rounded-(--inlay-radius) bg-(--inlay-surface) p-1 shadow-lg ring-1 ring-(--inlay-border)" data-slot="column-actions" role="menu">
          {actions.map((action) => (
            <button
              className="rounded-(--inlay-radius) px-2 py-1 text-left text-sm hover:bg-(--inlay-hover)"
              key={action.instanceKey ?? action.name}
              onClick={(event) => { event.stopPropagation(); setOpen(false); execute(action, [row]); }}
              role="menuitem"
              type="button"
            >
              {action.label}
            </button>
          ))}
        </span>
      ) : null}
    </span>
  );
}

function RemoteFilterOptions({ filter, value, onChange, classNames }: { filter: Filter; value: unknown; onChange: (value: unknown) => void; classNames?: TableClassNames }) {
  const [options, setOptions] = useState<Array<{ value: string | number; label: string }>>(filter.options ?? []);
  const [search, setSearch] = useState("");
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const load = async (term: string, values: Array<string | number> = []) => {
    const endpoint = filter.remoteOptions?.endpoint;
    if (!endpoint) return;
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set("search", term);
    values.forEach((item) => url.searchParams.append("values[]", String(item)));
    const response = await fetch(url.toString(), { credentials: "same-origin", headers: { Accept: "application/json" } });
    if (!response.ok) return;
    const payload = await response.json() as { options?: Array<{ value: string | number; label: string }> };
    if (Array.isArray(payload.options)) setOptions(payload.options);
  };
  useEffect(() => {
    if (filter.remoteOptions?.preload) void load("");
    return () => { if (timer.current) clearTimeout(timer.current); };
  }, [filter.remoteOptions?.preload]);

  return (
    <div className={`grid min-w-0 gap-1.5 text-sm font-medium text-(--inlay-text) ${classNames?.filterGroup ?? ""}`} data-filter={filter.name} data-slot="filter-group">
      <span data-slot="filter-label">{filter.label}</span>
      <input
        aria-label={`Search ${filter.label}`}
        className={controlClass}
        data-slot="filter-search"
        onChange={(event) => {
          setSearch(event.target.value);
          if (timer.current) clearTimeout(timer.current);
          const term = event.target.value;
          timer.current = setTimeout(() => void load(term), 250);
        }}
        type="search"
        value={search}
      />
      <select
        aria-label={filter.label}
        className={`${controlClass} ${filter.multiple ? "min-h-28" : ""} font-normal ${classNames?.filterControl ?? ""}`}
        data-slot="filter-control"
        multiple={filter.multiple}
        name={filter.name}
        onChange={(event) => onChange(filter.multiple ? [...event.target.selectedOptions].map(option => option.value) : event.target.value)}
        value={filter.multiple ? (Array.isArray(value) ? value.map(String) : []) : String(value ?? "")}
      >
        {filter.multiple ? null : <option value="">All</option>}
        {options.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
      </select>
    </div>
  );
}

function Pagination({
  pagination,
  setQuery,
  className,
  extremeLinks = false,
}: {
  pagination: NonNullable<TableResource["pagination"]>;
  setQuery: (query: Partial<QueryState>) => void;
  className?: string;
  extremeLinks?: boolean;
}) {
  const mode = pagination.mode ?? "length-aware";
  const current = pagination.currentPage ?? 1;
  const last = pagination.lastPage ?? current;
  const pages = mode === "length-aware" ? paginationItems(current, last) : [];
  const summary =
    pagination.total != null && pagination.from != null && pagination.to != null
      ? `Showing ${pagination.from}–${pagination.to} of ${pagination.total}`
      : mode === "cursor"
        ? "Cursor pagination"
        : pagination.from != null && pagination.to != null
          ? `Showing ${pagination.from}–${pagination.to}`
          : `Page ${current}${mode === "length-aware" ? ` of ${last}` : ""}`;
  const perPageOptions = pagination.perPageOptions ?? [];
  const chooser = perPageOptions.length > 0 ? (
    <div className="flex items-center gap-2 text-sm text-(--inlay-muted)" data-slot="pagination-per-page">
      <span>Per page</span>
      <Select
        ariaLabel="Per page"
        buttonClassName="text-sm"
        className="min-w-20"
        onValueChange={(value) => setQuery({
          perPage: value === "all" ? "all" : Number(value),
          page: 1,
          cursor: null,
        })}
        options={perPageOptions.map((option) => ({ value: String(option), label: option === "all" ? "All" : String(option) }))}
        value={String(pagination.perPage ?? pagination.defaultPerPage ?? "")}
      />
    </div>
  ) : null;
  if (mode === "none") {
    return (
      <nav
        aria-label="Pagination"
        className={`mt-4 flex flex-col gap-3 border-t border-(--inlay-border) pt-4 sm:flex-row sm:items-center sm:justify-between ${className ?? ""}`}
        data-slot="pagination"
      >
        <p className="text-sm tabular-nums text-(--inlay-muted)">{summary}</p>
        {chooser}
      </nav>
    );
  }
  return (
    <nav
      aria-label="Pagination"
      className={`mt-4 flex flex-col gap-3 border-t border-(--inlay-border) pt-4 sm:flex-row sm:items-center sm:justify-between ${className ?? ""}`}
      data-slot="pagination"
    >
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <p className="text-sm tabular-nums text-(--inlay-muted)">{summary}</p>
        {chooser}
      </div>
      <div className="flex items-center justify-between gap-2 sm:justify-end">
      <button
        className={secondaryButton}
        disabled={mode === "cursor" ? !pagination.previousCursor : current <= 1}
        onClick={() => mode === "cursor"
          ? setQuery({ cursor: pagination.previousCursor ?? null, page: 1 })
          : setQuery({ page: current - 1, cursor: null })}
        type="button"
      >
        Previous
      </button>
      {mode === "length-aware" ? <div
        className="hidden items-center rounded-(--inlay-radius) border border-(--inlay-control-border) bg-(--inlay-surface) p-0.5 shadow-xs sm:flex"
        data-slot="pagination-pages"
      >
        {extremeLinks ? <button
          aria-label="First page"
          className="grid min-h-8 min-w-8 place-items-center rounded-(--inlay-radius) text-sm disabled:opacity-40"
          data-slot="pagination-first"
          disabled={current <= 1}
          onClick={() => setQuery({ page: 1, cursor: null })}
          type="button"
        >«</button> : null}
        {pages.map((page, index) =>
          page === "ellipsis" ? (
            <span
              aria-hidden="true"
              className="grid min-w-8 place-items-center text-sm text-(--inlay-muted)"
              key={`ellipsis-${index}`}
            >
              …
            </span>
          ) : (
            <button
              aria-current={page === current ? "page" : undefined}
              aria-label={`Page ${page}`}
              className="grid min-h-8 min-w-8 place-items-center rounded-[calc(var(--inlay-radius)-0.125rem)] px-2 text-sm font-medium text-(--inlay-muted) transition hover:bg-(--inlay-hover) hover:text-(--inlay-text) aria-current:bg-(--inlay-accent) aria-current:text-(--inlay-accent-foreground) focus-visible:outline-2 focus-visible:outline-(--inlay-accent)"
              key={page}
              onClick={() => setQuery({ page, cursor: null })}
              type="button"
            >
              {page}
            </button>
          ),
        )}
        {extremeLinks ? <button
          aria-label="Last page"
          className="grid min-h-8 min-w-8 place-items-center rounded-(--inlay-radius) text-sm disabled:opacity-40"
          data-slot="pagination-last"
          disabled={current >= last}
          onClick={() => setQuery({ page: last, cursor: null })}
          type="button"
        >»</button> : null}
      </div> : null}
      <button
        className={secondaryButton}
        disabled={mode === "cursor" ? !pagination.nextCursor : mode === "simple" ? !pagination.hasMorePages : current >= last}
        onClick={() => mode === "cursor"
          ? setQuery({ cursor: pagination.nextCursor ?? null, page: 1 })
          : setQuery({ page: current + 1, cursor: null })}
        type="button"
      >
        Next
      </button>
      </div>
    </nav>
  );
}

function paginationItems(current: number, last: number): Array<number | "ellipsis"> {
  if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1);
  const visible = new Set([1, last, current - 1, current, current + 1]);
  const pages = [...visible]
    .filter((page) => page >= 1 && page <= last)
    .sort((left, right) => left - right);
  const result: Array<number | "ellipsis"> = [];
  pages.forEach((page, index) => {
    if (index > 0 && page - pages[index - 1] > 1) result.push("ellipsis");
    result.push(page);
  });
  return result;
}

function defaults(resource: TableResource) {
  return Object.fromEntries(
    resource.filters
      .filter((filter) => filter.default != null)
      .map((filter) => [filter.name, filter.default]),
  );
}
function columnStorageKey(resource: TableResource) {
  return `inlay:table:${resource.name}:columns`;
}
function queryStorageKey(resource: TableResource) {
  return `inlay:table:${resource.name}:query`;
}
function persistQueryState(resource: TableResource, query: QueryState) {
  const config = resource.queryPersistence;
  if (!config || typeof window === "undefined" || !Object.values(config).some(Boolean)) return;
  const value = {
    ...(config.search ? { search: query.search } : {}),
    ...(config.search ? { columnSearches: query.columnSearches ?? {} } : {}),
    ...(config.sort ? { sort: query.sort, direction: query.direction } : {}),
    ...(config.filters ? { filters: query.filters } : {}),
  };
  try {
    window.sessionStorage.setItem(queryStorageKey(resource), JSON.stringify(value));
  } catch {
    // Query navigation still works when browser storage is unavailable.
  }
}
function restoredQueryState(resource: TableResource): Partial<QueryState> | null {
  const config = resource.queryPersistence;
  if (!config || typeof window === "undefined" || !Object.values(config).some(Boolean)) return null;
  try {
    const stored = JSON.parse(window.sessionStorage.getItem(queryStorageKey(resource)) ?? "null");
    if (!stored || typeof stored !== "object" || Array.isArray(stored)) return null;
    const restored: Partial<QueryState> = {};
    if (config.search && typeof stored.search === "string") restored.search = stored.search.slice(0, 200);
    if (config.search && stored.columnSearches && typeof stored.columnSearches === "object" && !Array.isArray(stored.columnSearches)) {
      const names = new Set(resource.columns.filter((column) => column.individuallySearchable).map((column) => column.name));
      restored.columnSearches = Object.fromEntries(
        Object.entries(stored.columnSearches)
          .filter(([name, value]) => names.has(name) && typeof value === "string")
          .map(([name, value]) => [name, (value as string).slice(0, 500)]),
      );
    }
    if (config.sort && (stored.sort === null || resource.columns.some((column) => column.sortable && column.name === stored.sort))) {
      restored.sort = stored.sort;
      restored.direction = stored.direction === "desc" ? "desc" : "asc";
    }
    if (config.filters && stored.filters && typeof stored.filters === "object" && !Array.isArray(stored.filters)) {
      const names = new Set(resource.filters.map((filter) => filter.name));
      restored.filters = Object.fromEntries(Object.entries(stored.filters).filter(([name]) => names.has(name)));
    }
    return Object.keys(restored).length ? restored : null;
  } catch {
    return null;
  }
}
function initialColumnState(resource: TableResource): { visibility: Record<string, boolean>; order: string[] } {
  const defaults = Object.fromEntries(resource.columns.map((column) => [column.name, column.visible]));
  const defaultOrder = resource.columns.map((column) => column.name);
  if (!resource.columnManager?.persistInSession || typeof window === "undefined") return { visibility: defaults, order: defaultOrder };
  try {
    const stored = JSON.parse(window.sessionStorage.getItem(columnStorageKey(resource)) ?? "null");
    if (!stored || typeof stored !== "object" || Array.isArray(stored)) return { visibility: defaults, order: defaultOrder };
    const storedVisibility = stored.visibility && typeof stored.visibility === "object" ? stored.visibility : stored;
    const visibility = Object.fromEntries(resource.columns.map((column) => [
      column.name,
      column.toggleable && typeof storedVisibility[column.name] === "boolean" ? storedVisibility[column.name] : column.visible,
    ]));
    const validStoredOrder = resource.columnManager.reorderable && Array.isArray(stored.order)
      ? stored.order.filter((name: unknown, index: number, values: unknown[]): name is string => typeof name === "string" && defaultOrder.includes(name) && values.indexOf(name) === index)
      : [];
    const order = validStoredOrder.length
      ? [...validStoredOrder, ...defaultOrder.filter((name) => !validStoredOrder.includes(name))]
      : defaultOrder;
    return { visibility, order };
  } catch {
    return { visibility: defaults, order: defaultOrder };
  }
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
    if (filter.type === "query-builder" && Object.hasOwn(normalized, filter.name))
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
    const constraint = constraints.find((item) => item.name === rule.constraint || item.relationship === rule.constraint) ?? constraints[0];
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
function getColumnState(row: TableRow, path: string): unknown {
  const presentation = (row.__inlay as { columns?: Record<string, CellPresentation> } | undefined)?.columns?.[path];
  return presentation ? presentation.state : getAtPath(row, path);
}
function updateRowColumn(
  rows: TableRow[],
  primaryKey: string,
  record: string | number,
  path: string,
  state: unknown,
): TableRow[] {
  return rows.map((row) => {
    if (String(row[primaryKey]) !== String(record)) return row;
    const next = { ...row };
    const inlay = row.__inlay as { columns?: Record<string, CellPresentation> } | undefined;
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
    const segments = path.split(".");
    let target: TableRow = next;
    for (const segment of segments.slice(0, -1)) {
      const value = target[segment];
      target[segment] = value && typeof value === "object" && !Array.isArray(value)
        ? { ...(value as TableRow) }
        : {};
      target = target[segment] as TableRow;
    }
    target[segments.at(-1)!] = state;
    return next;
  });
}
function interpolate(template: string, row: TableRow) {
  return template.replace(/\{([^}]+)\}/g, (_, key) =>
    encodeURIComponent(String(getAtPath(row, key) ?? "")),
  );
}
function flattenQuery(name: string, query: QueryState) {
  return {
    [`${name}_search`]: query.search || undefined,
    [`${name}_column_searches`]: query.columnSearches ?? {},
    [`${name}_sort`]: query.sort || undefined,
    [`${name}_direction`]: query.sort ? query.direction : undefined,
    [`${name}_page`]: query.page,
    [`${name}_per_page`]: query.perPage ?? undefined,
    [`${name}_cursor`]: query.cursor || undefined,
    [`${name}_filters`]: query.filters,
    [`${name}_loaded`]: query.loaded ? 1 : undefined,
    [`${name}_group`]: query.group || undefined,
    [`${name}_group_direction`]: query.group ? (query.groupDirection ?? "asc") : undefined,
    [`${name}_view`]: query.view ?? "",
  };
}
function summaryValue(summary: SummaryResult): string {
  const raw = summary.value;
  if (raw && typeof raw === "object" && "min" in raw && "max" in raw) {
    return `${String(raw.min ?? "—")} – ${String(raw.max ?? "—")}`;
  }
  if (raw === null || raw === undefined) return "—";
  const numeric = typeof raw === "number" ? raw : Number(raw);
  let value = Number.isNaN(numeric) ? String(raw) : summary.currency
    ? new Intl.NumberFormat(undefined, { style: "currency", currency: summary.currency, minimumFractionDigits: summary.decimalPlaces ?? undefined, maximumFractionDigits: summary.decimalPlaces ?? undefined }).format(numeric)
    : new Intl.NumberFormat(undefined, { minimumFractionDigits: summary.decimalPlaces ?? undefined, maximumFractionDigits: summary.decimalPlaces ?? undefined }).format(numeric);
  if (!summary.currency) value = `${summary.prefix ?? ""}${value}${summary.suffix ?? ""}`;
  return value;
}
function ColumnHeaderCell({ column, query, onQueryChange, rowSpan, searchDebounce, searchOnBlur }: { column: Column; query: QueryState; onQueryChange: (patch: Partial<QueryState>) => void; rowSpan?: number; searchDebounce?: number | null; searchOnBlur?: boolean }) {
  const columnSearches = query.columnSearches ?? {};
  const [searchDraft, setSearchDraft] = useState(columnSearches[column.name] ?? "");
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => setSearchDraft(columnSearches[column.name] ?? ""), [column.name, columnSearches[column.name]]);
  useEffect(() => () => { if (searchTimer.current) clearTimeout(searchTimer.current); }, []);
  const commitSearch = (value: string) => {
    if (searchTimer.current) clearTimeout(searchTimer.current);
    const next = { ...columnSearches };
    if (value) next[column.name] = value;
    else delete next[column.name];
    if (value !== (columnSearches[column.name] ?? "")) onQueryChange({ columnSearches: next, page: 1 });
  };
  const queueSearch = (value: string) => {
    if (searchTimer.current) clearTimeout(searchTimer.current);
    const debounce = searchDebounce ?? 0;
    if (debounce <= 0) return commitSearch(value);
    searchTimer.current = setTimeout(() => commitSearch(value), debounce);
  };
  return <th {...safeAttributes(column.extraHeaderAttributes)} aria-sort={query.sort === column.name ? `${query.direction}ending` : "none"} className={`${column.wrapHeader ? 'whitespace-normal' : 'whitespace-nowrap'} min-w-0 overflow-hidden border-b border-(--inlay-border) px-3 py-2.5 text-xs font-semibold tracking-wide text-(--inlay-muted) uppercase lg:px-4 ${alignmentClass(column.alignment)} ${responsiveColumnClass(column)}`} rowSpan={rowSpan} scope="col" style={columnDimensionStyle(column)} title={column.headerTooltip ?? undefined}>
    <div className="grid min-w-0 gap-2">
      {column.sortable ? <button className="inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-sm hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" onClick={() => onQueryChange({ sort: column.name, direction: query.sort === column.name && query.direction === "asc" ? "desc" : "asc", page: 1 })} type="button"><span className={column.wrapHeader ? '' : 'truncate'}>{column.label}</span>{query.sort === column.name ? <span aria-hidden="true" className="shrink-0 text-(--inlay-accent)">{query.direction === "asc" ? "↑" : "↓"}</span> : null}</button> : <span className={column.wrapHeader ? '' : 'truncate'}>{column.label}</span>}
      {column.individuallySearchable ? <label className="normal-case tracking-normal">
        <span className="sr-only">Search {column.label}</span>
        <input
          aria-label={`Search ${column.label}`}
          className={`${controlClass} min-h-8 w-full px-2 py-1 text-sm font-normal`}
          data-slot="column-search"
          onBlur={() => searchOnBlur && commitSearch(searchDraft)}
          onChange={(event) => {
            const value = event.target.value;
            setSearchDraft(value);
            if (!searchOnBlur) queueSearch(value);
          }}
          onKeyDown={(event) => {
            if (event.key === "Enter") {
              event.preventDefault();
              commitSearch(searchDraft);
            }
          }}
          type="search"
          value={searchDraft}
        />
      </label> : null}
    </div>
  </th>;
}

/** Attributes are sanitized in PHP; this keeps a hand-written payload harmless too. */
function safeAttributes(attributes?: Record<string, string>): Record<string, string> {
  if (!attributes) return {};
  const unsafe = new Set(["style", "srcdoc", "href", "src", "formaction", "action", "children", "dangerouslySetInnerHTML", "key", "ref"]);
  return Object.fromEntries(
    Object.entries(attributes).filter(([key, value]) =>
      typeof value === "string" && !unsafe.has(key.toLowerCase()) && !key.toLowerCase().startsWith("on")),
  );
}
function cellAttributesFor(row: TableRow, column: Column): Record<string, string> {
  const presentation = (row.__inlay as { columns?: Record<string, CellPresentation> } | undefined)?.columns?.[column.name];
  return { ...(column.extraCellAttributes ?? {}), ...(presentation?.cellAttributes ?? {}) };
}
function contentAttributesFor(row: TableRow, column: Column): Record<string, string> {
  const presentation = (row.__inlay as { columns?: Record<string, CellPresentation> } | undefined)?.columns?.[column.name];
  return { ...(column.extraAttributes ?? {}), ...(presentation?.attributes ?? {}) };
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
function summaryText(summaries: Record<string, SummaryResult[]>): string {
  return Object.values(summaries).flat().map((summary) => `${summary.label}: ${summaryValue(summary)}`).join(" · ");
}
function summaryItems(overall?: SummaryResult[], page?: SummaryResult[], pageOnly = false) {
  if (pageOnly) {
    if (!page?.length) return null;
    return <div className="grid min-w-0 gap-1">{page.map((summary, index) => <div className="min-w-0" key={`${summary.type}-${index}`}><span className="font-medium text-(--inlay-text)">Page: {summaryValue(summary)}</span></div>)}</div>;
  }
  if (!overall?.length) return null;
  // Matched by type and label: a custom summarizer may publish no page value.
  const pageSummary = (summary: SummaryResult) =>
    page?.find((candidate) => candidate.type === summary.type && candidate.label === summary.label);
  return <div className="grid min-w-0 gap-1">{overall.map((summary, index) => {
    const paged = pageSummary(summary);
    return <div className="min-w-0" key={`${summary.type}-${index}`}><span className="font-medium text-(--inlay-text)">{summary.label}: {summaryValue(summary)}</span>{paged ? <span className="block text-xs text-(--inlay-muted)">Page: {summaryValue(paged)}</span> : null}</div>;
  })}</div>;
}
function badgeColor(color?: string) {
  return color === "success"
    ? "bg-(--inlay-success-surface) text-(--inlay-success)"
    : color === "danger"
      ? "bg-(--inlay-danger-surface) text-(--inlay-danger)"
      : "bg-(--inlay-surface-muted) text-(--inlay-text)";
}

function triggerButtonClass(action?: Action): string {
  if (action?.color === "primary") return primaryButton;
  if (action?.color === "danger") return dangerButton;
  return secondaryButton;
}
function semanticTextClass(color?: string | null) {
  return color === 'primary' ? 'text-(--inlay-accent)'
    : color === 'danger' ? 'text-(--inlay-danger)'
      : color === 'info' ? 'text-(--inlay-info)'
        : color === 'success' ? 'text-(--inlay-success)'
          : color === 'warning' ? 'text-(--inlay-warning)'
            : color === 'gray' ? 'text-(--inlay-muted)'
              : '';
}
function textBadgeClass(color?: string | null) {
  return color === 'primary' ? 'bg-(--inlay-accent)/10 text-(--inlay-accent)'
    : color === 'danger' ? 'bg-(--inlay-danger-surface) text-(--inlay-danger)'
      : color === 'info' ? 'bg-(--inlay-info-surface) text-(--inlay-info)'
        : color === 'success' ? 'bg-(--inlay-success-surface) text-(--inlay-success)'
          : color === 'warning' ? 'bg-(--inlay-warning-surface) text-(--inlay-warning)'
            : color === 'gray' || !color ? 'bg-(--inlay-surface-muted) text-(--inlay-text)'
              : '';
}
function semanticTextStyle(color?: string | null, badge = false): CSSProperties | undefined {
  if (!color || ['primary', 'danger', 'info', 'success', 'warning', 'gray'].includes(color)) return undefined;
  return badge
    ? { color: `var(--inlay-color-${color})`, backgroundColor: `var(--inlay-color-${color}-soft)` }
    : { color: `var(--inlay-color-${color})` };
}
function textSizeClass(size?: Column['textSize']) { return size === 'small' ? 'text-xs' : size === 'large' ? 'text-base' : 'text-sm'; }
function textWeightClass(weight?: Column['fontWeight']) { return weight === 'light' ? 'font-light' : weight === 'medium' ? 'font-medium' : weight === 'semibold' ? 'font-semibold' : weight === 'bold' ? 'font-bold' : 'font-normal'; }
function textFamilyClass(family?: Column['fontFamily']) { return family === 'serif' ? 'font-serif' : family === 'mono' ? 'font-mono' : 'font-sans'; }
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
function columnManagerGridClass(columns: number) {
  return columns === 2 ? "sm:grid-cols-2"
    : columns === 3 ? "sm:grid-cols-2 lg:grid-cols-3"
      : columns === 4 ? "sm:grid-cols-2 lg:grid-cols-4"
        : columns === 5 ? "sm:grid-cols-2 lg:grid-cols-5"
          : columns >= 6 ? "sm:grid-cols-2 lg:grid-cols-6"
            : "grid-cols-1";
}

function responsiveColumnClass(column: Column) {
  const visible = column.visibleFrom ? ({ sm: "hidden sm:table-cell", md: "hidden md:table-cell", lg: "hidden lg:table-cell", xl: "hidden xl:table-cell", "2xl": "hidden 2xl:table-cell" } as const)[column.visibleFrom] : "";
  const hidden = column.hiddenFrom ? ({ sm: "sm:hidden", md: "md:hidden", lg: "lg:hidden", xl: "xl:hidden", "2xl": "2xl:hidden" } as const)[column.hiddenFrom] : "";
  return `${visible} ${hidden}`;
}
function responsiveLayoutClass(component: ColumnLayout) {
  const visible = component.visibleFrom ? ({ sm: "hidden sm:flex", md: "hidden md:flex", lg: "hidden lg:flex", xl: "hidden xl:flex", "2xl": "hidden 2xl:flex" } as const)[component.visibleFrom] : "";
  const hidden = component.hiddenFrom ? ({ sm: "sm:hidden", md: "md:hidden", lg: "lg:hidden", xl: "xl:hidden", "2xl": "2xl:hidden" } as const)[component.hiddenFrom] : "";
  return `${visible} ${hidden} ${component.grow === false ? 'grow-0' : 'min-w-0 grow'}`;
}
function splitFromClass(from?: ColumnLayout['from']) {
  return from ? ({ sm: 'sm:flex-row sm:items-center', md: 'md:flex-row md:items-center', lg: 'lg:flex-row lg:items-center', xl: 'xl:flex-row xl:items-center', '2xl': '2xl:flex-row 2xl:items-center' } as const)[from] : 'flex-row items-center';
}
function stackAlignmentClass(alignment?: ColumnLayout['alignment']) { return alignment === 'center' ? 'items-center' : alignment === 'end' ? 'items-end' : 'items-start'; }
function stackSpaceClass(space = 1) { return ['gap-0', 'gap-1', 'gap-2', 'gap-3', 'gap-4', 'gap-5', 'gap-6', 'gap-7', 'gap-8'][space] ?? 'gap-1'; }
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
// Relative time is computed in the browser so it stays correct while a page is
// left open, rather than being frozen when the payload was built.
function relativeTime(value: unknown): string | null {
  const parsed = new Date(String(value));
  if (Number.isNaN(parsed.getTime())) return null;
  const seconds = Math.round((parsed.getTime() - Date.now()) / 1000);
  const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [
    ["year", 31536000], ["month", 2592000], ["week", 604800],
    ["day", 86400], ["hour", 3600], ["minute", 60], ["second", 1],
  ];
  const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });
  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size || unit === "second") {
      return formatter.format(Math.round(seconds / size), unit);
    }
  }
  return null;
}

function format(value: unknown, column: Column, alreadyFormatted = false) {
  if (alreadyFormatted) return value ?? "";
  if (column.since && value) return relativeTime(value) ?? value;
  if (column.money && typeof value === "number")
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: column.currency ?? "USD",
    }).format(value);
  if (column.numeric && typeof value === "number")
    return new Intl.NumberFormat().format(value);
  if (column.dateFormat && value)
    return new Intl.DateTimeFormat().format(new Date(String(value)));
  return value ?? "";
}
