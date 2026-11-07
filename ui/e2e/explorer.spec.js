import { expect, test } from '@playwright/test';
import { png, svg, text } from './fixtures.js';

test.beforeEach(async ({ request }) => {
  await request.post('/__reset');
});

test('uploads, previews, renames and deletes a file', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');
  await expect(explorer.getByText('This folder is empty.')).toBeVisible();

  await explorer.locator('input[type=file]').setInputFiles(png('shop photo.png'));
  const tile = explorer.getByRole('option', { name: 'shop-photo.png' });
  await expect(tile).toBeVisible();
  await expect(explorer.locator('.usage')).toContainText('1 of 50 files');

  await tile.click();
  const details = explorer.locator('.details');
  await expect(details.getByRole('heading')).toHaveText('shop-photo.png');
  await expect(details).toContainText('image/png');
  await expect(details.getByRole('img')).toHaveJSProperty('naturalWidth', 1);

  await details.getByRole('button', { name: 'Rename' }).click();
  const dialog = explorer.locator('dialog');
  await expect(dialog.getByRole('textbox')).toHaveValue('shop-photo');
  await dialog.getByRole('textbox').fill('front door');
  await dialog.getByRole('button', { name: 'OK' }).click();
  await expect(explorer.getByRole('option', { name: 'front-door.png' })).toBeVisible();
  await expect(details.getByRole('heading')).toHaveText('front-door.png');

  await details.getByRole('button', { name: 'Delete' }).click();
  await expect(dialog).toContainText('Delete "front-door.png"?');
  await dialog.getByRole('button', { name: 'Delete' }).click();
  await expect(explorer.getByText('This folder is empty.')).toBeVisible();
  await expect(explorer.locator('.usage')).toContainText('0 of 50 files');
});

test('creates, opens, renames and deletes folders', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');
  const dialog = explorer.locator('dialog');
  const tree = explorer.locator('.tree');

  await explorer.getByRole('button', { name: 'New folder' }).click();
  await dialog.getByRole('textbox').fill('photos');
  await dialog.getByRole('button', { name: 'OK' }).click();
  await expect(tree.getByRole('button', { name: 'photos' })).toBeVisible();

  await explorer.locator('.grid').getByRole('option', { name: 'photos' }).dblclick();
  await expect(explorer).toHaveAttribute('path', '/photos');
  await expect(explorer.locator('.crumbs')).toContainText('photos');
  await explorer.locator('input[type=file]').setInputFiles(png('a.png'));
  await expect(explorer.getByRole('option', { name: 'a.png' })).toBeVisible();

  await explorer.locator('.crumbs').getByRole('button', { name: 'All files' }).click();
  await explorer.getByRole('option', { name: 'photos' }).click();
  // The folder icon in the details is styled like the one in the grid (not a huge black shape)
  const icon = await explorer.locator('.details .preview svg').boundingBox();
  const preview = await explorer.locator('.details .preview').boundingBox();
  expect(icon.width).toBeLessThanOrEqual(preview.width / 2 + 1);
  await explorer.locator('.details').getByRole('button', { name: 'Rename' }).click();
  await dialog.getByRole('textbox').fill('images');
  await dialog.getByRole('button', { name: 'OK' }).click();
  await expect(tree.getByRole('button', { name: 'images' })).toBeVisible();

  await tree.getByRole('button', { name: 'images' }).click();
  await expect(explorer.getByRole('option', { name: 'a.png' })).toBeVisible();

  await explorer.locator('.crumbs').getByRole('button', { name: 'All files' }).click();
  await explorer.getByRole('option', { name: 'images' }).click();
  await explorer.locator('.details').getByRole('button', { name: 'Delete' }).click();
  await expect(dialog).toContainText('and everything in it');
  await dialog.getByRole('button', { name: 'Delete' }).click();
  await expect(explorer.getByText('This folder is empty.')).toBeVisible();
  await expect(tree.getByRole('button', { name: 'images' })).toHaveCount(0);
});

test('cancelling a dialog changes nothing', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');
  await explorer.getByRole('button', { name: 'New folder' }).click();
  await explorer.locator('dialog').getByRole('textbox').fill('nope');
  await page.keyboard.press('Escape');

  await expect(explorer.locator('dialog')).not.toBeVisible();
  await expect(explorer.getByText('This folder is empty.')).toBeVisible();
});

