<?php

declare(strict_types=1);

/**
 * Guards on the custom properties renderers declare, because CSS fails these
 * silently: a wrong custom property produces no console error, no test failure,
 * and no visible break in the one mode the author happened to look at.
 *
 * Both rules here were written against defects found in shipped components, and
 * both were verified in a real browser rather than argued from the spec.
 */

/**
 * A custom property whose value references its own name is a cycle, so it is
 * invalid at computed-value time. Chromium does not use the `var()` fallback and
 * does not inherit the ancestor's value either — the property is discarded, and
 * every property reading it becomes invalid too.
 *
 * Measured, with an ancestor correctly setting `--inlay-danger: #dc2626`:
 *
 *   --inlay-danger: var(--inlay-danger, #dc2626)   =>  (empty)
 *   color: var(--inlay-danger)                     =>  rgb(0, 0, 0)
 *   min-height: var(--inlay-control-height)        =>  0px
 *
 * Four of these had shipped. Form validation errors rendered black instead of red
 * in both renderers, and every control in a Vue table had `min-height: 0`, which
 * read as missing padding.
 */
it('never declares a custom property that falls back to itself', function (): void {
    $cycles = [];
    $declarations = 0;

    foreach (rendererSourceFiles() as $file => $source) {
        // Collapsed, because these maps are formatted across several lines.
        $flat = (string) preg_replace('/\s+/', ' ', $source);

        preg_match_all('/["\'](--inlay-[a-z0-9-]+)["\']\s*:\s*([^,;]{0,200})/', $flat, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $property, $value]) {
            $declarations++;

            if (str_contains($value, "var({$property}")) {
                $cycles[] = "{$file} declares {$property} with a fallback to itself";
            }
        }
    }

    // A regex that stopped matching would make this vacuous.
    expect($declarations)->toBeGreaterThan(40);

    expect($cycles)->toBe([], 'The browser discards the declaration and the inherited value, so anything reading it computes to its initial value.');
});

/**
 * A package must declare the same custom properties in both renderers. The
 * properties are the styling contract every descendant reads, so a package
 * declaring fewer in one renderer means descendants there read something nothing
 * defines — `ring-(--inlay-control-border)` fell back to `currentColor`, drawing a
 * black outline in Vue where React drew a hairline.
 *
 * Compared per package rather than per component file, because which component
 * declares a property is an implementation detail: Vue's infolist grid lives in
 * `SchemaRenderer.vue` and React's in `Infolist.tsx`, and both are correct.
 *
 * Property names only. The values differ legitimately — each renderer reads its own
 * props object, and the two theme types name their keys independently.
 */
it('declares the same custom properties in both renderers', function (): void {
    /** @var array<string, array<string, list<string>>> */
    $byPackage = [];

    foreach (rendererSourceFiles() as $file => $source) {
        if (! preg_match('#packages/([^/]+)/(react|vue)/src/#', $file, $parts)) {
            continue;
        }

        [, $package, $renderer] = $parts;

        preg_match_all('/["\'](--inlay-[a-z0-9-]+)["\']\s*:/', $source, $found);

        foreach ($found[1] as $property) {
            $byPackage[$package][$renderer][] = $property;
        }
    }

    $divergent = [];

    /**
     * Packages where one renderer declares no custom properties at all, with the
     * reason. This list exists because skipping them silently was a hole: the check
     * was written for "one renderer declares fewer", and declaring none is that case
     * at its extreme, yet `isset()` on both keys quietly passed it over.
     *
     * @var array<string, string>
     */
    $oneSidedByDesign = [];

    foreach ($byPackage as $package => $renderers) {
        if (! isset($renderers['react'], $renderers['vue'])) {
            // Only one renderer of the pair is present at all — `rendererParity`
            // reports a missing counterpart, so this is not that.
            $pair = is_dir(dirname(__DIR__)."/packages/{$package}/react/src") && is_dir(dirname(__DIR__)."/packages/{$package}/vue/src");

            if ($pair && ! isset($oneSidedByDesign[$package])) {
                $divergent[] = "{$package}: one renderer declares custom properties and the other declares none";
            }

            continue;
        }

        $react = array_unique($renderers['react']);
        $vue = array_unique($renderers['vue']);

        foreach (array_diff($react, $vue) as $missing) {
            $divergent[] = "{$package}: Vue never declares {$missing}, which React does";
        }

        foreach (array_diff($vue, $react) as $missing) {
            $divergent[] = "{$package}: React never declares {$missing}, which Vue does";
        }
    }

    // The packages this was written for would otherwise pass by being skipped.
    expect(array_keys($byPackage))->toContain('form', 'table', 'import', 'infolist');

    expect($divergent)->toBe([], 'Descendants in the lagging renderer read a custom property nothing defines.');
});

