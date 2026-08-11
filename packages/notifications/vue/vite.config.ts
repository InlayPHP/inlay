import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  root: new URL('.', import.meta.url).pathname,
  plugins: [vue()],
  build: {
    lib: { entry: 'src/index.ts', formats: ['es'], fileName: 'index' },
    rollupOptions: { external: ['vue', '@inlayphp/core', '@inlayphp/ui', '@inlayphp/notifications'] },
  },
  test: { environment: 'jsdom', setupFiles: ['./vitest.setup.ts'] },
})
