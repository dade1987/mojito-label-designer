import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'jsdom',
    setupFiles: ['./vitest.setup.js'],
    coverage: {
      provider: 'v8',
      include: ['src/utils/**/*.js'],
      exclude: ['src/**/__tests__/**', 'src/main.js', 'src/App.vue'],
      thresholds: {
        lines: 100,
        functions: 100,
        statements: 100,
      },
    },
  },
})