test('shows errors from the server', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');
  const errors = [];
  await page.exposeFunction('recordError', (code) => errors.push(code));
  await page.evaluate(() => document.addEventListener('tansu-error', (e) => window.recordError(e.detail.error.code)));

  // The name says PNG, the content does not: the server refuses it
  await explorer.locator('input[type=file]').setInputFiles(text('fake.png'));
  await expect(explorer.locator('.uploads')).toContainText('This type of file cannot be uploaded (JPG, PNG, GIF, WEBP, SVG)');

  for (let i = 0; i < 2; i++) {
    await explorer.getByRole('button', { name: 'New folder' }).click();
    await explorer.locator('dialog').getByRole('textbox').fill('same');
    await explorer.locator('dialog').getByRole('button', { name: 'OK' }).click();
  }
  await expect(explorer.getByRole('status')).toHaveText('That name is already taken.');
  expect(errors).toEqual(['type_not_allowed', 'name_taken']);
});

test('checks the type before sending', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');
  const requests = [];
  page.on('request', (request) => request.method() === 'POST' && requests.push(request.url()));

  await explorer.locator('input[type=file]').setInputFiles(text('notes.txt'));
  await expect(explorer.locator('.uploads')).toContainText('cannot be uploaded');
  expect(requests).toEqual([]);
});

test('uploads several files by drag and drop, sanitizing SVG', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');
  const files = [png('one.png'), svg('logo.svg')];
  const dataTransfer = await page.evaluateHandle((files) => {
    const transfer = new DataTransfer();
    for (const { name, mimeType, base64 } of files) {
      transfer.items.add(new File([Uint8Array.from(atob(base64), (c) => c.charCodeAt(0))], name, { type: mimeType }));
    }
    return transfer;
  }, files.map(({ name, mimeType, buffer }) => ({ name, mimeType, base64: buffer.toString('base64') })));

  const main = explorer.locator('.main');
  await main.dispatchEvent('dragenter', { dataTransfer });
  await expect(explorer.locator('.drop')).toBeVisible();
  await main.dispatchEvent('drop', { dataTransfer });

  await expect(explorer.getByRole('option', { name: 'one.png' })).toBeVisible();
  await expect(explorer.getByRole('option', { name: 'logo.svg' })).toBeVisible();
  await expect(explorer.locator('.drop')).toBeHidden();
  const stored = await page.request.get('/api/raw?path=/logo.svg');
  expect(await stored.text()).not.toContain('script');
});

test('works with the keyboard', async ({ page }) => {
  await page.goto('/');
  const explorer = page.locator('#explorer');
  await explorer.locator('input[type=file]').setInputFiles(png('a.png'));
  const tile = explorer.getByRole('option', { name: 'a.png' });
  await tile.focus();

  await page.keyboard.press('F2');
  await explorer.locator('dialog').getByRole('textbox').fill('b');
  await page.keyboard.press('Enter');
  await expect(explorer.getByRole('option', { name: 'b.png' })).toBeVisible();

  await explorer.getByRole('option', { name: 'b.png' }).focus();
  await page.keyboard.press('Delete');
  await page.keyboard.press('Enter');
  await expect(explorer.getByText('This folder is empty.')).toBeVisible();
});

test('read-only owners can look but not change anything', async ({ page }) => {
  await page.goto('/?owner=readonly');
  const explorer = page.locator('#explorer');
  await expect(explorer.getByText('This folder is empty.')).toBeVisible();

  await expect(explorer.getByRole('button', { name: 'Upload' })).toHaveCount(0);
  await expect(explorer.getByRole('button', { name: 'New folder' })).toHaveCount(0);
});

test('owners do not see each other’s files', async ({ page }) => {
  await page.goto('/');
  await page.locator('#explorer input[type=file]').setInputFiles(png('mine.png'));
  await expect(page.locator('#explorer').getByRole('option', { name: 'mine.png' })).toBeVisible();

  await page.goto('/?owner=other');
  await expect(page.locator('#explorer').getByText('This folder is empty.')).toBeVisible();
});

test('speaks Japanese', async ({ page }) => {
  await page.goto('/?lang=ja');
  const explorer = page.locator('#explorer');

  await expect(explorer.getByRole('button', { name: 'アップロード' })).toBeVisible();
  await expect(explorer.getByText('このフォルダは空です。')).toBeVisible();
});
