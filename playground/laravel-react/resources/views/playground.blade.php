{{--
    The playground's index.

    `/` is served directly by this index, so a fresh
    database always receives a useful landing page. The demo is optional; this is
    not, so it is a plain Blade view with no bundle and no database behind it — it
    renders even when assets are stale or migrations have only just run.
--}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Inlay playground</title>
        <style>
            :root { color-scheme: light dark; }
            body { margin: 0 auto; max-width: 46rem; padding: 3rem 1.5rem; font: 16px/1.6 ui-sans-serif, system-ui, sans-serif; }
            h1 { margin: 0 0 .25rem; font-size: 1.75rem; letter-spacing: -.02em; }
            p.lede { margin: 0 0 2rem; opacity: .7; }
            h2 { margin: 2rem 0 .5rem; font-size: .8rem; text-transform: uppercase; letter-spacing: .08em; opacity: .55; }
            ul { margin: 0; padding: 0; list-style: none; }
            li { border-top: 1px solid color-mix(in srgb, currentColor 12%, transparent); }
            a { display: flex; gap: .75rem; padding: .7rem 0; color: inherit; text-decoration: none; }
            a:hover span:first-child { text-decoration: underline; }
            a span:last-child { margin-left: auto; opacity: .55; font-size: .85rem; }
            code { background: color-mix(in srgb, currentColor 8%, transparent); border-radius: .3rem; padding: .1rem .35rem; font-size: .9em; }
        </style>
    </head>
    <body>
        <h1>Inlay playground</h1>
        <p class="lede">A Laravel application running the Inlay packages from source. Sign in with
            <code>test@example.com</code> / <code>password</code>.</p>

        <h2>Admin panel</h2>
        <ul>
            <li><a href="/admin"><span>Panel, resources, widgets</span><span>React</span></a></li>
            <li><a href="/vue/panel"><span>Panel shell, widgets</span><span>Vue</span></a></li>
        </ul>

        <h2>Standalone pages</h2>
        <ul>
            <li><a href="/standalone/forms"><span>Form</span><span>React</span></a></li>
            <li><a href="/standalone/tables"><span>Table</span><span>React</span></a></li>
            <li><a href="/vue/standalone/forms"><span>Form</span><span>Vue</span></a></li>
            <li><a href="/vue/standalone/tables"><span>Table</span><span>Vue</span></a></li>
        </ul>

        <p style="margin-top:2.5rem;opacity:.55;font-size:.85rem">The Vue routes serve the same page
            classes and the same payload as their React counterparts, so anything that differs
            between them is the renderer.</p>
    </body>
</html>
