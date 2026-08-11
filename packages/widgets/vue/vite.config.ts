import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({ plugins: [vue()], build: { lib: { entry: 'src/index.ts', formats: ['es'], fileName: 'index' }, rollupOptions: { external: ['vue', '@inertiajs/vue3', '@inlayphp/actions', '@inlayphp/actions-vue', '@inlayphp/core', '@inlayphp/forms-vue', '@inlayphp/tables-vue'] } }, test: { environment: 'jsdom', setupFiles: ['./vitest.setup.ts'] } })