/**
 * Every custom property a renderer declares belongs to the `--inlay-` namespace,
 * because that prefix is what the styling documentation tells a host to target.
 *
 * Two had drifted out of it. Vue's infolist grid read `--infolist-columns` and the
 * widget dashboard read `--widget-columns`, so a host setting the documented
 * `--inlay-infolist-columns` or `--inlay-widget-columns` changed nothing. Neither
 * broke a test or a screenshot: the components set and read the same wrong name, so
 * they looked right and were simply unstyleable.
 */
it('declares custom properties only in the inlay namespace', function (): void {
    /**
     * Reason required per entry, so an exception cannot be added silently.
     *
     * @var array<string, string>
     */
    $allowed = [
        '--media-' => 'The media manager aliases its own namespace onto --inlay-* tokens internally. Undocumented, declared identically by both renderers, and renaming it would change a surface some host may already target.',
    ];

    $outside = [];
    $declarations = 0;

    foreach (rendererSourceFiles() as $file => $source) {
        if (! preg_match('#packages/[^/]+/(react|vue)/src/#', $file)) {
            continue;
        }

        preg_match_all('/["\'](--[a-z0-9-]+)["\']\s*:/', $source, $found);

        foreach ($found[1] as $property) {
            $declarations++;

            if (str_starts_with($property, '--inlay-')) {
                continue;
            }

            foreach (array_keys($allowed) as $prefix) {
                if (str_starts_with($property, $prefix)) {
                    continue 2;
                }
            }

            $outside[] = "{$file} declares {$property}, which no host can find by the documented name";
        }
    }

    expect($declarations)->toBeGreaterThan(40);

    expect($outside)->toBe([], 'A property outside the namespace is set and read by the same component, so it looks correct and cannot be restyled.');
});

/**
 * @return array<string, string> Path relative to the repository root, keyed to its contents.
 */
function rendererSourceFiles(): array
{
    $root = dirname(__DIR__);
    $files = [];

    $tree = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root.'/packages', FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $item): bool => ! in_array($item->getFilename(), ['node_modules', 'dist'], true),
        ),
    );

    foreach ($tree as $item) {
        if (! $item instanceof SplFileInfo || ! in_array($item->getExtension(), ['tsx', 'vue', 'ts', 'css'], true)) {
            continue;
        }

        $path = str_replace($root.'/', '', $item->getPathname());
        $files[$path] = withoutComments((string) file_get_contents($item->getPathname()));
    }

    return $files;
}

/**
 * `classNames` is the element-level override surface: a host names a key and the
 * renderer appends that string to one element's class list. Two failures are
 * possible and neither shows up in a test that only asks whether a component
 * rendered — a key declared in the type but applied to nothing does nothing, and a
 * key one renderer accepts and the other does not styles half a codebase.
 *
 * Both had shipped. `TableClassNames` in Vue was nine keys short of React's, so
 * `classNames.row` and `classNames.cell` restyled React tables and silently did
 * nothing in Vue. Vue's infolist declared `empty`, `layout`, and `wizard` and
 * applied none of them. React's widget dashboard declared `stat` and applied it
 * nowhere, while Vue applied it. `header` was declared by both and named no element
 * in either.
 */
