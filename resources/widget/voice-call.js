/*! DocChat voice calls in the browser. MIT.
 * Used by the website widget, call links and the test call on Workspace > Calls.
 *
 *   DocChatVoiceCall.mount(container, {
 *     base: 'https://your-docchat',          // DocChat's address
 *     start: function () { return Promise<{ token, greeting, max_seconds }> },
 *     styleRoot: shadowRoot,                 // optional, where the styles go
 *     onEnd: function (reason) {}            // optional
 *   });
 *
 * The microphone is recorded one utterance at a time (it stops after a pause),
 * sent to DocChat for speech to text, and the answer is read aloud with the
 * best voice the browser has, or with the self-hosted Kokoro voice. The start
 * reply's "voice" settings choose the language, voice, rate and pitch, the
 * pace, fillers ("One moment."), soft hold music while an answer is written,
 * a quiet background sound, and whether the caller may talk over the
 * assistant (the mic keeps listening while it speaks, and speech stops it).
 */
(function () {
  'use strict';
  if (window.DocChatVoiceCall) return;

  var CSS = [
    '.dcv{--c:#4f46e5;--bg:#fff;--fg:#18181b;--mut:#71717a;--bd:#e4e4e7;--me:#f4f4f5;display:flex;flex-direction:column;height:100%;min-height:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:15px;line-height:1.45}',
    '@media (prefers-color-scheme:dark){.dcv{--bg:#18181b;--fg:#f4f4f5;--mut:#a1a1aa;--bd:#3f3f46;--me:#27272a}}',
    '.dcv *{box-sizing:border-box}',
    '.dcv-top{display:flex;flex-direction:column;align-items:center;gap:8px;padding:18px 14px 12px;border-bottom:1px solid var(--bd)}',
    '.dcv-orb{width:72px;height:72px;border-radius:50%;background:var(--c);display:flex;align-items:center;justify-content:center;color:#fff;transition:transform .08s linear;box-shadow:0 0 0 0 rgba(79,70,229,.4)}',
    '.dcv-orb svg{width:30px;height:30px}',
    '.dcv-speaking .dcv-orb{animation:dcvp 1.2s ease-in-out infinite}',
    '@keyframes dcvp{0%,100%{box-shadow:0 0 0 0 rgba(127,127,127,.35)}50%{box-shadow:0 0 0 12px rgba(127,127,127,0)}}',
    '.dcv-status{font-weight:600}',
    '.dcv-time{font-size:12px;color:var(--mut);font-variant-numeric:tabular-nums}',
    '.dcv-log{flex:1;min-height:0;overflow-y:auto;padding:12px 14px;display:flex;flex-direction:column;gap:8px}',
    '.dcv-line{max-width:90%;padding:8px 11px;border-radius:12px;overflow-wrap:anywhere}',
    '.dcv-agent{align-self:flex-start;background:var(--me)}',
    '.dcv-you{align-self:flex-end;background:var(--c);color:#fff}',
    '.dcv-note{align-self:center;font-size:13px;color:var(--mut);text-align:center}',
    '.dcv-src{display:block;margin-top:4px;font-size:12px;color:var(--mut)}',
    '.dcv-type{display:flex;gap:6px;padding:8px 10px;border-top:1px solid var(--bd)}',
    '.dcv-type input{flex:1;min-width:0;border:1px solid var(--bd);border-radius:10px;padding:7px 10px;font-size:14px;background:var(--bg);color:var(--fg)}',
    '.dcv-btns{display:flex;gap:8px;justify-content:center;padding:10px}',
    '.dcv button{border:0;border-radius:999px;padding:9px 16px;font-weight:600;cursor:pointer;font-size:14px}',
    '.dcv button:focus-visible{outline:2px solid var(--c);outline-offset:2px}',
    '.dcv-mute{background:var(--me);color:var(--fg)}',
    '.dcv-mute[aria-pressed="true"]{background:#fde68a;color:#78350f}',
    '.dcv-end{background:#dc2626;color:#fff}',
    '.dcv-send{background:var(--c);color:#fff;border-radius:10px!important;padding:7px 12px!important}',
    '.dcv button:disabled{opacity:.5;cursor:default}',
  ].join('');

  var PHONE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>';

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (name) {
      if (name === 'text') node.textContent = attrs[name];
      else if (name.indexOf('on') === 0) node.addEventListener(name.slice(2), attrs[name]);
      else node.setAttribute(name, attrs[name]);
    });
    (children || []).forEach(function (child) { if (child) node.appendChild(child); });
    return node;
  }

  function supported() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder && (window.AudioContext || window.webkitAudioContext));
  }

  function pickMime() {
    var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
    for (var i = 0; i < types.length; i++) {
      if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
    }
    return '';
  }

  function extFor(mime) {
    if (mime.indexOf('ogg') !== -1) return 'ogg';
    if (mime.indexOf('mp4') !== -1) return 'm4a';
    return 'webm';
  }

  // Voice names that usually mean a natural (neural) voice, best first.
  var NATURAL = /(natural|neural|online|premium|enhanced|wavenet|google)/i;
  var FEMALE = /(female|woman|samantha|victoria|karen|moira|tessa|fiona|zira|aria|jenny|sonia|libby|natasha|emma|ava|allison|susan|serena|kate|michelle|ana\b|elvira|paulina|monica|helena|denise|amelie|katja|hedda|swara|kalpana|heera|neerja|uzma|gul|salma|zariyah|hoda|lekha|veena|zuzana|joanna|olivia|lucia|lea|vicki|hala)/i;
  var MALE = /(\bmale|\bman\b|daniel|alex\b|fred|david|mark|guy|ryan|thomas|rishi|george|james|arthur|aaron|tom\b|lee\b|oliver|jorge|diego|alvaro|raul|henri|remy|conrad|stefan|madhur|prabhat|asad|salman|hamed|naayf|matthew|brian|sergio|andres|zayd|reed|eddy|grandpa|rocko)/i;

  // Score the device's voices for the call's language, gender and the owner's pick.
  function pickVoice(list, prefs) {
    prefs = prefs || {};
    var lang = String(prefs.lang || '').toLowerCase();
    var base = lang.split('-')[0];
    var best = null;
    var bestScore = -1;
    (list || []).forEach(function (v) {
      var vlang = String(v.lang || '').toLowerCase().replace('_', '-');
      var score = 0;
      if (prefs.name && v.name === prefs.name) score += 100;
      if (lang && vlang === lang) score += 30;
      else if (base && vlang.split('-')[0] === base) score += 18;
      else if (lang) return;
      if (NATURAL.test(v.name)) score += 10;
      if (prefs.gender === 'female' && FEMALE.test(v.name) && !MALE.test(v.name)) score += 6;
      if (prefs.gender === 'male' && MALE.test(v.name)) score += 6;
      if (prefs.gender === 'female' && MALE.test(v.name) && !FEMALE.test(v.name)) score -= 4;
      if (prefs.gender === 'male' && FEMALE.test(v.name) && !MALE.test(v.name)) score -= 4;
      if (v.localService === false) score += 2;
      if (score > bestScore) { best = v; bestScore = score; }
    });
    return best;
  }

  function loadVoices(timeout) {
    var synth = window.speechSynthesis;
    return new Promise(function (resolve) {
      if (!synth || !synth.getVoices) return resolve([]);
      var list = synth.getVoices() || [];
      if (list.length) return resolve(list);
      var done = false;
      function finish() { if (!done) { done = true; resolve(synth.getVoices() || []); } }
      try { synth.addEventListener('voiceschanged', finish, { once: true }); } catch (e) { /* old browsers */ }
      setTimeout(finish, timeout || 1200);
    });
  }

  // Soft sounds made with Web Audio, so there are no files to load or license.
  function Sounds(ctx) {
    this.ctx = ctx;
    this.bg = null;
    this.hold = null;
  }
  Sounds.prototype.background = function (kind) {
    if (!this.ctx || !kind || kind === 'none' || this.bg) return;
    var ctx = this.ctx;
    var seconds = 4;
    var buffer = ctx.createBuffer(1, ctx.sampleRate * seconds, ctx.sampleRate);
    var data = buffer.getChannelData(0);
    var last = 0;
    for (var i = 0; i < data.length; i++) {
      // Brown noise: a soft rumble, like a room with people far away.
      last = (last + 0.02 * (Math.random() * 2 - 1)) / 1.02;
      data[i] = last * 3.5;
    }
    var src = ctx.createBufferSource();
    src.buffer = buffer;
    src.loop = true;
    var filter = ctx.createBiquadFilter();
    filter.type = kind === 'cafe' ? 'bandpass' : 'lowpass';
    filter.frequency.value = kind === 'cafe' ? 700 : 420;
    var gain = ctx.createGain();
    gain.gain.value = kind === 'cafe' ? 0.05 : 0.035;
    src.connect(filter); filter.connect(gain); gain.connect(ctx.destination);
    src.start();
    this.bg = { src: src, gain: gain };
  };
  Sounds.prototype.startHold = function () {
    if (!this.ctx || this.hold) return;
    var ctx = this.ctx;
    var gain = ctx.createGain();
    gain.gain.setValueAtTime(0, ctx.currentTime);
    gain.gain.linearRampToValueAtTime(0.035, ctx.currentTime + 0.6);
    gain.connect(ctx.destination);
    var chords = [[261.63, 329.63, 392.0], [220.0, 261.63, 329.63], [174.61, 220.0, 261.63], [196.0, 246.94, 293.66]];
    var oscs = [];
    var t0 = ctx.currentTime;
    for (var n = 0; n < 3; n++) {
      var osc = ctx.createOscillator();
      osc.type = 'sine';
      for (var k = 0; k < 40; k++) osc.frequency.setValueAtTime(chords[k % chords.length][n], t0 + k * 1.6);
      osc.connect(gain);
      osc.start();
      oscs.push(osc);
    }
    this.hold = { gain: gain, oscs: oscs };
  };
  Sounds.prototype.stopHold = function () {
    if (!this.hold) return;
    var hold = this.hold;
    this.hold = null;
    try {
      hold.gain.gain.cancelScheduledValues(this.ctx.currentTime);
      hold.gain.gain.linearRampToValueAtTime(0, this.ctx.currentTime + 0.25);
      setTimeout(function () { hold.oscs.forEach(function (o) { try { o.stop(); } catch (e) { /* stopped */ } }); }, 300);
    } catch (e) { /* closed */ }
  };
  Sounds.prototype.stop = function () {
    this.stopHold();
    if (this.bg) { try { this.bg.src.stop(); } catch (e) { /* stopped */ } this.bg = null; }
  };

  function mount(container, opts) {
    opts = opts || {};
    var base = String(opts.base || '').replace(/\/$/, '');
    var styleRoot = opts.styleRoot || document.head;
    if (!styleRoot.querySelector || !styleRoot.querySelector('style[data-dcv]')) {
      var style = el('style', { 'data-dcv': '1' });
      style.textContent = CSS;
      styleRoot.appendChild(style);
    }

    var state = { token: null, ended: false, muted: false, stream: null, ctx: null, analyser: null, started: 0, max: 600, timer: null, pendingText: null, wake: null, recorder: null, floor: 0, voice: null, picked: null, sounds: null, spokeAt: 0 };
    var settings = {
      engine: 'browser', lang: opts.lang || '', gender: '', browser_voice: null, rate: 1, pitch: 1,
      interruptions: true, end_of_turn_ms: 1000, reply_gap_ms: 0, fillers: [], hold_music: false, background: 'none',
    };

    var orb = el('div', { class: 'dcv-orb' });
    orb.innerHTML = PHONE;
    var status = el('div', { class: 'dcv-status', role: 'status', 'aria-live': 'polite', text: 'Connecting...' });
    var time = el('div', { class: 'dcv-time', text: '0:00' });
    var log = el('div', { class: 'dcv-log', role: 'log', 'aria-label': 'Call transcript' });
    var input = el('input', { type: 'text', placeholder: 'Or type here', 'aria-label': 'Type instead of speaking', maxlength: '1000' });
    var sendBtn = el('button', { class: 'dcv-send', type: 'submit', text: 'Send' });
    var typeForm = el('form', { class: 'dcv-type', onsubmit: function (e) { e.preventDefault(); typed(); } }, [input, sendBtn]);
    var muteBtn = el('button', { class: 'dcv-mute', type: 'button', 'aria-pressed': 'false', text: 'Mute', onclick: toggleMute });
    var endBtn = el('button', { class: 'dcv-end', type: 'button', text: 'End call', onclick: function () { finish('You ended the call.', true); } });
    var root = el('div', { class: 'dcv' }, [
      el('div', { class: 'dcv-top' }, [orb, status, time]),
      log,
      typeForm,
      el('div', { class: 'dcv-btns' }, [muteBtn, endBtn]),
    ]);
    if (opts.color && /^#[0-9a-fA-F]{3,8}$/.test(opts.color)) root.style.setProperty('--c', opts.color);
    container.textContent = '';
    container.appendChild(root);

    function setStatus(text, speaking) {
      status.textContent = text;
      root.classList.toggle('dcv-speaking', !!speaking);
    }

    function line(who, text, citations) {
      var node = el('div', { class: 'dcv-line dcv-' + who, text: text });
      if (citations && citations.length) {
        node.appendChild(el('span', { class: 'dcv-src', text: 'Sources: ' + citations.map(function (c) { return c.title + (c.page ? ' p. ' + c.page : ''); }).join(', ') }));
      }
      log.appendChild(node);
      log.scrollTop = log.scrollHeight;
    }

    function note(text) {
      log.appendChild(el('div', { class: 'dcv-note', text: text }));
      log.scrollTop = log.scrollHeight;
    }

    function tick() {
      var s = Math.floor((Date.now() - state.started) / 1000);
      time.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
      if (s >= state.max) finish('The call reached its time limit.', true);
    }

    function toggleMute() {
      state.muted = !state.muted;
      muteBtn.setAttribute('aria-pressed', String(state.muted));
      muteBtn.textContent = state.muted ? 'Unmute' : 'Mute';
      if (state.stream) state.stream.getAudioTracks().forEach(function (t) { t.enabled = !state.muted; });
      if (state.wake) state.wake();
    }

    function typed() {
      var text = input.value.trim();
      if (!text || state.ended) return;
      input.value = '';
      state.pendingText = text;
      if (state.wake) state.wake();
    }

    function applyVoice(voice) {
      if (!voice) return;
      Object.keys(settings).forEach(function (k) { if (voice[k] !== undefined && voice[k] !== null) settings[k] = voice[k]; });
      if (voice.browser_voice === null) settings.browser_voice = null;
      state.picked = null;
    }

    function browserVoice() {
      if (state.picked !== null) return state.picked;
      var synth = window.speechSynthesis;
      state.picked = synth && synth.getVoices ? pickVoice(synth.getVoices(), { lang: settings.lang, gender: settings.gender, name: settings.browser_voice }) : null;
      return state.picked;
    }

    // While the assistant speaks, listen for the caller talking over it.
    // Resolves once speech is heard (and calls stop()), or never.
    function watchBargeIn(stop) {
      if (!settings.interruptions || !state.analyser || state.muted) return function () {};
      var began = Date.now();
      var floor = 0;
      var n = 0;
      var loud = 0;
      var poll = setInterval(function () {
        if (state.ended || state.muted) return;
        var v = level();
        if (Date.now() - began < 250) { floor += v; n++; return; }
        var threshold = Math.max(0.03, (n ? floor / n : 0) * 3, state.floor * 4);
        loud = v > threshold ? loud + 1 : 0;
        if (loud >= 5) { clearInterval(poll); stop(); }
      }, 60);
      return function () { clearInterval(poll); };
    }

    // Read text aloud; resolves { interrupted } when done.
    function speak(text, interruptible) {
      return new Promise(function (resolve) {
        if (state.ended || !text) return resolve({ interrupted: false });
        setStatus('Assistant speaking...', true);
        var done = false;
        var unwatch = function () {};
        function finishSpeak(interrupted) {
          if (done) return;
          done = true;
          unwatch();
          state.cancelSpeech = null;
          state.spokeAt = Date.now();
          resolve({ interrupted: !!interrupted });
        }
        var cancel = (settings.engine === 'kokoro' || settings.engine === 'clone' ? speakKokoro : speakBrowser)(text, finishSpeak);
        state.cancelSpeech = function () { cancel(); finishSpeak(false); };
        if (interruptible !== false) unwatch = watchBargeIn(function () { cancel(); finishSpeak(true); });
      });
    }

    function speakBrowser(text, done) {
      var synth = window.speechSynthesis;
      var guess = 1200 + text.length * 75 / Math.max(0.5, settings.rate || 1);
      var timer = setTimeout(function () { done(false); }, guess + 4000);
      function end() { clearTimeout(timer); done(false); }
      if (!synth || !window.SpeechSynthesisUtterance) { clearTimeout(timer); timer = setTimeout(end, Math.min(guess, 6000)); return function () { clearTimeout(timer); }; }
      try {
        synth.cancel();
        var voice = browserVoice();
        var parts = sentences(text);
        parts.forEach(function (part, i) {
          var u = new SpeechSynthesisUtterance(part.trim());
          if (voice) u.voice = voice;
          if (settings.lang) u.lang = voice && voice.lang ? voice.lang : settings.lang;
          u.rate = settings.rate || 1;
          u.pitch = settings.pitch || 1;
          if (i === parts.length - 1) { u.onend = end; u.onerror = end; }
          synth.speak(u);
        });
      } catch (e) { end(); }
      return function () { clearTimeout(timer); try { synth.cancel(); } catch (e) { /* ignore */ } };
    }

    // Split at punctuation + space, so "2.5" stays whole.
    function sentences(text) {
      var parts = String(text).split(/(?<=[.!?\u0964\u06D4\u061F])\s+/).filter(function (p) { return p.trim() !== ''; });
      return parts.length ? parts : [text];
    }

    // Kokoro: fetch each sentence as audio and play them in order. The next
    // sentence is fetched while the current one plays. Falls back to the
    // browser voice for whatever is left if anything fails.
    function speakKokoro(text, done) {
      var parts = sentences(text);
      parts = parts.map(function (p) { return p.trim(); }).filter(Boolean);
      var stopped = false;
      var audio = null;
      var fallback = null;
      function fetchPart(i) {
        if (i >= parts.length) return null;
        return post('/api/v1/call/speech', { text: parts[i] }, true).then(function (res) {
          if (res.status !== 200 || !res.blob) throw new Error('voice');
          return URL.createObjectURL(res.blob);
        });
      }
      function play(i, pending) {
        if (stopped) return;
        if (i >= parts.length) return done(false);
        var next = null;
        pending.then(function (url) {
          if (stopped) return;
          next = fetchPart(i + 1);
          if (next) next.catch(function () { /* handled when reached */ });
          audio = new Audio(url);
          audio.onended = function () { URL.revokeObjectURL(url); play(i + 1, next || Promise.resolve(null)); };
          audio.onerror = function () { browserRest(i); };
          var playing = audio.play();
          if (playing && playing.catch) playing.catch(function () { browserRest(i); });
        }, function () { browserRest(i); });
      }
      function browserRest(i) {
        if (stopped) return;
        settings.engine = 'browser';
        fallback = speakBrowser(parts.slice(i).join(' '), done);
      }
      play(0, fetchPart(0) || Promise.resolve(null));
      return function () {
        stopped = true;
        if (audio) { try { audio.pause(); } catch (e) { /* ignore */ } }
        if (fallback) fallback();
      };
    }

    function getMic() {
      if (state.stream || !supported()) return Promise.resolve(state.stream);
      return navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }).then(function (stream) {
        state.stream = stream;
        var Ctx = window.AudioContext || window.webkitAudioContext;
        state.ctx = new Ctx();
        var source = state.ctx.createMediaStreamSource(stream);
        state.analyser = state.ctx.createAnalyser();
        state.analyser.fftSize = 1024;
        source.connect(state.analyser);
        return stream;
      }).catch(function () {
        note('No microphone access. You can type instead.');
        return null;
      });
    }

    function audioContext() {
      if (state.ctx) return state.ctx;
      var Ctx = window.AudioContext || window.webkitAudioContext;
      try { state.ctx = Ctx ? new Ctx() : null; } catch (e) { state.ctx = null; }
      return state.ctx;
    }

    function level() {
      var data = new Float32Array(state.analyser.fftSize);
      state.analyser.getFloatTimeDomainData(data);
      var sum = 0;
      for (var i = 0; i < data.length; i++) sum += data[i] * data[i];
      return Math.sqrt(sum / data.length);
    }

    // One utterance: { audio: Blob } after speech and a pause, { text } when typed,
    // or { silence: true } when nothing was said for a while.
    function listen(talking) {
      return new Promise(function (resolve) {
        var settled = false;
        var recorder = null;
        var chunks = [];
        var poll = null;
        var waitingMute = false;
        function settle(value) {
          if (settled) return;
          settled = true;
          clearInterval(poll);
          state.wake = null;
          if (recorder && recorder.state !== 'inactive') {
            recorder.onstop = function () { resolve(value.keep ? { audio: new Blob(chunks, { type: recorder.mimeType || 'audio/webm' }), at: value.at } : value); };
            try { recorder.stop(); } catch (e) { resolve(value); }
          } else resolve(value.keep ? { silence: true } : value);
        }
        state.wake = function () {
          if (state.ended) return settle({ ended: true });
          if (state.pendingText) { var t = state.pendingText; state.pendingText = null; return settle({ text: t }); }
          if (waitingMute && !state.muted) return settle({ muted: true });
        };
        if (state.pendingText) return state.wake();

        if (!state.stream || state.muted) {
          waitingMute = !!state.stream;
          setStatus(state.stream ? 'Muted. Unmute or type.' : 'Type your question below.');
          return;
        }

        setStatus('Listening...');
        var mime = pickMime();
        try {
          recorder = mime ? new MediaRecorder(state.stream, { mimeType: mime }) : new MediaRecorder(state.stream);
        } catch (e) { recorder = new MediaRecorder(state.stream); }
        state.recorder = recorder;
        recorder.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
        recorder.start(250);

        var began = Date.now();
        var floor = 0;
        var samples = 0;
        // After an interruption the caller is already talking.
        var heardAt = talking ? began : 0;
        var quietSince = 0;
        var endAfter = Math.max(400, settings.end_of_turn_ms || 1000);
        poll = setInterval(function () {
          if (state.muted) return settle({ muted: true });
          var now = Date.now();
          var v = level();
          orb.style.transform = 'scale(' + (1 + Math.min(v * 6, 0.35)) + ')';
          if (!talking && now - began < 300) { floor += v; samples++; return; }
          if (samples) state.floor = floor / samples;
          var threshold = Math.max(0.012, (state.floor || 0) * 2.5);
          if (v > threshold) { if (!heardAt) heardAt = now; quietSince = 0; }
          else if (heardAt && !quietSince) quietSince = now;
          if (heardAt && quietSince && now - quietSince > endAfter) return settle({ keep: true, at: now - (now - quietSince) });
          if (heardAt && now - heardAt > 20000) return settle({ keep: true });
          if (!heardAt && now - began > 9000) return settle({ silence: true });
        }, 60);
      });
    }

    function post(path, body, binary) {
      var headers = { Authorization: 'Bearer ' + state.token, Accept: binary ? 'audio/mpeg' : 'application/json' };
      var init = { method: 'POST', headers: headers };
      if (body instanceof FormData) init.body = body;
      else { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body || {}); }
      return fetch(base + path, init).then(function (r) {
        if (binary && r.ok) return r.blob().then(function (blob) { return { status: r.status, blob: blob }; });
        return r.json().catch(function () { return null; }).then(function (json) { return { status: r.status, body: json }; });
      });
    }

    // While the answer is written: a short filler after a moment, then soft
    // hold music until it arrives. Returns a function that stops both and
    // resolves once a filler being spoken has finished.
    function whileThinking() {
      var stopped = false;
      var talking = Promise.resolve();
      var fillers = settings.fillers || [];
      var fillerTimer = fillers.length ? setTimeout(function () {
        if (stopped) return;
        var text = fillers[Math.floor(Math.random() * fillers.length)];
        talking = speak(text, false).then(function () { if (!stopped) setStatus('Thinking...'); });
      }, 1100) : null;
      var holdTimer = settings.hold_music && state.sounds ? setTimeout(function () {
        if (!stopped) state.sounds.startHold();
      }, fillers.length ? 2800 : 1600) : null;
      return function () {
        stopped = true;
        clearTimeout(fillerTimer);
        clearTimeout(holdTimer);
        if (state.sounds) state.sounds.stopHold();
        return talking;
      };
    }

    function pause(ms) { return new Promise(function (resolve) { setTimeout(resolve, Math.max(0, ms)); }); }

    function turn(input) {
      var form = new FormData();
      if (input.text) form.append('text', input.text);
      if (input.audio) form.append('audio', input.audio, 'speech.' + extFor(input.audio.type || ''));
      if (input.text) line('you', input.text);
      setStatus('Thinking...');
      return post('/api/v1/call/turns', form);
    }

    function finish(reason, hangUp) {
      if (state.ended) return;
      state.ended = true;
      clearInterval(state.timer);
      if (state.cancelSpeech) state.cancelSpeech();
      if (state.recorder && state.recorder.state !== 'inactive') { try { state.recorder.stop(); } catch (e) { /* ignore */ } }
      if (state.stream) state.stream.getTracks().forEach(function (t) { t.stop(); });
      if (state.sounds) state.sounds.stop();
      if (state.ctx) { try { state.ctx.close(); } catch (e) { /* ignore */ } }
      if (state.wake) state.wake();
      if (hangUp && state.token) {
        try { fetch(base + '/api/v1/call/end', { method: 'POST', keepalive: true, headers: { Authorization: 'Bearer ' + state.token, Accept: 'application/json' } }); } catch (e) { /* ignore */ }
      }
      setStatus('Call ended');
      orb.style.transform = '';
      note(reason || 'Call ended.');
      muteBtn.disabled = true;
      endBtn.disabled = true;
      sendBtn.disabled = true;
      input.disabled = true;
      if (opts.onEnd) opts.onEnd(reason);
    }

    function loop(talking) {
      if (state.ended) return Promise.resolve();
      return listen(talking).then(function (heard) {
        if (state.ended || heard.ended) return;
        if (heard.muted) return loop();
        var stopThinking = heard.silence ? function () { return Promise.resolve(); } : whileThinking();
        var saidAt = heard.at || Date.now();
        return turn(heard).then(function (res) {
          return stopThinking().then(function () { return res; });
        }).then(function (res) {
          if (state.ended) return;
          if (res.status === 409 || res.status === 410 || res.status === 401) return finish('This call has ended.', false);
          if (res.status === 429) { note('Too many requests. Please wait a moment.'); return loop(); }
          if (res.status !== 200 || !res.body || !res.body.data) { note('Something went wrong. Please try again.'); return loop(); }
          var d = res.body.data;
          if (d.lang && d.lang !== settings.lang) { settings.lang = d.lang; state.picked = null; }
          if (d.fillers) settings.fillers = d.fillers;
          if (d.engine) settings.engine = d.engine;
          if (d.heard && !heard.text) line('you', d.heard);
          line('agent', d.reply, d.citations);
          // A short, natural gap before answering, if the reply came back very fast.
          return pause((settings.reply_gap_ms || 0) - (Date.now() - saidAt)).then(function () {
            return speak(d.reply);
          }).then(function (spoken) {
            if (d.ended) return finish('The assistant ended the call.', false);
            return loop(spoken.interrupted);
          });
        }, function () {
          stopThinking();
          if (state.ended) return;
          note('Could not reach the assistant. Check your connection.');
          return loop();
        });
      });
    }

    var controller = { end: function () { finish('You ended the call.', true); }, root: root };

    // Ask for the mic first, inside the click that opened the call.
    getMic().then(function () {
      return Promise.resolve(opts.start());
    }).then(function (data) {
      if (!data || !data.token) throw new Error('no token');
      state.token = data.token;
      state.max = data.max_seconds || 600;
      state.started = Date.now();
      state.timer = setInterval(tick, 1000);
      applyVoice(data.voice);
      if (settings.hold_music || (settings.background && settings.background !== 'none')) {
        state.sounds = new Sounds(audioContext());
        state.sounds.background(settings.background);
      }
      line('agent', data.greeting);
      return loadVoices().then(function () { return speak(data.greeting); }).then(function (spoken) { return loop(spoken.interrupted); });
    }).catch(function (error) {
      finish(error && error.message && error.message !== 'no token' ? error.message : 'The call could not start. Please try again later.', false);
    });

    return controller;
  }

  window.DocChatVoiceCall = { mount: mount, supported: supported, pickVoice: pickVoice, loadVoices: loadVoices, version: 2 };
})();
