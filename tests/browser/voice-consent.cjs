/**
 * Browser check of the voice consent recorder (resources/widget/voice-recorder.js)
 * in Chromium with a fake microphone. The upload is caught with a Playwright
 * route; the server side (transcript check, storage) is covered by Pest.
 *
 *   NODE_PATH=/tmp/pw/node_modules node tests/browser/voice-consent.cjs
 */
const path = require('path');
const fs = require('fs');
const os = require('os');
const assert = require('assert');
const { chromium } = require('playwright');

const RECORDER = fs.readFileSync(path.join(__dirname, '../../resources/widget/voice-recorder.js'), 'utf8');
const SITE = 'https://docchat.example.com/voice-consent/dc_vc_test';
const UPLOAD = 'https://docchat.example.com/voice-consent/dc_vc_test/recording';

function toneWav(file, seconds) {
  const rate = 16000;
  const samples = seconds * rate;
  const data = Buffer.alloc(samples * 2);
  for (let i = 0; i < samples; i++) data.writeInt16LE(Math.round(0.3 * 32767 * Math.sin((2 * Math.PI * 220 * i) / rate)), i * 2);
  const header = Buffer.alloc(44);
  header.write('RIFF', 0); header.writeUInt32LE(36 + data.length, 4); header.write('WAVE', 8);
  header.write('fmt ', 12); header.writeUInt32LE(16, 16); header.writeUInt16LE(1, 20); header.writeUInt16LE(1, 22);
  header.writeUInt32LE(rate, 24); header.writeUInt32LE(rate * 2, 28); header.writeUInt16LE(2, 32); header.writeUInt16LE(16, 34);
  header.write('data', 36); header.writeUInt32LE(data.length, 40);
  fs.writeFileSync(file, Buffer.concat([header, data]));
}

function page(opts) {
  return '<!doctype html><html><body>'
    + '<input type="checkbox" id="consent-confirm">'
    + '<button id="rec-start">Start</button><button id="rec-stop" class="hidden">Stop</button>'
    + '<span id="rec-time">0:00</span><audio id="rec-preview" class="hidden" controls></audio>'
    + '<button id="rec-send" class="hidden">Send</button><p id="rec-status"></p>'
    + '<style>.hidden{display:none}</style>'
    + '<script>' + RECORDER + '</script>'
    + '<script>window.DocChatVoiceRecorder.mount(' + JSON.stringify(Object.assign({ url: UPLOAD, csrf: 'csrf-1', minSeconds: 2, maxSeconds: 45, reload: false, text: { tick: 'TICK', short: 'SHORT' } }, opts)) + ');</script>'
    + '</body></html>';
}

let passed = 0;
async function check(name, fn) {
  await fn();
  passed++;
  console.log('ok - ' + name);
}

(async () => {
  const wavFile = path.join(os.tmpdir(), 'docchat-consent-mic.wav');
  toneWav(wavFile, 30);
  const browser = await chromium.launch({
    args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-audio-capture=' + wavFile, '--autoplay-policy=no-user-gesture-required'],
  });
  const ctx = await browser.newContext({ permissions: ['microphone'] });
  const errors = [];
  const uploads = [];

  async function open(opts) {
    const p = await ctx.newPage();
    p.on('pageerror', (e) => errors.push(e.message));
    await p.route(SITE, (route) => route.fulfill({ contentType: 'text/html', body: page(opts) }));
    await p.route(UPLOAD, async (route) => {
      const req = route.request();
      uploads.push({ body: req.postDataBuffer(), type: req.headers()['content-type'] || '' });
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { status: 'ready', message: 'READY' } }) });
    });
    await p.goto(SITE);
    return p;
  }

  await check('recording needs the consent box ticked', async () => {
    const p = await open({});
    await p.click('#rec-start');
    assert.strictEqual(await p.textContent('#rec-status'), 'TICK');
    assert.ok(await p.isVisible('#rec-start'));
    await p.close();
  });

  await check('a recording that is too short is not offered for sending', async () => {
    const p = await open({ minSeconds: 30 });
    await p.check('#consent-confirm');
    await p.click('#rec-start');
    await p.waitForSelector('#rec-stop:not(.hidden)');
    await p.waitForTimeout(800);
    await p.click('#rec-stop');
    assert.strictEqual(await p.textContent('#rec-status'), 'SHORT');
    assert.ok(await p.isHidden('#rec-send'));
    await p.close();
  });

  await check('a recording is sent as a 24 kHz mono 16-bit WAV with the consent and CSRF fields', async () => {
    const p = await open({});
    await p.check('#consent-confirm');
    await p.click('#rec-start');
    await p.waitForSelector('#rec-stop:not(.hidden)');
    await p.waitForFunction(() => document.getElementById('rec-time').textContent === '0:03', null, { timeout: 15000 });
    await p.click('#rec-stop');
    await p.waitForSelector('#rec-send:not(.hidden)');
    assert.ok(await p.isVisible('#rec-preview'));
    await p.click('#rec-send');
    await p.waitForFunction(() => document.getElementById('rec-status').textContent === 'READY');
    assert.strictEqual(uploads.length, 1);
    const { body, type } = uploads[0];
    assert.ok(type.startsWith('multipart/form-data'), type);
    const text = body.toString('latin1');
    assert.ok(text.includes('name="confirm"\r\n\r\n1'), 'confirm field');
    assert.ok(text.includes('name="_token"\r\n\r\ncsrf-1'), 'csrf field');
    const at = body.indexOf('RIFF');
    assert.ok(at > 0, 'a WAV file');
    const wav = body.subarray(at);
    assert.strictEqual(wav.toString('latin1', 8, 12), 'WAVE');
    assert.strictEqual(wav.readUInt16LE(20), 1, 'PCM');
    assert.strictEqual(wav.readUInt16LE(22), 1, 'mono');
    assert.strictEqual(wav.readUInt32LE(24), 24000, '24 kHz');
    assert.strictEqual(wav.readUInt16LE(34), 16, '16-bit');
    const seconds = wav.readUInt32LE(40) / 48000;
    assert.ok(seconds >= 2.5 && seconds <= 6, 'about 3 seconds, got ' + seconds);
    // Not silence: the fake microphone's tone came through.
    let peak = 0;
    for (let i = 44; i < Math.min(wav.length - 1, 44 + 48000 * 2); i += 2) peak = Math.max(peak, Math.abs(wav.readInt16LE(i)));
    assert.ok(peak > 1000, 'audio level ' + peak);
    assert.ok(await p.isHidden('#rec-send'));
    await p.close();
  });

  await check('encode() resamples to 24 kHz', async () => {
    const p = await open({});
    const info = await p.evaluate(async () => {
      const blob = window.DocChatVoiceRecorder.encode([new Float32Array(48000).fill(0.5)], 48000);
      const view = new DataView(await blob.arrayBuffer());
      return { rate: view.getUint32(24, true), bytes: view.getUint32(40, true), sample: view.getInt16(100, true) };
    });
    assert.deepStrictEqual(info, { rate: 24000, bytes: 48000, sample: Math.floor(0.5 * 0x7fff) });
    await p.close();
  });

  assert.deepStrictEqual(errors, []);
  await browser.close();
  console.log(passed + ' checks passed');
})().catch((e) => { console.error(e); process.exit(1); });
