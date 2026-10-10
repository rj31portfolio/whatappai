import { test, expect } from '@playwright/test';

async function login(page, email = 'owner@demo.test') {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('DemoPass!2026');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/dashboard$/);
}

test('dashboard, Livewire search, tenant switching and mobile layout', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await expect(page.getByRole('heading', { name: 'Make room for growth.' })).toBeVisible();
    await page.screenshot({ path: 'test-results/dashboard-desktop.png', fullPage: true });
    await page.getByRole('link', { name: 'Contacts', exact: true }).click();
    await page.waitForFunction(() => window.Livewire?.all().length > 0);
    const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('livewire'));
    await page.getByLabel('Search contacts').fill('Ananya');
    expect((await response).status()).toBe(200);
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody')).toContainText('Ananya Sharma');
    await expect(page.locator('tbody')).not.toContainText('Vikram Patel');
    await page.getByLabel('Business', { exact: true }).selectOption({ label: 'BrightPath Academy' });
    await page.getByRole('button', { name: 'Switch', exact: true }).click();
    await expect(page.getByText('Here’s what’s happening at BrightPath Academy.')).toBeVisible();
    await page.getByRole('link', { name: 'Contacts', exact: true }).click();
    await expect(page.locator('tbody')).toContainText('Nisha Joshi');
    await expect(page.locator('tbody')).not.toContainText('Ananya Sharma');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/dashboard');
    await expect(page.getByRole('heading', { name: 'Make room for growth.' })).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    expect(overflow).toBe(false);
    await page.screenshot({ path: 'test-results/dashboard-mobile.png', fullPage: true });
    expect(errors).toEqual([]);
});

test('analyst has read-only controls and CSRF rejects a forged post', async ({ page }) => {
    await login(page, 'analyst@demo.test');
    await page.goto('/contacts');
    await expect(page.getByRole('link', { name: 'Add contact' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Export CSV' })).toHaveCount(0);
    const response = await page.request.post('/contacts', { form: { name: 'Forged', source: 'other' } });
    expect(response.status()).toBe(419);
});
