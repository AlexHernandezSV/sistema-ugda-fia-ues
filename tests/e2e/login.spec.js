import { expect, test } from '@playwright/test';

test('la aplicacion responde y muestra el login', async ({ page, request }) => {
    const health = await request.get('/up');
    expect(health.ok()).toBeTruthy();

    await page.goto('/login', { waitUntil: 'domcontentloaded' });

    await expect(page.getByText(/Sistema de Gesti[oó]n Documental/i)).toBeVisible();
    await expect(page.getByRole('button', { name: /Iniciar Sesi[oó]n/i })).toBeVisible();
});
