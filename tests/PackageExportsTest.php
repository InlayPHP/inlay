<?php

declare(strict_types=1);

/**
 * Every export a package declares must point at a file its build produces.
 *
 * `@inlayphp/actions-vue` declared `./dist/types/src/index.d.ts` and emitted
 * `./dist/types/actions/vue/src/index.d.ts`, because its tsconfig mapped a sibling
 * package to that sibling's source and TypeScript widened the declaration root to
 * cover it. Nothing caught it: tests and typecheck both run from `src`, so a
 * published entry point is only exercised by a consumer building against the
 * package.
 *
 * Every subpath is checked, not only `.`: the `./testing` entry added for the
 * shared renderer contracts was itself declared at the wrong path first.
 *
 * The mapping is asserted rather than the build, so this runs without one — a
 * path pointing nowhere is wrong whether or not `dist` exists.
 */
it('declares entry points that its own build emits', function (): void {
    $manifests = glob(__DIR__.'/../packages/*/*/package.json') ?: [];

    // A broken glob would make this vacuous.
    expect(count($manifests))->toBeGreaterThan(10);

    $broken = [];
    $checked = 0;

    foreach ($manifests as $file) {
        $directory = dirname($file);
        $manifest = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $exports = $manifest['exports'] ?? null;

        if (! is_dir($directory.'/dist')) {
            // Nothing built to contradict a declared entry point.
            continue;
        }

        if (! is_array($exports)) {
            // A package can use only top-level main/module/types fields.
            $exports = [];
        }

        $name = basename(dirname($directory)).'/'.basename($directory);

        foreach (['main', 'module', 'types'] as $field) {
            $target = $manifest[$field] ?? null;

            if (! is_string($target)) {
                continue;
            }

            $checked++;

            if (! file_exists($directory.'/'.ltrim($target, './'))) {
                $broken[] = "{$name} declares {$field} as {$target}, which its build does not produce";
            }
        }

        foreach ($exports as $subpath => $conditions) {
            foreach ((array) $conditions as $condition => $target) {
                if (! is_string($target)) {
                    continue;
                }

                $checked++;

                if (file_exists($directory.'/'.ltrim($target, './'))) {
                    continue;
                }

                $broken[] = "{$name} declares {$subpath} {$condition} as {$target}, which its build does not produce";
            }
        }
    }

    // The PHP matrix intentionally runs before frontend artifacts are built on
    // a clean checkout. The dedicated post-build export job enforces this
    // contract; this pass should still validate any artifacts that are present.
    if ($checked === 0) {
        expect($broken)->toBe([], 'A consumer importing these gets an unresolved module or an implicit any.');

        return;
    }

    // Fewer than this would mean the walk quietly stopped finding exports.
    expect($checked)->toBeGreaterThan(30);

    expect($broken)->toBe([], 'A consumer importing these gets an unresolved module or an implicit any.');
});

it('documents every Composer and frontend package directory', function (): void {
    $manifests = array_merge(
        glob(__DIR__.'/../packages/*/composer.json') ?: [],
        glob(__DIR__.'/../packages/*/package.json') ?: [],
        glob(__DIR__.'/../packages/*/*/package.json') ?: [],
    );

    // A broken walk should never make the documentation requirement vacuous.
    expect(count($manifests))->toBeGreaterThan(30);

    $missing = [];

    foreach ($manifests as $manifest) {
        $directory = dirname($manifest);

        if (! is_file($directory.'/README.md')) {
            $missing[] = $directory;
        }
    }

    expect(array_values(array_unique($missing)))->toBe([], 'Every published package needs an install and API README.');
});

it('splits every Composer package into a standalone repository', function (): void {
    $workflow = (string) file_get_contents(__DIR__.'/../.github/workflows/split.yml');
    preg_match_all("/local_path:\s*'([^']+)'/", $workflow, $matches);
    preg_match_all("/split_repository:\s*'([^']+)'/", $workflow, $repositoryMatches);

    $listed = array_values(array_unique($matches[1] ?? []));
    $packages = array_map(
        static fn (string $manifest): string => basename(dirname($manifest)),
        array_filter(
            glob(__DIR__.'/../packages/*/composer.json') ?: [],
            static fn (string $manifest): bool => ! str_starts_with(basename(dirname($manifest)), 'cms'),
        ),
    );

    sort($listed);
    sort($packages);

    expect($listed)->toBe($packages, 'Every Composer package must be covered by the split workflow.');

    $script = (string) file_get_contents(__DIR__.'/../bin/create-split-repos.sh');
    $missingRepositories = [];

    foreach (array_values(array_unique($repositoryMatches[1] ?? [])) as $repository) {
        if (! str_contains($script, "create {$repository} ")) {
            $missingRepositories[] = $repository;
        }
    }

    expect($missingRepositories)->toBe([], 'Every split repository must be provisioned by the setup script.');
});

