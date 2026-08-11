import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  root: new URL('.', import.meta.url).pathname,
  plugins: [react()],
  resolve: {
    alias: {
      '@inlayphp/actions': new URL('../frontend/src/index.ts', import.meta.url).pathname,
      '@inlayphp/core/testing': new URL('../../core/frontend/src/testing/index.ts', import.meta.url).pathname,
      '@inlayphp/core': new URL('../../core/frontend/src/index.ts', import.meta.url).pathname,
    },
    dedupe: ['react', 'react-dom'],
  },
  test: { environment: 'jsdom', setupFiles: ['./vitest.setup.ts'] },
})
