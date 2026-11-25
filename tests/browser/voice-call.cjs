/**
 * Browser check of voice calls (resources/widget/voice-call.js) through the
 * website widget, in Chromium with a fake microphone. The DocChat API is faked
 * with Playwright routes; the server side is covered by the Pest tests.
 *
 *   NODE_PATH=/tmp/pw/node_modules node tests/browser/voice-call.cjs
 *
 * The fake microphone plays a WAV made here: a short silence, a tone that the
 * silence detector takes as speech, then silence. speechSynthesis is replaced
 * with a stub that records what would be said, so no audio device is needed.
 */
const path = require('path');
const fs = require('fs');
const os = require('os');
const assert = require('assert');
const { chromium } = require('playwright');

const WIDGET = fs.readFileSync(path.join(__dirname, '../../resources/widget/widget.js'), 'utf8');
const VOICE_JS = fs.readFileSync(path.join(__dirname, '../../resources/widget/voice-call.js'), 'utf8');
const SITE = 'https://shop.example.com/';
const API = 'https://docchat.example.com';
const KEY = 'dc_pub_' + 'x'.repeat(40);
const TOKEN = 'dc_call_' + 'y'.repeat(40);
const SHOTS = process.env.WIDGET_SCREENSHOTS || os.tmpdir();

function speechWav(file) {
  const rate = 16000;
  const parts = [[0.6, 0], [1.4, 0.35], [6, 0]];
  const samples = [];
  for (const [seconds, amp] of parts) {
    for (let i = 0; i < seconds * rate; i++) samples.push(Math.round(amp * 32767 * Math.sin((2 * Math.PI * 220 * i) / rate) * (0.6 + 0.4 * Math.sin(i / 300))));
  }
  const data = Buffer.alloc(samples.length * 2);
  samples.forEach((s, i) => data.writeInt16LE(s, i * 2));
  const header = Buffer.alloc(44);
  header.write('RIFF', 0); header.writeUInt32LE(36 + data.length, 4); header.write('WAVE', 8);
  header.write('fmt ', 12); header.writeUInt32LE(16, 16); header.writeUInt16LE(1, 20); header.writeUInt16LE(1, 22);
  header.writeUInt32LE(rate, 24); header.writeUInt32LE(rate * 2, 28); header.writeUInt16LE(2, 32); header.writeUInt16LE(16, 34);
  header.write('data', 36); header.writeUInt32LE(data.length, 40);
  fs.writeFileSync(file, Buffer.concat([header, data]));
}

const cors = { 'Access-Control-Allow-Origin': '*' };

function toneWav(seconds = 0.3) {
  const rate = 8000;
  const n = Math.round(seconds * rate);
  const data = Buffer.alloc(n * 2);
  for (let i = 0; i < n; i++) data.writeInt16LE(Math.round(8000 * Math.sin((2 * Math.PI * 440 * i) / rate)), i * 2);
  const header = Buffer.alloc(44);
  header.write('RIFF', 0); header.writeUInt32LE(36 + data.length, 4); header.write('WAVE', 8);
  header.write('fmt ', 12); header.writeUInt32LE(16, 16); header.writeUInt16LE(1, 20); header.writeUInt16LE(1, 22);
  header.writeUInt32LE(rate, 24); header.writeUInt32LE(rate * 2, 28); header.writeUInt16LE(2, 32); header.writeUInt16LE(16, 34);
  header.write('data', 36); header.writeUInt32LE(data.length, 40);
  return Buffer.concat([header, data]);
}

