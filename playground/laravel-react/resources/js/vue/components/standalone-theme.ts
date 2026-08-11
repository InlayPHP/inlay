/**
 * The standalone Vue pages do not mount a Panel, so they provide the same
 * semantic light/dark host tokens as the React standalone shell.
 */
export const standaloneThemeClass =
    'min-h-dvh min-w-0 overflow-x-hidden bg-(--inlay-background) text-(--inlay-foreground) [--inlay-accent-foreground:#fff] [--inlay-accent:#4f46e5] [--inlay-background:#f6f7fb] [--inlay-border:rgb(24_24_27/0.12)] [--inlay-control-border:#d4d4d8] [--inlay-control-height:2.5rem] [--inlay-danger:#dc2626] [--inlay-foreground:#18181b] [--inlay-hover:#f4f4f5] [--inlay-muted:#71717a] [--inlay-radius:0.75rem] [--inlay-surface-muted:#f4f4f5] [--inlay-surface:#fff] dark:[--inlay-accent-foreground:#111827] dark:[--inlay-accent:#818cf8] dark:[--inlay-background:#09090b] dark:[--inlay-border:rgb(255_255_255/0.12)] dark:[--inlay-control-border:rgb(255_255_255/0.2)] dark:[--inlay-foreground:#fafafa] dark:[--inlay-hover:#27272a] dark:[--inlay-muted:#a1a1aa] dark:[--inlay-surface-muted:#242427] dark:[--inlay-surface:#18181b]';

export const standaloneThemeStyle = {
    '--inlay-default-accent': 'var(--inlay-accent)',
    '--inlay-default-surface': 'var(--inlay-surface)',
    '--inlay-default-surface-muted': 'var(--inlay-surface-muted)',
    '--inlay-default-foreground': 'var(--inlay-foreground)',
    '--inlay-default-muted': 'var(--inlay-muted)',
    '--inlay-default-border': 'var(--inlay-border)',
    '--inlay-default-danger': 'var(--inlay-danger)',
    '--inlay-panel-accent': 'var(--inlay-accent)',
    '--inlay-panel-accent-foreground': 'var(--inlay-accent-foreground)',
    '--inlay-panel-background': 'var(--inlay-background)',
    '--inlay-panel-surface': 'var(--inlay-surface)',
    '--inlay-panel-text': 'var(--inlay-foreground)',
    '--inlay-panel-muted': 'var(--inlay-muted)',
    '--inlay-panel-border': 'var(--inlay-border)',
    '--inlay-panel-control-border': 'var(--inlay-control-border)',
    '--inlay-panel-hover': 'var(--inlay-hover)',
    '--inlay-panel-radius': 'var(--inlay-radius)',
    '--inlay-panel-control-height': 'var(--inlay-control-height)',
    '--inlay-panel-button-height': 'var(--inlay-control-height)',
    '--inlay-panel-button-xs-height': '2rem',
    '--inlay-panel-button-sm-height': '2.25rem',
    '--inlay-panel-button-lg-height': '2.75rem',
    '--inlay-panel-icon-button-size': 'var(--inlay-control-height)',
};
