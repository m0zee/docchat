/**
 * End-to-end check of the Documents page against a running DocChat: log in,
 * pick a file, press Upload, and wait for the document to appear in the list.
 * The Pest tests call the Livewire action directly, so they can't see a break
 * in the browser half (for example a form action that clashes with one of
 * Livewire's own $wire methods, which is what happened with "upload").
 *
 *   BASE_URL=http://localhost:8000 NODE_PATH=/tmp/pw/node_modules node tests/browser/document-upload.cjs
 *
 * Logs in as the SampleDocumentSeeder user unless DOCCHAT_EMAIL / DOCCHAT_PASSWORD are set.
 */
const path = require('path');
const fs = require('fs');
const os = require('os');
const assert = require('assert');
const { chromium } = require('playwright');

const BASE = (process.env.BASE_URL || 'http://localhost:8000').replace(/\/$/, '');
const EMAIL = process.env.DOCCHAT_EMAIL || 'demo@docchat.test';
const PASSWORD = process.env.DOCCHAT_PASSWORD || 'password';

(async () => {
  const name = 'browser-upload-' + Date.now() + '.txt';
  const file = path.join(os.tmpdir(), name);
  fs.writeFileSync(file, 'DocChat browser upload check.\n');

  const browser = await chromium.launch();
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error' || /Livewire Expression Error/.test(message.text())) errors.push(message.text());
  });

  try {
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login')), page.click('[data-test="login-button"]')]);

    await page.goto(BASE + '/documents');
    const form = page.locator('form:has(input[type="file"])');
    await form.locator('input[type="file"]').setInputFiles(file);
    await page.getByText(name).first().waitFor({ timeout: 15000 }); // the chosen file is shown once the temporary upload finishes

    await form.locator('button[type="submit"]').click();
    await page.locator('table').getByText(name).waitFor({ timeout: 15000 });
    assert.strictEqual(await form.locator('button[type="submit"]').isDisabled(), false, 'the Upload button is still busy');
    assert.deepStrictEqual(errors, [], 'the page logged errors');
    console.log('ok - a picked file is uploaded and listed');
  } catch (error) {
    console.error('page errors:', errors);
    throw error;
  } finally {
    await browser.close();
    fs.rmSync(file, { force: true });
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
