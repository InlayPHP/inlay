import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  root: new URL('.', import.meta.url).pathname,
  plugins: [vue()],
  resolve: { alias: { '@inlayphp/actions': new URL('../frontend/src/index.ts', import.meta.url).pathname } },
  build: {
    lib: { entry: 'src/index.ts', formats: ['es'], fileName: 'index' },
    rollupOptions: { external: ['vue', '@inlayphp/actions', '@inlayphp/core', '@inlayphp/ui'] },
  },
  test: { environment: 'jsdom', setupFiles: ['./vitest.setup.ts'] },
})
