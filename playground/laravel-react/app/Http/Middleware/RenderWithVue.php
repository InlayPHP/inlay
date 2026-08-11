<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a route through the Vue renderers instead of the React ones.
 *
 * Everything upstream of this is shared: the same page classes, the same schema, the
 * same serialized payload. Only the root view differs, which is the whole point —
 * a divergence found here is a renderer divergence and cannot be a difference in
 * what the server sent.
 *
 * This closes the gap the parity document had named repeatedly: every renderer
 * comparison until now ran in jsdom, and the defects that actually reached a
 * screenshot — an icon name printed as text, a control with `min-height: 0`, error
 * text computed to black — were ones only a browser would show.
 */
class RenderWithVue
{
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::setRootView('app-vue');

        return $next($request);
    }
}
