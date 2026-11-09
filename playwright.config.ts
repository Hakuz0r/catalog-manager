import { defineConfig } from '@playwright/test'
const port = Number(process.env.PORTFOLIO_TEST_PORT || 5173)
const baseURL = `http://127.0.0.1:${port}`
export default defineConfig({
  testDir: './tests', workers: 1,
  use: { baseURL, headless: true, launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} },
  webServer: { command: `npm run dev -- --port ${port}`, url: baseURL, reuseExistingServer: !process.env.CI },
})
