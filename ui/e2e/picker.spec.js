import { expect, test } from '@playwright/test';
import { png } from './fixtures.js';

test.beforeEach(async ({ request }) => {
  await request.post('/__reset');
});

test('chooses a file in the modal and submits its path with the form', async ({ page }) => {
  await page.goto('/');
  const picker = page.locator('#picker');
  await expect(picker.getByText('No image')).toBeVisible();
  await expect(picker.getByRole('button', { name: 'Remove' })).toBeHidden();

  await picker.getByRole('button', { name: 'Choose an image' }).click();
  const modal = picker.locator('dialog.modal');
  await expect(modal).toBeVisible();
  await modal.locator('tansu-explorer input[type=file]').setInputFiles(png('logo.png'));
  await modal.getByRole('option', { name: 'logo.png' }).dblclick();

  await expect(modal).toBeHidden();
  await expect(picker).toHaveAttribute('value', '/logo.png');
  await expect(picker.locator('.preview img')).toHaveJSProperty('naturalWidth', 1);
  await expect(picker.locator('.file')).toHaveText('logo.png');

  await page.getByRole('button', { name: 'Submit' }).click();
  await expect(page.locator('#submitted')).toHaveText('{"image":"/logo.png"}');
});

test('chooses with the Choose button and opens in the folder of the value', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');
  await explorer.getByRole('button', { name: 'New folder' }).click();
  await explorer.locator('dialog').getByRole('textbox').fill('logos');
  await explorer.locator('dialog').getByRole('button', { name: 'OK' }).click();
  await explorer.getByRole('option', { name: 'logos' }).dblclick();
  await explorer.locator('input[type=file]').setInputFiles([png('a.png'), png('b.png')]);
  await expect(explorer.getByRole('option', { name: 'b.png' })).toBeVisible();

  const picker = page.locator('#picker');
  await picker.getByRole('button', { name: 'Choose an image' }).click();
  const modal = picker.locator('dialog.modal');
  await modal.getByRole('option', { name: 'logos' }).dblclick();
  await modal.getByRole('option', { name: 'a.png' }).click();
  await modal.getByRole('button', { name: 'Choose' }).click();
  await expect(picker).toHaveAttribute('value', '/logos/a.png');

  // Opening again starts in /logos
  await picker.getByRole('button', { name: 'Choose an image' }).click();
  await expect(modal.locator('tansu-explorer')).toHaveAttribute('path', '/logos');
  await expect(modal.getByRole('option', { name: 'b.png' })).toBeVisible();
  await modal.getByRole('button', { name: 'Close' }).click();
  await expect(picker).toHaveAttribute('value', '/logos/a.png');
});

test('removing clears the value, reset restores it', async ({ page }) => {
  await page.goto('/');
  const picker = page.locator('#picker');
  await picker.getByRole('button', { name: 'Choose an image' }).click();
  const modal = picker.locator('dialog.modal');
  await modal.locator('tansu-explorer input[type=file]').setInputFiles(png('a.png'));
  await modal.getByRole('option', { name: 'a.png' }).dblclick();
  const changes = await page.evaluateHandle(() => {
    const list = [];
    document.getElementById('picker').addEventListener('change', () => list.push(document.getElementById('picker').value));
    return list;
  });

  await picker.getByRole('button', { name: 'Remove' }).click();
  await expect(picker.getByText('No image')).toBeVisible();
  await page.getByRole('button', { name: 'Submit' }).click();
  await expect(page.locator('#submitted')).toHaveText('{}');
  expect(await changes.jsonValue()).toEqual(['']);

  await picker.getByRole('button', { name: 'Choose an image' }).click();
  await modal.getByRole('option', { name: 'a.png' }).dblclick();
  await page.getByRole('button', { name: 'Reset' }).click();
  await expect(picker).not.toHaveAttribute('value');
  await expect(picker.getByText('No image')).toBeVisible();
});

test('required blocks submitting without a value', async ({ page }) => {
  await page.goto('/');
  await page.locator('#picker').evaluate((picker) => picker.setAttribute('required', ''));

  await page.getByRole('button', { name: 'Submit' }).click();
  await expect(page.locator('#submitted')).toHaveText('');
  expect(await page.locator('#form').evaluate((form) => form.checkValidity())).toBe(false);
});
