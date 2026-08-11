import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [vue()],
  build: {
    lib: { entry: 'src/index.ts', formats: ['es'], fileName: 'index' },
    rollupOptions: { external: ['vue', '@inlayphp/ui', '@inlayphp/ui-vue', '@inlayphp/forms-vue', '@inlayphp/tables-vue', '@inertiajs/vue3'] },
  },
  // globals: true so Testing Library registers automatic cleanup between tests.
  test: { environment: 'jsdom', globals: true, setupFiles: ['./vitest.setup.ts'] },
})
