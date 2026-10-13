import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './tests/pos',
  fullyParallel: false,
  use: { baseURL: 'http://127.0.0.1:5179', channel: 'chrome', headless: true, trace: 'retain-on-failure' },
  webServer: {
    command: 'npm run dev -- --host 127.0.0.1 --port 5179 --strictPort',
    url: 'http://127.0.0.1:5179',
    env: { VITE_API_URL: 'http://127.0.0.1:5179' },
    reuseExistingServer: false,
  },
})
