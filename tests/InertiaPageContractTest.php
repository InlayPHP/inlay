<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/**
 * Every Inertia component name PHP publishes must be renderable by something.
 *
 * `Inertia::render('inlay-cms/globals', …)` with no component of that name in any
 * renderer produces a blank page and no error anywhere: the response is a valid
 * Inertia payload, the route works, the JSON variant works, and the screen is
 * empty. The renderer-parity tests cannot see it either, because they compare
 * React against Vue and both are equally absent.
 *
 * Names an application is expected to supply itself are recorded below with a
 * reason. Everything else must resolve.
 */
$publishedNames = static function (): array {
    $names = [];

    foreach (glob(__DIR__.'/../packages/*/src', GLOB_ONLYDIR) ?: [] as $source) {
        $package = basename(dirname($source));

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/Inertia::render\(\s*'([^']+)'/", (string) file_get_contents($file->getPathname()), $direct);
            preg_match_all("/'(inlay-[a-z-]+\/[a-zA-Z\/-]+)'/", (string) file_get_contents($file->getPathname()), $fallback);

            foreach ([...$direct[1], ...$fallback[1]] as $name) {
                if (Str::startsWith($name, 'inlay-')) {
                    $names[$name] = $package;
                }
            }
        }
    }

    ksort($names);

    return $names;
};

$resolvableNames = static function (): array {
    $resolvable = [];

    foreach (glob(__DIR__.'/../packages/*/{react,vue}/src/index.ts', GLOB_BRACE) ?: [] as $index) {
        preg_match_all("/'(inlay-[a-z-]+\/[a-zA-Z\/-]+)'/", (string) file_get_contents($index), $matches);

        foreach ($matches[1] as $name) {
            $resolvable[$name] = true;
        }
    }

    return $resolvable;
};

/**
 * Names an application supplies, and why the package ships none.
 *
 * Empty, and worth keeping empty: every page name PHP publishes now resolves in
 * both renderers. An entry here is a promise that somebody else will build a
 * screen, which is how five of them ended up rendering nothing.
 *
 * @var array<string, string>
 */
$applicationSupplied = [];

it('publishes Inertia component names a renderer can resolve', function () use ($publishedNames, $resolvableNames, $applicationSupplied): void {
    $published = $publishedNames();
    $resolvable = $resolvableNames();

    // A broken glob would make this vacuous, so the counts are asserted first.
    expect($published)->not->toBeEmpty()
        ->and($resolvable)->not->toBeEmpty();

    $unrenderable = [];
    foreach ($published as $name => $package) {
        if (! isset($resolvable[$name]) && ! isset($applicationSupplied[$name])) {
            $unrenderable[] = "{$name} (published by {$package})";
        }
    }

    expect($unrenderable)->toBe([], 'These names render a blank Inertia page. Ship a component, or record why an application must supply one.');

    // A recorded exception that has since been shipped should be removed, so the
    // list stays a statement of what is true rather than what once was.
    $stale = array_values(array_filter(
        array_keys($applicationSupplied),
        static fn (string $name): bool => isset($resolvable[$name]),
    ));

    expect($stale)->toBe([], 'A renderer now resolves these; drop them from the application-supplied list.');
});
