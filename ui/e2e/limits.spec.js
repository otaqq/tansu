import { expect, test } from '@playwright/test';
import { png } from './fixtures.js';

test.beforeEach(async ({ request }) => {
  await request.post('/__reset');
});

test('shows the largest file per group and the accepted types', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('#explorer').locator('.limits')).toHaveText(
    'Images up to 2 MB · Documents up to 5 MB · Videos up to 20 MB · JPG, PNG, GIF, WEBP, PDF, MP4, WEBM, SVG',
  );
});

test('shows the quota as a meter', async ({ page }) => {
  await page.goto('/');
  const meter = page.locator('#explorer').getByRole('meter', { name: 'Storage used' });
  await expect(meter).toHaveAttribute('aria-valuenow', '0');
  await page.locator('#explorer').locator('input[type=file]').setInputFiles(png('a.png'));
  // One file of 50
  await expect(meter).toHaveAttribute('aria-valuenow', '2');
});

test('refuses uploads with a reason when the quota is full', async ({ page }) => {
  await page.goto('/?owner=tiny');
  const explorer = page.locator('#explorer');
  const upload = explorer.getByRole('button', { name: 'Upload' });
  await expect(upload).toBeEnabled();

  await explorer.locator('input[type=file]').setInputFiles(png('only.png'));
  await expect(explorer.getByRole('option', { name: 'only.png' })).toBeVisible();
  await expect(upload).toBeDisabled();
  await expect(explorer.getByRole('meter')).toHaveAttribute('aria-valuenow', '100');
  await expect(explorer.getByRole('meter')).toHaveClass(/full/);
  await expect(explorer.locator('.usage .quota-full')).toHaveText('No room left: 1 of 1 files are used. Delete files to upload more.');

  // Deleting makes room again
  await explorer.getByRole('option', { name: 'only.png' }).click();
  await explorer.locator('.details').getByRole('button', { name: 'Delete' }).click();
  await explorer.locator('dialog').getByRole('button', { name: 'Delete' }).click();
  await expect(upload).toBeEnabled();
});

test('speaks Japanese about limits too', async ({ page }) => {
  await page.goto('/?owner=tiny&lang=ja');
  const explorer = page.locator('#explorer');
  await expect(explorer.locator('.limits')).toHaveText('1 ファイル 2 MB まで · JPG, PNG, GIF, WEBP, PDF, MP4, WEBM, SVG');
  await explorer.locator('input[type=file]').setInputFiles(png('only.png'));
  await expect(explorer.locator('.usage .quota-full')).toHaveText('上限に達しています（1 / 1 ファイル）。アップロードするには、ファイルを削除してください。');
});
