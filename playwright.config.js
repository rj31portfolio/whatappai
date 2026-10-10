import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    workers: 1,
    use: {
        baseURL: 'http://127.0.0.1:8000',
        channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome',
        screenshot: 'only-on-failure',
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000/login',
        reuseExistingServer: !process.env.CI,
        timeout: 30000,
    },
});
