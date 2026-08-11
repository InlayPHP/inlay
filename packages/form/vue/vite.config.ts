import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'
export default defineConfig({ plugins: [vue()], build: { lib: { entry: 'src/index.ts', formats: ['es'], fileName: 'index' }, rollupOptions: { external: ['vue', '@inertiajs/vue3', '@inlayphp/core', '@inlayphp/ui', '@inlayphp/actions', '@inlayphp/actions-vue', '@tiptap/core', '@tiptap/vue-3', '@tiptap/pm', '@tiptap/starter-kit', '@tiptap/extension-image', '@tiptap/extension-placeholder', '@tiptap/extension-text-align'] } }, test: { environment: 'jsdom', setupFiles: ['./vitest.setup.ts'] } })
