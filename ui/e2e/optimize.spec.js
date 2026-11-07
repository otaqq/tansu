import { expect, test } from '@playwright/test';
import { animatedGif, bigPng, mp4, pdf } from './fixtures.js';

test.beforeEach(async ({ request }) => {
  await request.post('/__reset');
});

const naturalWidth = (locator) => locator.evaluate((img) => (img.complete ? img.naturalWidth : new Promise((resolve) => {
  img.addEventListener('load', () => resolve(img.naturalWidth), { once: true });
})));

test('uploads an image resized by a preset', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');
  const select = explorer.getByRole('combobox', { name: 'Optimize images' });
  await expect(select).toHaveValue('none');
  await expect(select.locator('option')).toHaveText(['Keep the original', 'Thumbnail (64×64 px · quality 80%)', 'Smaller file (quality 60%)', 'Custom']);

  await select.selectOption('thumb');
  await explorer.locator('input[type=file]').setInputFiles(bigPng('wide.png', 400, 200));
  await explorer.getByRole('option', { name: 'wide.png' }).click();
  expect(await naturalWidth(explorer.locator('.details img'))).toBe(64);
  // The choice stays for the next upload
  await expect(select).toHaveValue('thumb');
});

test('uploads with custom values, and keeps the original by default', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');

  await explorer.locator('input[type=file]').setInputFiles(bigPng('original.png', 400, 200));
  await explorer.getByRole('option', { name: 'original.png' }).click();
  expect(await naturalWidth(explorer.locator('.details img'))).toBe(400);

  await explorer.getByRole('combobox', { name: 'Optimize images' }).selectOption('custom');
  const width = explorer.getByRole('spinbutton', { name: 'Width' });
  await expect(width).toHaveValue('1200');
  await width.fill('100');
  await width.blur();
  await explorer.getByRole('checkbox', { name: 'Compress' }).uncheck();
  await expect(explorer.getByRole('spinbutton', { name: 'Quality' })).toBeDisabled();
  await explorer.locator('input[type=file]').setInputFiles(bigPng('custom.png', 400, 200));
  await explorer.getByRole('option', { name: 'custom.png' }).click();
  expect(await naturalWidth(explorer.locator('.details img'))).toBe(100);
});

test('warns until closed when an optimization is skipped', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');
  await explorer.getByRole('combobox', { name: 'Optimize images' }).selectOption('custom');
  await explorer.getByRole('spinbutton', { name: 'Width' }).fill('2');
  await explorer.getByRole('spinbutton', { name: 'Width' }).blur();

  await explorer.locator('input[type=file]').setInputFiles(animatedGif('anim.gif'));
  const warning = explorer.getByRole('alert');
  await expect(warning).toHaveText(/Stored without optimizing: animated images are kept as they are\./);
  await expect(explorer.getByRole('option', { name: 'anim.gif' })).toBeVisible();
  await page.waitForTimeout(3500);
  await expect(warning).toBeVisible();
  await warning.getByRole('button', { name: 'Close' }).click();
  await expect(warning).toHaveCount(0);
});

test('explains the limits in a hint, in Japanese too', async ({ page }) => {
  await page.goto('/?lang=ja');
  const explorer = page.locator('#explorer');
  const hint = explorer.getByRole('button', { name: '画像の最適化について' });
  await expect(hint).toHaveAttribute('title', /品質は JPEG と WebP だけに効きます/);
  await hint.click();
  await expect(explorer.locator('.optimize-hint')).toBeVisible();
  await expect(explorer.getByRole('combobox', { name: '画像の最適化' }).locator('option').nth(1)).toHaveText('サムネイル (64×64 px · 品質 80%)');
});

test('starts on the choice given by the optimize attribute', async ({ page }) => {
  await page.goto('/');
  await page.locator('#explorer').evaluate((element) => element.setAttribute('optimize', 'compact'));
  await expect(page.locator('#explorer').getByRole('combobox', { name: 'Optimize images' })).toHaveValue('compact');
});

test('shows documents and videos with their own icon and preview', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');
  await explorer.locator('input[type=file]').setInputFiles([pdf('report.pdf'), mp4('clip.mp4')]);

  await explorer.getByRole('option', { name: 'report.pdf' }).click();
  const details = explorer.locator('.details');
  await expect(details).toContainText('application/pdf');
  await expect(details.getByRole('link', { name: 'Open in a new tab' })).toHaveAttribute('target', '_blank');

  await explorer.getByRole('option', { name: 'clip.mp4' }).click();
  await expect(details.locator('video')).toHaveAttribute('controls', '');
  await expect(explorer.getByRole('option', { name: 'clip.mp4' }).locator('svg')).toBeVisible();
});
