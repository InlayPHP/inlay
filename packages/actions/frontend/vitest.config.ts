export default {
  root: new URL('.', import.meta.url).pathname,
  resolve: {
    alias: {
      '@inlayphp/core': new URL('../../core/frontend/src/index.ts', import.meta.url).pathname,
    },
  },
  test: {
    globals: true,
  },
}