it('applies every classNames key it declares, in both renderers', function (): void {
    /**
     * Keys a renderer declares deliberately without applying them itself, with the
     * reason. Empty on purpose: every entry here is a host-visible no-op.
     *
     * @var array<string, string>
     */
    $unapplied = [];

    /**
     * Packages whose two renderers accept different key names, with the reason.
     *
     * @var array<string, string>
     */
    $vocabularyExceptions = [
    ];

    $declared = [];
    $applied = [];

    foreach (rendererSourceFiles() as $file => $source) {
        if (! preg_match('#packages/([^/]+)/(react|vue)/src/#', $file, $parts)) {
            continue;
        }

        [, $package, $renderer] = $parts;

        // Only `Partial<Record<'a' | 'b', string>>` unions, which is how every
        // renderer but the panel declares the surface; the panel's interface is
        // read below.
        preg_match_all('/ClassNames\s*=\s*Partial<\s*Record<(.*?),\s*string\s*>\s*>/s', $source, $unions);

        foreach ($unions[1] as $union) {
            preg_match_all('/["\']([a-zA-Z][a-zA-Z0-9]*)["\']/', $union, $keys);
            foreach ($keys[1] as $key) {
                $declared[$package][$renderer][$key] = true;
            }
        }

        if (preg_match('/ClassNames\s*\{(.*?)\n\}/s', $source, $interface)) {
            preg_match_all('/^\s*([a-zA-Z][a-zA-Z0-9]*)\?:\s*string/m', $interface[1], $keys);
            foreach ($keys[1] as $key) {
                $declared[$package][$renderer][$key] = true;
            }
        }

        // `classNames?.key`, `classNames.key`, and the `classes?.key` alias React
        // passes into its panel renderers.
        preg_match_all('/(?:classNames|classes)[?]?\.([a-zA-Z][a-zA-Z0-9]*)/', $source, $uses);
        foreach ($uses[1] as $key) {
            $applied[$package][$renderer][$key] = true;
        }
    }

    // A parse that silently stopped finding declarations would pass everything.
    expect($declared)->toHaveKeys(['form', 'table', 'infolist', 'panel', 'widgets', 'import']);

    $dead = [];
    $divergent = [];

    foreach ($declared as $package => $renderers) {
        foreach ($renderers as $renderer => $keys) {
            foreach (array_diff_key($keys, $applied[$package][$renderer] ?? []) as $key => $_) {
                if (($unapplied["{$package}/{$renderer}.{$key}"] ?? null) !== null) {
                    continue;
                }

                $dead[] = "{$package}/{$renderer} declares classNames.{$key} and applies it to nothing";
            }
        }

        if (! isset($renderers['react'], $renderers['vue']) || isset($vocabularyExceptions[$package])) {
            continue;
        }

        foreach (array_diff_key($renderers['react'], $renderers['vue']) as $key => $_) {
            $divergent[] = "{$package}: Vue does not accept classNames.{$key}, which React does";
        }

        foreach (array_diff_key($renderers['vue'], $renderers['react']) as $key => $_) {
            $divergent[] = "{$package}: React does not accept classNames.{$key}, which Vue does";
        }
    }

    // Every exception must still describe a real package, or it is stale.
    foreach (array_keys($vocabularyExceptions) as $package) {
        expect($declared)->toHaveKey($package);
    }

    expect($dead)->toBe([], 'A host sets the key, the type accepts it, and nothing changes.');
    expect($divergent)->toBe([], 'The same override styles one renderer and is ignored by the other.');
});

/**
 * `data-slot` is the third styling surface, and the one a host selects on:
 * `[data-slot="table-row"]` in a stylesheet, or in a test. A name one renderer
 * publishes and the other does not means a selector silently matches nothing.
 *
 * Vue's media manager implemented both the upload dropzone and the asset detail
 * drawer and named neither, so `[data-slot="dropzone"]` and
 * `[data-slot="detail-drawer"]` found nothing there while working in React. Vue's
 * content tree went further and rendered a different shape — one flat list where
 * React nested a `<ul data-slot="cms-tree-children">` per parent — so the hook was
 * missing because the element was.
 *
 * Static comparison only. Several renderers compose a name at runtime
 * (`data-slot={`${slot}-schema`}`), which no amount of regex resolves, so a package
 * where either renderer does that is exempted by name with its reason rather than
 * guessed at. The exemptions are the honest boundary of this check, not a backlog.
 */
