import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    rollupOptions: {
      output: {
        // Keep the example's form and table renderers independently cacheable.
        // They are intentionally large, and shipping them in the HTML entry
        // makes every small example change invalidate both bundles.
        manualChunks(id) {
          if (id.includes('/node_modules/@inertiajs/')) return 'inertia'
          if (id.includes('/node_modules/react') || id.includes('/node_modules/scheduler/')) return 'react'
          if (id.includes('/node_modules/nprogress')) return 'progress'
          if (id.includes('/node_modules/')) return 'vendor'
          if (id.includes('/packages/form/')) return 'inlay-form'
          if (id.includes('/packages/table/')) return 'inlay-table'
          return undefined
        },
      },
    },
  },
  resolve: {
    alias: {
      '@inlayphp/forms-react': new URL('../../packages/form/react/src/index.ts', import.meta.url).pathname,
      '@inlayphp/tables-react': new URL('../../packages/table/react/src/index.ts', import.meta.url).pathname,
    },
  },
})
