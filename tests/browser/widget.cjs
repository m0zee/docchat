/**
 * Browser check of the website widget (resources/widget/widget.js) in Chromium.
 * The DocChat API is faked with Playwright routes, so no server or keys are needed;
 * the server side of streaming is covered by the Pest tests.
 *
 *   mkdir -p /tmp/pw && cd /tmp/pw && npm init -y && npm install playwright@1.63.0 && npx playwright install chromium
 *   NODE_PATH=/tmp/pw/node_modules node tests/browser/widget.cjs
 *
 * Playwright is not a project dependency; CI installs it on the side the same way
 * (the widget-browser job).
 */
const path = require('path');
const fs = require('fs');
const assert = require('assert');
const { chromium } = require('playwright');

const SCRIPT = fs.readFileSync(path.join(__dirname, '../../resources/widget/widget.js'), 'utf8');
const SITE = 'https://shop.example.com/';
const API = 'https://docchat.example.com';
const KEY = 'dc_pub_' + 'x'.repeat(40);

function sse(events) {
  return events.map(([event, data]) => `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`).join('');
}

async function setup(context, { refuse = false, status = 200 } = {}) {
  const asked = [];
  await context.route(SITE, (route) => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html><head><meta name="viewport" content="width=device-width"></head><body><h1>Shop</h1><script src="${API}/widget.js" data-key="${KEY}" async></script></body></html>`,
  }));
  await context.route(`${API}/widget.js`, (route) => route.fulfill({ contentType: 'application/javascript', body: SCRIPT }));
  await context.route(`${API}/api/v1/widget`, (route) => route.fulfill({
    contentType: 'application/json',
    headers: { 'Access-Control-Allow-Origin': '*' },
    body: JSON.stringify({ data: { title: 'Ask Harbor', welcome: 'Hello from Harbor!', color: '#ff0000', position: 'right', handoff: { email: 'help@shop.example.com', text: 'Talk to a person:' } } }),
  }));
  await context.route(`${API}/api/v1/ask/stream`, async (route) => {
    const request = route.request();
    if (request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'POST' } });
    }
    asked.push({ body: JSON.parse(request.postData()), auth: request.headers().authorization });
    if (status !== 200) {
      return route.fulfill({ status, contentType: 'application/json', headers: { 'Access-Control-Allow-Origin': '*' }, body: JSON.stringify({ error: { code: 'rate_limited', message: 'slow down' } }) });
    }
    const answer = refuse ? "I couldn't find that in your documents." : 'We open at **9** <img src=x onerror="window.__xss=1"> [1].';
    const pieces = answer.match(/.{1,6}/g);
    return route.fulfill({
      status: 200,
      contentType: 'text/event-stream',
      headers: { 'Access-Control-Allow-Origin': '*' },
      body: sse([
        ...pieces.map((text) => ['token', { text }]),
        ['done', { answer, refused: refuse, citations: refuse ? [] : [{ n: 1, document_id: 1, title: 'Opening hours', page: 2, snippet: 'Open 9 to 5' }] }],
      ]),
    });
  });
  return asked;
}

const shadow = (page, selector) => page.locator('#docchat-widget').locator(selector);

async function run() {
  const browser = await chromium.launch();
  const results = [];
  const check = async (name, fn) => {
    try { await fn(); results.push(['ok', name]); } catch (error) { results.push(['FAIL', name, error.message]); }
  };

  await check('opens, streams an answer with sources, and never renders answer HTML', async () => {
    const context = await browser.newContext();
    const asked = await setup(context);
    const page = await context.newPage();
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'text=Hello from Harbor!').waitFor();
    assert.strictEqual(await shadow(page, 'h2').textContent(), 'Ask Harbor');
    await shadow(page, 'textarea').fill('When do you open?');
    await shadow(page, 'textarea').press('Enter');
    await shadow(page, '.chip').waitFor();
    assert.match(await shadow(page, '.msg.bot >> nth=1').textContent(), /We open at 9 <img/);
    assert.strictEqual(await shadow(page, '.msg.bot b').first().textContent(), '9');
    assert.strictEqual(await shadow(page, '.chip').textContent(), '[1] Opening hours, p. 2');
    assert.strictEqual(await page.evaluate(() => window.__xss), undefined);
    assert.strictEqual(asked[0].auth, 'Bearer ' + KEY);
    assert.match(asked[0].body.external_conversation_id, /^[A-Za-z0-9_-]{16,64}$/);
    const color = await shadow(page, '.bubble').evaluate((n) => getComputedStyle(n).backgroundColor);
    assert.strictEqual(color, 'rgb(255, 0, 0)');

    // History survives a reload, and the same conversation continues.
    await page.reload();
    await shadow(page, '.bubble').click();
    await shadow(page, '.chip').waitFor();
    await shadow(page, 'textarea').fill('And Sunday?');
    await shadow(page, '.send').click();
    await shadow(page, '.chip >> nth=1').waitFor();
    assert.strictEqual(asked[1].body.external_conversation_id, asked[0].body.external_conversation_id);

    // New chat clears the log and starts a new conversation id.
    await shadow(page, 'text=New chat').click();
    assert.strictEqual(await shadow(page, '.msg').count(), 1);
    await shadow(page, 'textarea').fill('Hi');
    await shadow(page, 'textarea').press('Enter');
    await shadow(page, '.chip').waitFor();
    assert.notStrictEqual(asked[2].body.external_conversation_id, asked[0].body.external_conversation_id);
    await context.close();
  });

  await check('a refusal shows the handoff line', async () => {
    const context = await browser.newContext();
    await setup(context, { refuse: true });
    const page = await context.newPage();
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'textarea').fill('Do you sell boats?');
    await shadow(page, 'textarea').press('Enter');
    await shadow(page, '.handoff a').waitFor();
    assert.strictEqual(await shadow(page, '.handoff a').getAttribute('href'), 'mailto:help@shop.example.com');
    assert.match(await shadow(page, '.handoff').textContent(), /Talk to a person:/);
    await context.close();
  });

  await check('a rate limit shows a friendly error', async () => {
    const context = await browser.newContext();
    await setup(context, { status: 429 });
    const page = await context.newPage();
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'textarea').fill('Hi');
    await shadow(page, 'textarea').press('Enter');
    await shadow(page, '.err').waitFor();
    assert.match(await shadow(page, '.err').textContent(), /wait a minute/);
    await context.close();
  });

  await check('works when localStorage throws', async () => {
    const context = await browser.newContext();
    await setup(context);
    await context.addInitScript(() => {
      Object.defineProperty(window, 'localStorage', { get() { throw new Error('blocked'); } });
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'textarea').fill('Hi');
    await shadow(page, 'textarea').press('Enter');
    await shadow(page, '.chip').waitFor();
    assert.deepStrictEqual(errors, []);
    await context.close();
  });

  await check('fills the screen on a phone and keeps page styles out', async () => {
    const context = await browser.newContext({ viewport: { width: 375, height: 700 }, isMobile: true, hasTouch: true });
    await setup(context);
    const page = await context.newPage();
    await page.goto(SITE);
    await page.addStyleTag({ content: 'button, div { background: lime !important; }' });
    await shadow(page, '.bubble').click();
    const box = await shadow(page, '.panel').boundingBox();
    assert.ok(box.width >= 370 && box.height >= 690, `panel is ${box.width}x${box.height}`);
    const bg = await shadow(page, '.send').evaluate((n) => getComputedStyle(n).backgroundColor);
    assert.strictEqual(bg, 'rgb(255, 0, 0)');
    await page.screenshot({ path: path.join(process.env.WIDGET_SCREENSHOTS || require('os').tmpdir(), 'widget-mobile.png') });
    await context.close();
  });

  await check('follows dark mode', async () => {
    const context = await browser.newContext({ colorScheme: 'dark' });
    await setup(context);
    const page = await context.newPage();
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    const bg = await shadow(page, '.panel').evaluate((n) => getComputedStyle(n).backgroundColor);
    assert.strictEqual(bg, 'rgb(24, 24, 27)');
    await page.screenshot({ path: path.join(process.env.WIDGET_SCREENSHOTS || require('os').tmpdir(), 'widget-dark.png') });
    await context.close();
  });

  await browser.close();
  for (const [status, name, message] of results) console.log(`${status === 'ok' ? '✓' : '✗'} ${name}${message ? '\n    ' + message : ''}`);
  const failed = results.filter(([s]) => s !== 'ok').length;
  console.log(`\n${results.length - failed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
}

run();
