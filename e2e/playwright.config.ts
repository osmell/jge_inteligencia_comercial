import { defineConfig, devices } from '@playwright/test'

/**
 * Pruebas de extremo a extremo contra una instalación en marcha (frontend en E2E_BASE_URL y API en :8000) con los usuarios de
 * desarrollo (DevDataSeeder). Por omisión solo LEEN datos; las que crean registros exigen E2E_ALLOW_WRITES=1 para no ensuciar
 * una base de desarrollo. Ver e2e/README.md.
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  timeout: 30_000,
  expect: { timeout: 7_000 },
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    locale: 'es',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
