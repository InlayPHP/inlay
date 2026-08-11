<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * The Vue renderers, served by this Laravel application.
 *
 * Until these routes existed, nothing ran the Vue packages against a real server:
 * every renderer comparison happened in jsdom against a hand-written payload. The
 * defects that had actually reached a screenshot were the ones only a browser shows —
 * an icon name printed as text, a control computed to `min-height: 0`, error text
 * computed to black — and each was fixed in Vue's source without being observed there.
 *
 * The value is that the server half is identical. `/standalone/tables` and
 * `/vue/standalone/tables` run the same page class and serialize the same payload, so
 * anything that differs between them is the renderer and cannot be the server.
 */
beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('pins the Vue playground adapter to Inertia 3', function (): void {
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($package['dependencies']['@inertiajs/vue3'] ?? null)->toBe('^3.0.0');
});

it('serves the same payload to both renderers, and only the root view differs', function (): void {
    $react = $this->actingAs($this->user)->get('/standalone/tables');
    $vue = $this->actingAs($this->user)->get('/vue/standalone/tables');

    $react->assertOk();
    $vue->assertOk();

    // Same Inertia page name and same table contract from the same page class.
    foreach ([$react, $vue] as $response) {
        $response->assertInertia(fn (Assert $page) => $page
            ->component('standalone/table')
            ->where('table.contract', 'inlay.tables.v1'));
    }

    // The one difference is which bundle mounts. Asserted through the manifest,
    // because a built asset is served under a hashed name rather than its source path.
    $manifest = json_decode((string) file_get_contents(base_path('public/build/manifest.json')), true);
    $reactBundle = $manifest['resources/js/app.tsx']['file'];
    $vueBundle = $manifest['resources/js/vue/app.ts']['file'];

    $react->assertSee($reactBundle, false);
    $vue->assertSee($vueBundle, false);
    $vue->assertDontSee($reactBundle, false);
});

it('serves the form through the Vue entrypoint with its validation transport intact', function (): void {
    $this->actingAs($this->user)->get('/vue/standalone/forms')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('standalone/form')
            ->where('form.contract', 'inlay.forms.v1'));

    // The action a Vue form posts to is the same route the React one posts to, so a
    // submission failing here would be a renderer problem rather than a routing one.
    $this->actingAs($this->user)->post('/vue/standalone/forms', [])
        ->assertSessionHasErrors(['name', 'email']);
});

it('resolves package-owned two-factor pages through the Vue entrypoint', function (): void {
    $this->get('/vue/standalone/fortify-challenge')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inlay-two-factor/challenge', false)
            ->where('inlayPage.type', 'two-factor-challenge')
            ->where('challengeForm.action', '/two-factor-challenge'));
});

it('resolves the media manager page from its Vue package registry', function (): void {
    $source = (string) file_get_contents(resource_path('js/vue/app.ts'));

    expect($source)
        ->toContain("import { mediaManagerPages } from '@inlayphp/media-manager-vue';")
        ->toContain('mediaManagerPages[name as keyof typeof mediaManagerPages]');

    $administrator = User::factory()->create(['role' => 'admin', 'active' => true]);
    $administrator->assignRole(Role::findOrCreate('super-admin'));

    $this->actingAs($administrator)->get('/vue/media')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inlay-media-manager/index')
            ->where('media.contract', 'inlay.media-manager.v1')
            ->where('inlayPanel.contract', 'inlay.panels.v1'));
});

it('builds a Vue bundle for every page the Vue entrypoint can resolve', function (): void {
    $manifest = json_decode((string) file_get_contents(base_path('public/build/manifest.json')), true);

    // A page component with no built bundle renders blank, which is the failure the
    // React side already guards against for its own pages.
    expect($manifest)->toHaveKey('resources/js/vue/app.ts');

    // `glob` does not recurse on `**`, so the pages one directory down were missed and
    // this loop ran zero times — a check that passes by examining nothing.
    $pages = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js/vue/pages'), FilesystemIterator::SKIP_DOTS));
    $checked = 0;

    foreach ($pages as $page) {
        if (! $page instanceof SplFileInfo || $page->getExtension() !== 'vue') {
            continue;
        }

        $checked++;
        $entry = str_replace(base_path().'/', '', $page->getPathname());

        // A page component with no bundle renders blank. `toHaveKey` reads a second
        // argument as the expected value rather than a message, so the reason is a
        // comment rather than an argument.
        expect($manifest)->toHaveKey($entry);
    }

    expect($checked)->toBeGreaterThan(1);
});

it('answers the landing page without any seeded content', function (): void {
    // The root index is a plain view with no database behind it, so a fresh
    // deployment always has a useful landing page.
    $this->get('/')
        ->assertOk()
        ->assertSee('Inlay playground')
        ->assertSee('/vue/standalone/tables', false);
});
