import { expect, test } from '@playwright/test';

test.beforeEach(async ({ request }) => {
  await request.post('/__reset');
});

test('lists pages, edits, saves and previews with the host-provided preview', async ({ page }) => {
  await page.goto('/');
  const docs = page.locator('#documents');
  await expect(docs.getByRole('button', { name: 'Home' })).toBeVisible();
  await expect(docs.getByRole('button', { name: 'About' })).toBeVisible();
  // The optional subtitle (here the file name) shows under the name
  await expect(docs.locator('.doc .badge').first()).toHaveText('home.html');

  // A page with no file yet starts empty
  await docs.getByRole('button', { name: 'Home' }).click();
  const editor = docs.locator('textarea.editor');
  await expect(editor).toHaveValue('');

  await editor.fill('<h1>Hello page</h1>');
  await docs.getByRole('button', { name: 'Save' }).click();
  await expect(docs.getByText('Saved')).toBeVisible();

  // The injected preview renders the edited HTML in an iframe
  await docs.getByRole('button', { name: 'Preview' }).click();
  await expect(docs.locator('.preview-mount iframe').contentFrame().getByRole('heading', { name: 'Hello page' })).toBeVisible();

  // It was saved on the server: reload, reopen, the content is there
  await page.reload();
  await page.locator('#documents').getByRole('button', { name: 'Home' }).click();
  await expect(page.locator('#documents textarea.editor')).toHaveValue('<h1>Hello page</h1>');
});

test('loads a local file into the editor and offers a download', async ({ page }) => {
  await page.goto('/');
  const docs = page.locator('#documents');
  await docs.getByRole('button', { name: 'Home' }).click();
  await docs.locator('textarea.editor').waitFor();

  // Upload: a local file's text is read into the editor
  await docs.locator('input[type=file]').setInputFiles({ name: 'home.html', mimeType: 'text/html', buffer: Buffer.from('<h2>From file</h2>') });
  await expect(docs.locator('textarea.editor')).toHaveValue('<h2>From file</h2>');

  // Download: the button is present (the actual file save is left to the browser)
  await expect(docs.getByRole('button', { name: 'Download' })).toBeVisible();
});

test('speaks the configured language', async ({ page }) => {
  await page.goto('/?lang=ja');
  const docs = page.locator('#documents');
  await docs.getByRole('button', { name: 'Home' }).click();
  await expect(docs.getByRole('button', { name: '保存' })).toBeVisible();
  await expect(docs.getByRole('button', { name: 'プレビュー' })).toBeVisible();
});