it('publishes the same data-slot names in both renderers', function (): void {
    /**
     * Reason required per entry.
     *
     * @var array<string, string>
     */
    $exempt = [
        'form' => 'Composes slot names at runtime, so the literal sets in the source are each incomplete and their difference is not evidence of anything. Covered instead by rendering: `formSlotVocabulary` in @inlayphp/core/testing, asserted by both renderer suites.',
        'infolist' => 'Same, and worse: React emits `${slot}-schema`, `${position}-actions`, and a bare `{slot}` for the eight label and content regions, all of which Vue writes as literals, so twelve names looked Vue-only. Covered by `infolistSlotVocabulary`, asserted by both renderer suites.',
        'panel' => 'React composes its navigation item slots at runtime where Vue writes them out. Covered instead by rendering: `panelSlotVocabulary` in @inlayphp/core/testing, asserted by both renderer suites for both navigation modes.',
    ];

    $slots = [];
    $composesAtRuntime = [];

    foreach (rendererSourceFiles() as $file => $source) {
        if (! preg_match('#packages/([^/]+)/(react|vue)/src/#', $file, $parts)) {
            continue;
        }

        [, $package, $renderer] = $parts;

        preg_match_all('/(?<![:\w-])data-slot="([a-z][a-z0-9-]*)"/', $source, $found);

        foreach ($found[1] as $slot) {
            $slots[$package][$renderer][$slot] = true;
        }

        // Vue may create a style or portal node imperatively rather than through
        // a template attribute. Treat its literal dataset slot assignment as
        // the same published selector contract.
        preg_match_all('/dataset\.slot\s*=\s*[\'\"]([a-z][a-z0-9-]*)[\'\"]/', $source, $dynamicSlots);

        foreach ($dynamicSlots[1] as $slot) {
            $slots[$package][$renderer][$slot] = true;
        }

        // An expression may still name its slots literally — the column manager
        // overlay is `layout === 'modal' ? 'column-manager-overlay' : undefined` in
        // both renderers — so quoted names inside one count as published. Only a
        // template literal or a bare identifier is genuinely unresolvable.
        preg_match_all('/data-slot=(?:\{([^}]*)\}|"((?:[^"]*\?[^"]*)|(?:[^"]*\$[^"]*))")/', $source, $expressions, PREG_SET_ORDER);

        foreach ($expressions as $expression) {
            $body = $expression[1] !== '' ? $expression[1] : ($expression[2] ?? '');

            // Both quote styles: React writes "modal", Vue writes 'modal', and
            // reading only one made the same conditional look resolvable in one
            // renderer and unresolvable in the other. Over-collecting a non-slot
            // string like "modal" is harmless because both renderers collect it.
            preg_match_all('/[\'"]([a-z][a-z0-9-]*)[\'"]/', $body, $named);

            foreach ($named[1] as $slot) {
                $slots[$package][$renderer][$slot] = true;
            }

            if (str_contains($body, '`') || $named[1] === []) {
                $composesAtRuntime[$package][$renderer] = true;
            }
        }
    }

    // A parse that found nothing would pass everything.
    expect($slots)->toHaveKeys(['table', 'media-manager']);

    $divergent = [];

    foreach ($slots as $package => $renderers) {
        if (! isset($renderers['react'], $renderers['vue']) || isset($exempt[$package])) {
            continue;
        }

        // An unexempted package that composes names would report false differences.
        expect($composesAtRuntime)->not->toHaveKey($package);

        foreach (array_diff_key($renderers['react'], $renderers['vue']) as $slot => $_) {
            $divergent[] = "{$package}: Vue never publishes data-slot=\"{$slot}\", which React does";
        }

        foreach (array_diff_key($renderers['vue'], $renderers['react']) as $slot => $_) {
            $divergent[] = "{$package}: React never publishes data-slot=\"{$slot}\", which Vue does";
        }
    }

    // A stale exemption hides a package that no longer needs one.
    foreach (array_keys($exempt) as $package) {
        expect($slots)->toHaveKey($package);
    }

    expect($divergent)->toBe([], 'A selector written against one renderer matches nothing in the other.');
});