it('keeps npm publishing explicit and provenance-ready', function (): void {
    $root = json_decode(
        (string) file_get_contents(__DIR__.'/../package.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $workflow = (string) file_get_contents(__DIR__.'/../.github/workflows/publish-npm.yml');

    expect($root['scripts']['publish:npm'] ?? null)
        ->toBe("pnpm -r --filter '@inlayphp/*' publish --access public --no-git-checks")
        ->and($root['scripts']['publish:npm:dry-run'] ?? null)
        ->toBe("pnpm -r --filter '@inlayphp/*' publish --access public --no-git-checks --dry-run")
        ->and($workflow)
        ->toContain('pnpm publish:npm')
        ->toContain('NPM_CONFIG_PROVENANCE: true')
        ->toContain('id-token: write')
        ->toContain('NODE_AUTH_TOKEN: ${{ secrets.NPM_TOKEN }}');

    $manifests = array_merge(
        glob(__DIR__.'/../packages/*/package.json') ?: [],
        glob(__DIR__.'/../packages/*/*/package.json') ?: [],
    );
    $publicNames = [];
    $missingMetadata = [];

    foreach ($manifests as $manifest) {
        $package = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);

        if (($package['private'] ?? false) === true) {
            continue;
        }

        $publicNames[] = $package['name'] ?? basename(dirname($manifest));
        $directory = str_replace(__DIR__.'/../', '', dirname($manifest));
        $repository = $package['repository'] ?? null;

        if (($repository['type'] ?? null) !== 'git'
            || ($repository['url'] ?? null) !== 'https://github.com/InlayPHP/inlay.git'
            || ($repository['directory'] ?? null) !== $directory
            || ($package['homepage'] ?? null) !== 'https://github.com/InlayPHP/inlay/tree/main/'.$directory
            || ($package['bugs']['url'] ?? null) !== 'https://github.com/InlayPHP/inlay/issues'
            || ($package['publishConfig']['access'] ?? null) !== 'public'
            || ($package['engines']['node'] ?? null) !== '>=20') {
            $missingMetadata[] = $directory;
        }
    }

    expect(count($publicNames))->toBeGreaterThan(30);
    expect(array_filter($publicNames, static fn (string $name): bool => ! str_starts_with($name, '@inlayphp/')))
        ->toBe([], 'The recursive npm release command must never publish an unrelated package.');
    expect($missingMetadata)->toBe([], 'Every public npm package needs source, issue, access, and engine metadata.');
});

it('builds frontend artifacts before the clean-runner publish gates', function (): void {
    $workflow = (string) file_get_contents(__DIR__.'/../.github/workflows/publish-npm.yml');

    $build = strpos($workflow, '- run: pnpm build');
    $typecheck = strpos($workflow, '- run: pnpm typecheck');
    $tests = strpos($workflow, '- run: pnpm test:frontend');

    expect($build)->not->toBeFalse()
        ->and($typecheck)->not->toBeFalse()
        ->and($tests)->not->toBeFalse()
        ->and($build)->toBeLessThan($typecheck)
        ->and($build)->toBeLessThan($tests);
});

it('externalizes renderer peers from published bundles', function (): void {
    $expected = [
        'packages/actions/vue/vite.config.ts' => ['@inlayphp/ui'],
        'packages/form/react/package.json' => ['--external @inlayphp/core'],
        'packages/form/vue/vite.config.ts' => ['@inlayphp/core'],
        'packages/infolist/vue/vite.config.ts' => ['@inlayphp/core'],
        'packages/media-manager/vue/vite.config.ts' => ['@inlayphp/ui'],
        'packages/panel/vue/vite.config.ts' => ['@inlayphp/forms-vue'],
        'packages/permission-manager/vue/vite.config.ts' => ['@inlayphp/forms-vue', '@inlayphp/tables-vue'],
        'packages/resources/vue/vite.config.ts' => ['@inlayphp/actions'],
        'packages/two-factor-authentication/vue/vite.config.ts' => ['@inlayphp/forms-vue'],
    ];

    $missing = [];

    foreach ($expected as $relativePath => $externals) {
        $contents = (string) file_get_contents(__DIR__.'/../'.$relativePath);

        foreach ($externals as $external) {
            if (! str_contains($contents, $external)) {
                $missing[] = "{$relativePath}: {$external}";
            }
        }
    }

    expect($missing)->toBe([], 'A renderer peer must stay external so consumers provide one compatible copy.');
});

it('keeps the root installer source inside the Composer lint gate', function (): void {
    $composer = json_decode(
        (string) file_get_contents(__DIR__.'/../composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts']['lint'] ?? null)
        ->toContain('find src packages tests')
        ->toContain("-name '*.php'");

    $files = glob(__DIR__.'/../src/*.php') ?: [];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file), $output, $status);

        expect($status)->toBe(0, "Root installer source failed PHP lint: {$file}");
    }
});
