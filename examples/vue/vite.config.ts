import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  build: {
    rollupOptions: {
      output: {
        // Keep the example's form and table renderers independently cacheable.
        manualChunks(id) {
          if (id.includes('/node_modules/@inertiajs/')) return 'inertia'
          if (id.includes('/node_modules/@vue/')) return 'vue'
          if (id.includes('/node_modules/nprogress')) return 'progress'
          if (id.includes('/node_modules/')) return 'vendor'
          if (id.includes('/packages/form/')) return 'inlay-form'
          if (id.includes('/packages/table/')) return 'inlay-table'
          return undefined
        },
      },
    },
  },
})
