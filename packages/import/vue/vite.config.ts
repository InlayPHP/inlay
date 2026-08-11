import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [vue()],
  build: {
    lib: { entry: 'src/index.ts', formats: ['es'], fileName: 'index' },
    rollupOptions: { external: ['vue', '@inlayphp/ui-vue'] },
  },
  test: { environment: 'jsdom', setupFiles: ['./vitest.setup.ts'] },
})