// Fake device voices, and an utterance class that records which voice,
// language, rate and pitch were used. Replies containing "LONG" never end on
// their own, so only an interruption stops them.
async function fakeVoices(context) {
  await context.addInitScript(() => {
    const voices = [
      { name: 'Microsoft David - English (United States)', lang: 'en-US', localService: true },
      { name: 'Microsoft Aria Online (Natural) - English (United States)', lang: 'en-US', localService: false },
      { name: 'Microsoft Guy Online (Natural) - English (United States)', lang: 'en-US', localService: false },
      { name: 'Google UK English Female', lang: 'en-GB', localService: false },
      { name: 'Microsoft Uzma Online (Natural) - Urdu (Pakistan)', lang: 'ur-PK', localService: false },
    ];
    window.__utterances = [];
    window.__cancels = 0;
    window.SpeechSynthesisUtterance = function (text) { this.text = text; };
    window.speechSynthesis.getVoices = () => voices;
    window.speechSynthesis.speak = function (u) {
      window.__spoken.push(u.text);
      window.__utterances.push({ text: u.text, voice: u.voice ? u.voice.name : null, lang: u.lang, rate: u.rate, pitch: u.pitch });
      if (u.text.indexOf('LONG') === -1) setTimeout(() => u.onend && u.onend(), 50);
    };
    window.speechSynthesis.cancel = function () { window.__cancels++; };
    window.__oscillators = 0;
    const create = AudioContext.prototype.createOscillator;
    AudioContext.prototype.createOscillator = function () { window.__oscillators++; return create.call(this); };
  });
}

async function setup(context, { calls = true, endAfter = 99, voice = undefined, turnDelay = 0, reply = null, turnExtra = null } = {}) {
  const seen = { started: [], turns: [], ended: 0, speech: [] };
  await context.addInitScript(() => {
    window.__spoken = [];
    window.speechSynthesis.speak = function (u) { window.__spoken.push(u.text); setTimeout(() => u.onend && u.onend(), 50); };
    window.speechSynthesis.cancel = function () {};
  });
  await context.route(SITE, (route) => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width"></head><body><h1>Shop</h1><script src="${API}/widget.js" data-key="${KEY}" async></script></body></html>`,
  }));
  await context.route(`${API}/widget.js`, (route) => route.fulfill({ contentType: 'application/javascript', body: WIDGET }));
  await context.route(`${API}/voice-call.js`, (route) => route.fulfill({ contentType: 'application/javascript', body: VOICE_JS }));
  await context.route(`${API}/api/v1/**`, async (route) => {
    const request = route.request();
    if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { ...cors, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'POST' } });
    const url = request.url();
    if (url.endsWith('/api/v1/calls')) {
      seen.started.push({ auth: request.headers().authorization, body: JSON.parse(request.postData()) });
      return route.fulfill({ status: 201, contentType: 'application/json', headers: cors, body: JSON.stringify({ data: { call_id: 1, token: TOKEN, greeting: 'Hi, you have reached Harbor. I am an automated assistant.', max_seconds: 600, voice } }) });
    }
    if (url.endsWith('/api/v1/call/speech')) {
      seen.speech.push({ auth: request.headers().authorization, text: JSON.parse(request.postData()).text });
      return route.fulfill({ contentType: 'audio/wav', headers: cors, body: toneWav() });
    }
    if (url.endsWith('/api/v1/call/turns')) {
      const body = request.postDataBuffer() || Buffer.alloc(0);
      const typed = /name="text"\r\n\r\n([^\r]*)/.exec(body.toString('latin1'));
      const audio = /name="audio"; filename="([^"]+)"\r\nContent-Type: ([^\r]+)/.exec(body.toString('latin1'));
      seen.turns.push({ auth: request.headers().authorization, text: typed ? typed[1] : null, audio: audio ? { name: audio[1], type: audio[2], bytes: body.length } : null });
      const n = seen.turns.length;
      if (turnDelay) await new Promise((r) => setTimeout(r, turnDelay));
      return route.fulfill({ contentType: 'application/json', headers: cors, body: JSON.stringify({ data: {
        heard: typed ? typed[1] : 'When do you open?',
        reply: reply ? reply(n) : (n >= endAfter ? 'Thank you for calling. Goodbye!' : 'We open at 9.'),
        citations: n >= endAfter ? [] : [{ n: 1, title: 'Opening hours', page: 2 }],
        refused: false, ended: n >= endAfter, error: null,
        ...(turnExtra ? turnExtra(n) : {}),
      } }) });
    }
    if (url.endsWith('/api/v1/call/end')) { seen.ended++; return route.fulfill({ contentType: 'application/json', headers: cors, body: '{"data":{"ended":true}}' }); }
    return route.fulfill({ status: 404, headers: cors, body: '{}' });
  });
  // Registered last, so it wins over the catch-all above.
  await context.route(`${API}/api/v1/widget`, (route) => route.fulfill({
    contentType: 'application/json', headers: cors,
    body: JSON.stringify({ data: { title: 'Ask Harbor', welcome: 'Hello!', color: '#0f766e', position: 'right', handoff: { email: null, text: '' }, calls } }),
  }));
  return seen;
}

