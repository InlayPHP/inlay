import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    resolve: {
        // The playground links directly to local renderer packages. Force all
        // packages through the application's React instance for both SSR and
        // client rendering so hooks never cross React copies.
        dedupe: ['react', 'react-dom', 'vue', '@inertiajs/core', '@inertiajs/react', '@inertiajs/vue3'],
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx', 'resources/js/vue/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        // Only the Vue entrypoint's own files, so the React pages keep going through
        // the React plugin and the Vue SFCs are not handed to Babel.
        vue({ include: [/resources\/js\/vue\/.*\.vue$/, /node_modules\/@inlayphp\/.*\.vue$/] }),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
});
