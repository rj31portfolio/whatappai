import { test, expect } from '@playwright/test';

test('owner can open every populated panel and use visual workflow controls', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/login');
    await page.getByLabel('Email address').fill('owner@demo.test');
    await page.getByLabel('Password', { exact: true }).fill('DemoPass!2026');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/dashboard$/);
    for (const path of ['/inbox', '/campaigns', '/knowledge', '/ai', '/automations', '/analytics', '/team', '/billing', '/settings', '/account', '/integrations', '/api-access', '/reminders']) {
        const response = await page.goto(path);
        expect(response.status(), path).toBe(200);
        await expect(page.locator('main h1').first()).toBeVisible();
    }
    await page.goto('/automations');
    await expect(page.locator('.workflow-editor-step')).toHaveCount(1);
    await page.getByRole('button', { name: '+ Add step' }).click();
    await expect(page.locator('.workflow-editor-step')).toHaveCount(2);
    await page.locator('.workflow-editor-step').last().getByRole('button', { name: 'Remove' }).click();
    await expect(page.locator('.workflow-editor-step')).toHaveCount(1);
    await page.screenshot({ path: 'test-results/automations-panel.png', fullPage: true });
    await page.goto('/campaigns');
    await expect(page.getByRole('heading', { name: 'Demo welcome draft' })).toBeVisible();
    await page.screenshot({ path: 'test-results/campaigns-panel.png', fullPage: true });
    await page.goto('/settings');
    await expect(page.getByLabel('Inbound reply mode')).toBeVisible();
    expect(errors).toEqual([]);
});

test('Super Admin panels display management controls without provider secrets', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('admin@demo.test');
    await page.getByLabel('Password', { exact: true }).fill('DemoPass!2026');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/admin$/);
    for (const name of ['Provision a business', 'Create subscription plan', 'Maintenance and feature flags', 'User access']) {
        await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
    }
    await page.screenshot({ path: 'test-results/super-admin-panel.png', fullPage: true });
});
