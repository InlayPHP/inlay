import { Laptop, Moon, Sun } from 'lucide-react';
import { useEffect, useSyncExternalStore } from 'react';

type ThemeMode = 'light' | 'dark' | 'system';

const modes: ThemeMode[] = ['light', 'dark', 'system'];
const labels: Record<ThemeMode, string> = {
    light: 'Light theme',
    dark: 'Dark theme',
    system: 'System theme',
};
const themeChangeEvent = 'inlay-theme-change';

function getStoredTheme(): ThemeMode {
    const stored = window.localStorage.getItem('inlay-theme');

    return modes.includes(stored as ThemeMode)
        ? (stored as ThemeMode)
        : 'system';
}

function subscribeToTheme(onStoreChange: () => void) {
    window.addEventListener('storage', onStoreChange);
    window.addEventListener(themeChangeEvent, onStoreChange);

    return () => {
        window.removeEventListener('storage', onStoreChange);
        window.removeEventListener(themeChangeEvent, onStoreChange);
    };
}

function applyTheme(mode: ThemeMode) {
    const dark =
        mode === 'dark' ||
        (mode === 'system' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.dataset.themeMode = mode;
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
}

export function ThemeSwitcher() {
    // React uses the server snapshot during hydration, then reads the persisted
    // browser value without discarding the server-rendered subtree.
    const mode = useSyncExternalStore<ThemeMode>(
        subscribeToTheme,
        getStoredTheme,
        (): ThemeMode => 'system',
    );

    useEffect(() => {
        applyTheme(mode);

        if (mode !== 'system') {
            return;
        }

        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const update = () => applyTheme('system');

        media.addEventListener('change', update);

        return () => media.removeEventListener('change', update);
    }, [mode]);

    const next = () => {
        const value = modes[(modes.indexOf(mode) + 1) % modes.length];
        window.localStorage.setItem('inlay-theme', value);
        window.dispatchEvent(new Event(themeChangeEvent));
    };
    const Icon = mode === 'light' ? Sun : mode === 'dark' ? Moon : Laptop;

    return (
        <button
            aria-label={`${labels[mode]}. Switch theme`}
            className="inline-flex size-9 items-center justify-center rounded-(--inlay-radius) text-(--inlay-muted) transition hover:bg-(--inlay-hover) hover:text-(--inlay-foreground) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)"
            onClick={next}
            title={labels[mode]}
            type="button"
        >
            <Icon aria-hidden="true" className="size-4" strokeWidth={1.8} />
        </button>
    );
}