const shadow = (page, selector) => page.locator('#docchat-widget').locator(selector);

async function run() {
  const wav = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'dcv-')), 'speech.wav');
  speechWav(wav);
  const browser = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', `--use-file-for-fake-audio-capture=${wav}`, '--autoplay-policy=no-user-gesture-required'] });
  const results = [];
  const check = async (name, fn) => {
    try { await fn(); results.push(['ok', name]); } catch (error) { results.push(['FAIL', name, error.message]); }
  };

  await check('a visitor calls from the widget, speaks, and hears the answer', async () => {
    const context = await browser.newContext({ permissions: ['microphone'] });
    const seen = await setup(context);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'button:has-text("Call")').click();
    await shadow(page, '.dcv-agent').first().waitFor();
    assert.strictEqual(seen.started[0].auth, 'Bearer ' + KEY);
    assert.match(seen.started[0].body.external_conversation_id, /^[A-Za-z0-9_-]{16,64}$/);

    // The fake mic "speaks"; after the pause the recording is sent.
    await shadow(page, '.dcv-you').first().waitFor({ timeout: 20000 });
    await shadow(page, '.dcv-src').first().waitFor();
    assert.strictEqual(seen.turns[0].auth, 'Bearer ' + TOKEN);
    assert.ok(seen.turns[0].audio, 'audio was uploaded');
    assert.match(seen.turns[0].audio.name, /^speech\.(webm|ogg|m4a)$/);
    assert.ok(seen.turns[0].audio.bytes > 1000, 'the recording has sound');
    assert.strictEqual(await shadow(page, '.dcv-you').first().textContent(), 'When do you open?');
    assert.match(await shadow(page, '.dcv-agent >> nth=1').textContent(), /We open at 9\.Sources: Opening hours p\. 2/);
    const spoken = await page.evaluate(() => window.__spoken);
    assert.ok(spoken.some((t) => t.indexOf('automated assistant') !== -1), 'greeting was spoken');
    await page.waitForFunction(() => window.__spoken.some((t) => t === 'We open at 9.'));
    await page.screenshot({ path: path.join(SHOTS, 'widget-call.png') });

    // Hanging up tells the server and stops the mic.
    await shadow(page, '.dcv-end').click();
    await shadow(page, 'text=You ended the call.').waitFor();
    await page.waitForTimeout(200);
    assert.strictEqual(seen.ended, 1);
    assert.ok(await shadow(page, '.dcv-mute').isDisabled());
    await shadow(page, '.back').click();
    await shadow(page, 'textarea').waitFor();
    assert.deepStrictEqual(errors, []);
    await context.close();
  });

  await check('typing works too, and the agent can end the call', async () => {
    const context = await browser.newContext({ permissions: ['microphone'] });
    const seen = await setup(context, { endAfter: 1 });
    const page = await context.newPage();
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'button:has-text("Call")').click();
    await shadow(page, '.dcv-agent').first().waitFor();
    await shadow(page, '.dcv-mute').click();
    await shadow(page, 'text=Muted. Unmute or type.').waitFor();
    await shadow(page, '.dcv-type input').fill('Thanks, bye');
    await shadow(page, '.dcv-send').click();
    await shadow(page, 'text=The assistant ended the call.').waitFor({ timeout: 10000 });
    assert.strictEqual(seen.turns[0].text, 'Thanks, bye');
    assert.strictEqual(seen.turns[0].audio, null);
    assert.strictEqual(seen.ended, 0, 'the server already ended it');
    await context.close();
  });

  await check('no Call button when calls are off', async () => {
    const context = await browser.newContext();
    await setup(context, { calls: false });
    const page = await context.newPage();
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'text=Hello!').waitFor();
    assert.strictEqual(await shadow(page, 'button:has-text("Call")').isVisible(), false);
    await context.close();
  });

  await check('without a microphone the call still works by typing', async () => {
    const context = await browser.newContext();
    const seen = await setup(context);
    await context.addInitScript(() => {
      navigator.mediaDevices.getUserMedia = () => Promise.reject(new Error('denied'));
    });
    const page = await context.newPage();
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'button:has-text("Call")').click();
    await shadow(page, 'text=No microphone access. You can type instead.').waitFor();
    await shadow(page, 'text=Type your question below.').waitFor();
    await shadow(page, '.dcv-type input').fill('When do you open?');
    await shadow(page, '.dcv-type input').press('Enter');
    await shadow(page, '.dcv-src').waitFor();
    assert.strictEqual(seen.turns[0].text, 'When do you open?');
    await context.close();
  });


  const VOICE = { engine: 'browser', lang: 'en-US', gender: 'female', age: 'adult', browser_voice: null, rate: 1.1, pitch: 0.9, interruptions: true, end_of_turn_ms: 900, reply_gap_ms: 0, fillers: [], hold_music: false, background: 'none', name: 'Ava' };

  await check('the call uses the best natural voice for the language and gender, and follows the caller\'s language', async () => {
    const context = await browser.newContext({ permissions: ['microphone'] });
    const seen = await setup(context, { voice: VOICE, turnExtra: (n) => (n === 1 ? { lang: 'ur-PK' } : {}), reply: (n) => (n === 1 ? 'Hum 9 baje khulte hain.' : 'We open at 9.') });
    await fakeVoices(context);
    const page = await context.newPage();
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'button:has-text("Call")').click();
    await shadow(page, '.dcv-mute').click();
    await page.waitForFunction(() => window.__utterances.length > 0);
    const greeting = await page.evaluate(() => window.__utterances[0]);
    assert.strictEqual(greeting.voice, 'Microsoft Aria Online (Natural) - English (United States)');
    assert.strictEqual(greeting.rate, 1.1);
    assert.strictEqual(greeting.pitch, 0.9);
    await shadow(page, '.dcv-type input').fill('Kab khulte hain?');
    await shadow(page, '.dcv-send').click();
    await page.waitForFunction(() => window.__utterances.some((u) => u.text.indexOf('Hum 9') === 0));
    const reply = await page.evaluate(() => window.__utterances.find((u) => u.text.indexOf('Hum 9') === 0));
    assert.strictEqual(reply.voice, 'Microsoft Uzma Online (Natural) - Urdu (Pakistan)');
    assert.strictEqual(seen.turns.length, 1);
    await context.close();
  });

  await check('a male voice is picked when the profile asks for one, and the owner\'s pick wins', async () => {
    const picked = await (async () => {
      const page = await browser.newPage();
      await page.addScriptTag({ content: VOICE_JS });
      const result = await page.evaluate(() => {
        const list = [
          { name: 'Microsoft David - English (United States)', lang: 'en-US' },
          { name: 'Microsoft Aria Online (Natural) - English (United States)', lang: 'en-US' },
          { name: 'Microsoft Guy Online (Natural) - English (United States)', lang: 'en-US' },
          { name: 'Google UK English Female', lang: 'en-GB' },
        ];
        const pick = window.DocChatVoiceCall.pickVoice;
        return [
          pick(list, { lang: 'en-US', gender: 'male' }).name,
          pick(list, { lang: 'en-GB', gender: 'male' }).name,
          pick(list, { lang: 'en-US', gender: 'female', name: 'Microsoft David - English (United States)' }).name,
          pick(list, { lang: 'de-DE', gender: 'female' }),
        ];
      });
      await page.close();
      return result;
    })();
    assert.deepStrictEqual(picked, [
      'Microsoft Guy Online (Natural) - English (United States)',
      'Google UK English Female',
      'Microsoft David - English (United States)',
      null,
    ]);
  });

  await check('a slow answer gets a filler and soft hold music', async () => {
    const context = await browser.newContext({ permissions: ['microphone'] });
    const seen = await setup(context, { voice: { ...VOICE, fillers: ['One moment.'], hold_music: true, background: 'office' }, turnDelay: 3500 });
    await fakeVoices(context);
    const page = await context.newPage();
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'button:has-text("Call")').click();
    await shadow(page, '.dcv-mute').click();
    await page.waitForFunction(() => window.__utterances.length > 0);
    await shadow(page, '.dcv-type input').fill('When do you open?');
    await shadow(page, '.dcv-send').click();
    await page.waitForFunction(() => window.__spoken.indexOf('One moment.') !== -1, null, { timeout: 5000 });
    await page.waitForFunction(() => window.__oscillators >= 3, null, { timeout: 5000 });
    await page.waitForFunction(() => window.__spoken.indexOf('We open at 9.') !== -1, null, { timeout: 8000 });
    const order = await page.evaluate(() => window.__spoken);
    assert.ok(order.indexOf('One moment.') < order.indexOf('We open at 9.'), 'the filler comes before the answer');
    assert.strictEqual(seen.turns.length, 1);
    await context.close();
  });

  await check('the caller can talk over the assistant', async () => {
    const context = await browser.newContext({ permissions: ['microphone'] });
    const seen = await setup(context, { voice: VOICE, reply: (n) => (n === 1 ? 'LONG answer about every opening hour we have, which goes on and on.' : 'Sure.') });
    await fakeVoices(context);
    const page = await context.newPage();
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'button:has-text("Call")').click();
    // Turn 1 from the fake mic; its reply would never end, until the next
    // stretch of "speech" from the mic interrupts it and becomes turn 2.
    await page.waitForFunction(() => window.__spoken.some((t) => t.indexOf('LONG') === 0), null, { timeout: 20000 });
    await page.waitForFunction(() => window.__cancels > 1, null, { timeout: 20000 });
    await shadow(page, 'text=Sure.').waitFor({ timeout: 20000 });
    assert.strictEqual(seen.turns.length, 2);
    assert.ok(seen.turns[1].audio, 'the interruption was recorded and sent');
    await context.close();
  });

  await check('the self-hosted voice plays the call lines as audio, sentence by sentence', async () => {
    const context = await browser.newContext({ permissions: ['microphone'] });
    const seen = await setup(context, { voice: { ...VOICE, engine: 'kokoro' }, reply: () => 'We open at 9. We close at 5.' });
    await fakeVoices(context);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(SITE);
    await shadow(page, '.bubble').click();
    await shadow(page, 'button:has-text("Call")').click();
    await shadow(page, '.dcv-mute').click();
    await page.waitForFunction(() => document.querySelector('#docchat-widget') !== null);
    await new Promise((r) => setTimeout(r, 1500));
    await shadow(page, '.dcv-type input').fill('Hours?');
    await shadow(page, '.dcv-send').click();
    const until = Date.now() + 10000;
    while (seen.speech.length < 4 && Date.now() < until) await new Promise((r) => setTimeout(r, 100));
    assert.deepStrictEqual(seen.speech.map((s) => s.text), ['Hi, you have reached Harbor.', 'I am an automated assistant.', 'We open at 9.', 'We close at 5.']);
    assert.strictEqual(seen.speech[0].auth, 'Bearer ' + TOKEN);
    const browserSaid = await page.evaluate(() => window.__spoken);
    assert.deepStrictEqual(browserSaid, [], 'nothing fell back to the browser voice');
    assert.deepStrictEqual(errors, []);
    await context.close();
  });

  await browser.close();
  for (const [status, name, message] of results) console.log(`${status === 'ok' ? '✓' : '✗'} ${name}${message ? '\n    ' + message : ''}`);
  const failed = results.filter(([s]) => s !== 'ok').length;
  console.log(`\n${results.length - failed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
}

run();
