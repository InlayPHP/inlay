import { useId, useState } from 'react';

export function CodeDisclosure({
    code,
    title = 'View PHP builder and React code',
}: {
    code: string;
    title?: string;
}) {
    const [open, setOpen] = useState(false);
    const contentId = useId();

    return (
        <div className="mt-8 border-t border-(--inlay-border) pt-5">
            <button
                aria-controls={contentId}
                aria-expanded={open}
                className="flex cursor-pointer items-center gap-2 rounded-lg text-sm font-medium text-(--inlay-muted) outline-none hover:text-(--inlay-foreground) focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-(--inlay-accent)"
                onClick={() => setOpen((current) => !current)}
                type="button"
            >
                <span
                    aria-hidden="true"
                    className={`text-xs transition-transform ${open ? 'rotate-90' : ''}`}
                >
                    ▶
                </span>
                {title}
            </button>
            <div
                className={`${open ? 'block' : 'hidden'} mt-4 overflow-hidden rounded-(--inlay-radius) bg-zinc-950 shadow-xl ring-1 ring-white/10`}
                id={contentId}
            >
                <div className="border-b border-white/10 px-4 py-2 font-mono text-xs text-zinc-400">
                    Standalone package usage
                </div>
                <pre className="max-w-full overflow-x-auto p-4 font-mono text-sm leading-6 [tab-size:2] text-zinc-100">
                    <code>{code}</code>
                </pre>
            </div>
        </div>
    );
}
