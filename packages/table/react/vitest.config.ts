import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  root: new URL('.', import.meta.url).pathname,
  plugins: [react()],
  resolve: {
    alias: {
      '@inlayphp/actions': new URL('../../actions/frontend/src/index.ts', import.meta.url).pathname,
      '@inlayphp/actions-react': new URL('../../actions/react/src/index.ts', import.meta.url).pathname,
    },
    dedupe: ['react', 'react-dom'],
  },
  test: { environment: 'jsdom', setupFiles: ['./vitest.setup.ts'] },
})
