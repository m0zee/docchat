/**
 * DocChat voice consent recorder (the page at /voice-consent/{token}).
 *
 * Records the microphone in the browser and sends it as a mono 16-bit WAV
 * at 24 kHz, which the voice clone model reads directly. Recording happens
 * only here; there is no file upload, so a voice can't be cloned from
 * someone else's recording.
 *
 * The page has these elements: #consent-confirm (checkbox), #rec-start,
 * #rec-stop, #rec-send (buttons), #rec-time, #rec-status and #rec-preview
 * (an <audio>).
 */
(function () {
  'use strict';

  var RATE = 24000;

  // Mono 16-bit PCM WAV at RATE, resampled (linear) from the microphone's rate.
  function encode(chunks, from) {
    var length = 0;
    chunks.forEach(function (c) { length += c.length; });
    var all = new Float32Array(length);
    var at = 0;
    chunks.forEach(function (c) { all.set(c, at); at += c.length; });
    var count = Math.floor(length * RATE / from);
    var buffer = new ArrayBuffer(44 + count * 2);
    var view = new DataView(buffer);
    function text(offset, s) { for (var i = 0; i < s.length; i++) view.setUint8(offset + i, s.charCodeAt(i)); }
    text(0, 'RIFF'); view.setUint32(4, 36 + count * 2, true); text(8, 'WAVE');
    text(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
    view.setUint32(24, RATE, true); view.setUint32(28, RATE * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true);
    text(36, 'data'); view.setUint32(40, count * 2, true);
    for (var j = 0; j < count; j++) {
      var pos = j * from / RATE;
      var k = Math.floor(pos);
      var next = k + 1 < length ? all[k + 1] : all[k];
      var v = Math.max(-1, Math.min(1, all[k] + (next - all[k]) * (pos - k)));
      view.setInt16(44 + j * 2, v < 0 ? v * 0x8000 : v * 0x7fff, true);
    }
    return new Blob([buffer], { type: 'audio/wav' });
  }

  function mount(opts) {
    var t = opts.text || {};
    var $ = function (id) { return document.getElementById(id); };
    var startBtn = $('rec-start');
    var stopBtn = $('rec-stop');
    var sendBtn = $('rec-send');
    var timeEl = $('rec-time');
    var statusEl = $('rec-status');
    var preview = $('rec-preview');
    var confirmBox = $('consent-confirm');
    var rec = null;
    var wav = null;

    [stopBtn, sendBtn, preview].forEach(function (el) { show(el, false); });

    function say(message, bad) {
      statusEl.textContent = message;
      statusEl.className = 'text-sm ' + (bad ? 'text-red-600 dark:text-red-400' : 'text-zinc-600 dark:text-zinc-300');
      statusEl.setAttribute('data-state', bad ? 'error' : 'ok');
    }

    // Some button components set their own display, so hide with a style too.
    function show(el, on) {
      el.classList.toggle('hidden', !on);
      el.style.display = on ? '' : 'none';
    }

    function clock(seconds) {
      var s = Math.floor(seconds);
      return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }

    function stop() {
      if (!rec) return;
      var r = rec;
      rec = null;
      clearInterval(r.timer);
      r.processor.onaudioprocess = null;
      r.processor.disconnect();
      r.source.disconnect();
      r.stream.getTracks().forEach(function (track) { track.stop(); });
      r.ctx.close();
      show(stopBtn, false);
      show(startBtn, true);
      startBtn.textContent = t.again || 'Record again';
      timeEl.textContent = clock(r.samples / r.rate);
      if (r.samples / r.rate < opts.minSeconds) {
        say(t.short || 'Too short.', true);
        return;
      }
      wav = encode(r.chunks, r.rate);
      preview.src = URL.createObjectURL(wav);
      show(preview, true);
      show(sendBtn, true);
      say(t.listen || 'Listen back, then send it.');
    }

    startBtn.addEventListener('click', function () {
      if (!confirmBox.checked) {
        say(t.tick || 'Please tick the box first.', true);
        return;
      }
      wav = null;
      show(preview, false);
      show(sendBtn, false);
      say(t.asking || 'Asking for the microphone...');
      navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } }).then(function (stream) {
        var Ctx = window.AudioContext || window.webkitAudioContext;
        var ctx = new Ctx();
        var source = ctx.createMediaStreamSource(stream);
        var processor = ctx.createScriptProcessor(4096, 1, 1);
        var mute = ctx.createGain();
        mute.gain.value = 0;
        rec = { ctx: ctx, stream: stream, source: source, processor: processor, chunks: [], samples: 0, rate: ctx.sampleRate };
        processor.onaudioprocess = function (e) {
          if (!rec) return;
          var data = e.inputBuffer.getChannelData(0);
          rec.chunks.push(new Float32Array(data));
          rec.samples += data.length;
          if (rec.samples / rec.rate >= opts.maxSeconds) stop();
        };
        source.connect(processor);
        processor.connect(mute);
        mute.connect(ctx.destination);
        if (ctx.state === 'suspended' && ctx.resume) ctx.resume();
        rec.timer = setInterval(function () { if (rec) timeEl.textContent = clock(rec.samples / rec.rate); }, 250);
        show(startBtn, false);
        show(stopBtn, true);
        say(t.recording || 'Recording.');
      }).catch(function () {
        say(t.noMic || 'The microphone could not be used.', true);
      });
    });

    stopBtn.addEventListener('click', stop);

    sendBtn.addEventListener('click', function () {
      if (!wav) return;
      var form = new FormData();
      form.append('audio', wav, 'consent.wav');
      form.append('confirm', confirmBox.checked ? '1' : '0');
      form.append('_token', opts.csrf || '');
      sendBtn.disabled = true;
      say(t.checking || 'Checking...');
      fetch(opts.url, { method: 'POST', body: form, headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(function (r) {
        return r.json().then(function (body) {
          if (r.ok) {
            say(body.data.message);
            show(sendBtn, false);
            show(startBtn, false);
            if (opts.reload !== false) setTimeout(function () { window.location.reload(); }, 2500);
            return;
          }
          say((body && body.error && body.error.message) || (body && body.message) || t.failed || 'Something went wrong.', true);
          sendBtn.disabled = false;
        });
      }).catch(function () {
        say(t.failed || 'Something went wrong.', true);
        sendBtn.disabled = false;
      });
    });
  }

  window.DocChatVoiceRecorder = { mount: mount, encode: encode };
})();
