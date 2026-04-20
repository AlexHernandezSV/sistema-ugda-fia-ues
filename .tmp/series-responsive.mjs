import { chromium } from 'playwright';

const baseUrl = 'http://host.docker.internal:8000';
const mailpitUrl = 'http://mailpit:8025/api/v1/messages';
const email = 'admin-ugda@yopmail.com';
const password = 'password';
const views = [
  { name: 'mobile', viewport: { width: 390, height: 844 } },
  { name: 'tablet', viewport: { width: 834, height: 1112 } },
  { name: 'desktop', viewport: { width: 1440, height: 960 } },
];

async function latestMessageId() {
  const response = await fetch(mailpitUrl);
  const data = await response.json();
  const message = (data.messages || []).find((item) => (item.To || []).some((to) => to.Address === email));
  return message?.ID ?? null;
}

async function waitForNewCode(previousId) {
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const response = await fetch(mailpitUrl);
    const data = await response.json();
    const message = (data.messages || []).find((item) => (item.To || []).some((to) => to.Address === email) && item.ID !== previousId);

    if (message) {
      const match = message.Snippet?.match(/(\d{6})/);
      if (match) {
        return match[1];
      }
    }

    await new Promise((resolve) => setTimeout(resolve, 1000));
  }

  throw new Error('No se encontr? un c?digo 2FA nuevo en Mailpit.');
}

for (const view of views) {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: view.viewport });
  const page = await context.newPage();

  const previousId = await latestMessageId();

  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
  await page.locator('input').nth(0).fill(email);
  await page.locator('input[type="password"]').fill(password);
  await page.getByRole('button', { name: /Iniciar Sesi?n/i }).click();

  const code = await waitForNewCode(previousId);
  await page.waitForSelector('input[maxlength="1"]');

  for (const [index, digit] of code.split('').entries()) {
    await page.locator('input[maxlength="1"]').nth(index).fill(digit);
  }

  await page.getByRole('button', { name: /Verificar acceso/i }).click();
  await page.waitForURL('**/dashboard');

  await page.goto(`${baseUrl}/series-documentales`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);

  const newSeriesButton = page.getByRole('button', { name: /Nueva Serie/i });
  if (await newSeriesButton.isVisible()) {
    await newSeriesButton.click();
  }

  const firstToggle = page.locator('.series-panel__toggle').first();
  if (await firstToggle.isVisible()) {
    await firstToggle.click();
  }

  await page.waitForTimeout(500);
  await page.screenshot({
    path: `/var/www/html/storage/app/public/series-${view.name}.png`,
    fullPage: true,
  });

  await browser.close();
}