/**
 * Comments are removed before anything is read out of a file, because prose is
 * indistinguishable from code to a regex.
 *
 * This was not hypothetical: a comment explaining why the content tree nests
 * mentioned `[data-slot="cms-tree-children"]`, which was enough to register the name
 * as published. Deleting the actual attribute then changed nothing, and the guard
 * reported parity that no longer existed.
 */
function withoutComments(string $source): string
{
    // Block comments in both languages, then any line that is only a comment. `//`
    // is matched at the start of a line rather than anywhere, so a `https://` inside
    // a string survives.
    $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
    $source = (string) preg_replace('#<!--.*?-->#s', '', $source);

    return (string) preg_replace('#^\s*(?://|\*).*$#m', '', $source);
}

/**
 * The shared control and button classes have exactly one definition each.
 *
 * They used to live in `@inlayphp/ui-react`, which has no Vue counterpart, so every
 * Vue package wrote its own copy — and the copies drifted where nothing could see
 * it. The slot and custom-property guards compare names rather than the classes
 * carrying them, and a renderer test asserts what a component does rather than how
 * tall it is. Vue's form control had lost `min-h-(--inlay-control-height)` and
 * `aria-invalid:ring-(--inlay-danger)`; Vue's table buttons were 40px tall with
 * `text-base` against React's 36px with `text-sm`.
 *
 * An earlier version of this guard compared the two copies to each other, which kept
 * them in step but left the duplication in place. They now live in
 * `@inlayphp/ui`, and this asserts nothing has quietly declared its own again.
 */
it('defines the shared control and button classes exactly once', function (): void {
    $root = dirname(__DIR__);
    $shared = (string) file_get_contents($root.'/packages/ui/frontend/src/index.ts');

    foreach (['controlClass', 'buttonBaseClass'] as $name) {
        preg_match("/export const {$name}\\s*=\\s*([^\\n]+)/", $shared, $match);

        // A rename must fail here rather than quietly stop comparing anything.
        expect($match[1] ?? null)->not->toBeNull("{$name} was renamed or reformatted in @inlayphp/ui.");
        expect($shared)->toContain("export const {$name}");
    }

    expect($shared)->toContain('min-h-(--inlay-control-height)');

    $redefined = [];
    $inspected = 0;

    foreach (rendererSourceFiles() as $file => $source) {
        if (! preg_match('#packages/([^/]+)/(react|vue)/src/#', $file, $parts) || $parts[1] === 'ui') {
            continue;
        }

        // The media manager maps the two namespaces in opposite directions: React
        // aliases `--media-*` onto `--inlay-*` and styles with the shared classes,
        // Vue aliases `--inlay-*` onto `--media-*` and styles with its own. Sharing
        // the string would mean picking one direction, which is a larger decision
        // than this guard should force.
        if ($parts[1] === 'media-manager' && $parts[2] === 'vue') {
            continue;
        }

        $inspected++;

        // Composing from the import is fine — a denser table cell adds its own
        // padding to it — so what is forbidden is a quoted value, which cannot be
        // deriving from anything.
        //
        // Any name ending in the convention counts, not just the two exported ones.
        // The copy in `TableCell.vue` was called `cellControlClass`, which an
        // exact-name check walked straight past.
        // The import wizard styles from its own `--inlay-import-*` namespace, so its
        // buttons cannot derive from a base built on `--inlay-radius`. Whether that
        // namespace should exist is a separate question from this one; the two
        // renderers' import buttons are compared to each other instead.
        if ($parts[1] === 'import') {
            continue;
        }

        preg_match_all('/(?:const|let)\s+(\w*(?:[Cc]ontrolClass|[Bb]uttonClass|buttonBaseClass))\s*=\s*([\'"`])/', $source, $declarations, PREG_SET_ORDER);

        foreach ($declarations as [, $name, $quote]) {
            if ($quote === '`') {
                // A template literal interpolating the import is the intended shape.
                continue;
            }

            $redefined[] = "{$file} declares {$name} as its own string instead of composing it from @inlayphp/ui";
        }
    }

    expect($inspected)->toBeGreaterThan(20);

    expect($redefined)->toBe([], 'A second definition is a copy, and copies drift where no name-based guard can see it.');
});
