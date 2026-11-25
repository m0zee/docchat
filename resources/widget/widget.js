/*! DocChat website widget. MIT. Embed:
 * <script src="https://your-docchat/widget.js" data-key="dc_pub_..." async></script>
 */
(function () {
  'use strict';

  var script = document.currentScript || document.querySelector('script[data-key][src*="widget.js"]');
  if (!script || window.__docchatWidget) return;
  window.__docchatWidget = true;

  var KEY = script.getAttribute('data-key') || '';
  var BASE = new URL(script.src, location.href).origin;
  var OPEN = script.hasAttribute('data-open');
  var STORE = 'docchat:' + KEY.slice(0, 14) + ':';
  var MAX_SAVED = 40;

  // Storage can be blocked (private mode, third-party settings): never let it break the widget.
  function load(name, fallback) {
    try {
      var raw = localStorage.getItem(STORE + name);
      return raw === null ? fallback : JSON.parse(raw);
    } catch (e) {
      return fallback;
    }
  }
  function save(name, value) {
    try { localStorage.setItem(STORE + name, JSON.stringify(value)); } catch (e) { /* ignore */ }
  }
  function randomId() {
    var bytes = new Uint8Array(18);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    return 'w' + Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  var state = {
    conversation: load('conversation', null) || randomId(),
    messages: load('messages', []),
    config: { title: 'Ask our documents', welcome: 'Hi! Ask me anything about our documents.', color: '#4f46e5', position: 'right', handoff: { email: null, text: '' } },
    busy: false,
  };
  save('conversation', state.conversation);

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

  // **bold** and line breaks only, built as DOM nodes: answer text is never parsed as HTML.
  function rich(text) {
    var frag = document.createDocumentFragment();
    String(text).split(/(\*\*[^*\n]+\*\*)/).forEach(function (part) {
      if (/^\*\*[^*\n]+\*\*$/.test(part)) {
        frag.appendChild(el('b', { text: part.slice(2, -2) }));
        return;
      }
      part.split('\n').forEach(function (line, i) {
        if (i > 0) frag.appendChild(document.createElement('br'));
        frag.appendChild(document.createTextNode(line));
      });
    });
    return frag;
  }

  var host = el('div', { id: 'docchat-widget' });
  var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
  var style = el('style');
  style.textContent = [
    ':host{all:initial}',
    '*{box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}',
    '.wrap{--c:#4f46e5;--bg:#fff;--fg:#18181b;--mut:#71717a;--bd:#e4e4e7;--me:#f4f4f5;position:fixed;bottom:20px;right:20px;z-index:2147483000;font-size:15px;line-height:1.45}',
    '.wrap.left{right:auto;left:20px}',
    '@media (prefers-color-scheme:dark){.wrap{--bg:#18181b;--fg:#f4f4f5;--mut:#a1a1aa;--bd:#3f3f46;--me:#27272a}}',
    '.bubble{width:56px;height:56px;border-radius:50%;border:0;background:var(--c);color:#fff;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center}',
    '.bubble svg{width:26px;height:26px}',
    '.panel{position:absolute;bottom:70px;right:0;width:370px;height:560px;max-height:calc(100vh - 110px);background:var(--bg);color:var(--fg);border:1px solid var(--bd);border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25);display:none;flex-direction:column;overflow:hidden}',
    '.left .panel{right:auto;left:0}',
    '.open .panel{display:flex}',
    '.head{background:var(--c);color:#fff;padding:12px 14px;display:flex;align-items:center;gap:8px}',
    '.head h2{flex:1;margin:0;font-size:16px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.icon{background:transparent;border:0;color:#fff;cursor:pointer;padding:4px 6px;border-radius:6px;font-size:13px}',
    '.icon:hover,.icon:focus-visible{background:rgba(255,255,255,.2);outline:none}',
    '.log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px}',
    '.msg{max-width:88%;padding:9px 12px;border-radius:14px;white-space:normal;overflow-wrap:anywhere}',
    '.bot{align-self:flex-start;background:var(--me);border-bottom-left-radius:4px}',
    '.me{align-self:flex-end;background:var(--c);color:#fff;border-bottom-right-radius:4px}',
    '.err{align-self:flex-start;color:#b91c1c;font-size:14px}',
    '.chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}',
    '.chip{font-size:12px;border:1px solid var(--bd);border-radius:999px;padding:2px 8px;color:var(--mut);background:var(--bg);cursor:default;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.handoff{margin-top:8px;font-size:13px}',
    '.handoff a{color:var(--c)}',
    '.dots::after{content:"\\2026";animation:b 1s infinite}',
    '@keyframes b{50%{opacity:.2}}',
    'form{display:flex;gap:8px;padding:10px;border-top:1px solid var(--bd)}',
    'textarea{flex:1;resize:none;border:1px solid var(--bd);border-radius:10px;padding:8px 10px;font-size:15px;background:var(--bg);color:var(--fg);max-height:120px}',
    'textarea:focus{outline:2px solid var(--c);outline-offset:-1px}',
    '.send{border:0;border-radius:10px;background:var(--c);color:#fff;padding:0 14px;cursor:pointer;font-weight:600}',
    '.send:disabled{opacity:.5;cursor:default}',
    '.foot{font-size:11px;color:var(--mut);text-align:center;padding:0 0 8px}',
    '@media (max-width:480px){.wrap{bottom:14px;right:14px}.wrap.left{left:14px}.open .panel{position:fixed;inset:0;width:auto;height:auto;max-height:none;border-radius:0;border:0}.open .bubble{display:none}}',
  ].join('');

  var log = el('div', { class: 'log', role: 'log', 'aria-live': 'polite' });
  var input = el('textarea', { rows: '1', placeholder: 'Type your question', 'aria-label': 'Your question', maxlength: '2000' });
  var send = el('button', { class: 'send', type: 'submit', text: 'Send' });
  var title = el('h2', { text: state.config.title });
  var bubbleIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>';
  var bubble = el('button', { class: 'bubble', type: 'button', 'aria-label': 'Open chat', 'aria-expanded': 'false', onclick: function () { toggle(); } });
  bubble.innerHTML = bubbleIcon;
  var form = el('form', { onsubmit: function (e) { e.preventDefault(); ask(); } }, [input, send]);
  var panel = el('div', { class: 'panel', role: 'dialog', 'aria-label': 'Chat' }, [
    el('div', { class: 'head' }, [
      title,
      el('button', { class: 'icon', type: 'button', text: 'New chat', title: 'Start a new conversation', onclick: newChat }),
      el('button', { class: 'icon', type: 'button', text: '\u2715', 'aria-label': 'Close chat', onclick: function () { toggle(false); } }),
    ]),
    log,
    form,
    el('div', { class: 'foot', text: 'Answers come from this site\u2019s documents.' }),
  ]);
  var wrap = el('div', { class: 'wrap' }, [panel, bubble]);
  root.appendChild(style);
  root.appendChild(wrap);

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(); }
  });
  input.addEventListener('input', function () {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  });

  function toggle(force) {
    var open = typeof force === 'boolean' ? force : !wrap.classList.contains('open');
    wrap.classList.toggle('open', open);
    bubble.setAttribute('aria-expanded', String(open));
    if (open) { input.focus(); log.scrollTop = log.scrollHeight; }
  }

  function chips(citations) {
    if (!citations || !citations.length) return null;
    return el('div', { class: 'chips' }, citations.map(function (c) {
      var label = '[' + c.n + '] ' + c.title + (c.page ? ', p. ' + c.page : '');
      return el('span', { class: 'chip', title: (c.snippet || label), text: label });
    }));
  }

  function handoff() {
    var h = state.config.handoff || {};
    if (!h.email && !h.text) return null;
    var line = el('div', { class: 'handoff' }, [document.createTextNode((h.text || 'Contact us') + ' ')]);
    if (h.email) line.appendChild(el('a', { href: 'mailto:' + h.email, text: h.email }));
    return line;
  }

  function bubbleFor(m) {
    if (m.role === 'user') return el('div', { class: 'msg me', text: m.text });
    if (m.role === 'error') return el('div', { class: 'err', text: m.text });
    var node = el('div', { class: 'msg bot' });
    node.appendChild(rich(m.text));
    var c = chips(m.citations);
    if (c) node.appendChild(c);
    if (m.refused) { var h = handoff(); if (h) node.appendChild(h); }
    return node;
  }

  function render() {
    log.textContent = '';
    log.appendChild(bubbleFor({ role: 'bot', text: state.config.welcome }));
    state.messages.forEach(function (m) { log.appendChild(bubbleFor(m)); });
    log.scrollTop = log.scrollHeight;
  }

  function remember(m) {
    state.messages.push(m);
    state.messages = state.messages.slice(-MAX_SAVED);
    save('messages', state.messages);
  }

  function newChat() {
    state.conversation = randomId();
    state.messages = [];
    save('conversation', state.conversation);
    save('messages', []);
    render();
    input.focus();
  }

  function headers() {
    return { Authorization: 'Bearer ' + KEY, Accept: 'application/json, text/event-stream', 'Content-Type': 'application/json' };
  }

  function errorText(status, body) {
    if (status === 429) return 'Too many questions at once. Please wait a minute.';
    if (body && body.error && body.error.code === 'model_busy') return 'The assistant is busy. Please try again in a minute.';
    if (body && body.error && body.error.code === 'origin_not_allowed') return 'This chat is not set up for this website yet.';
    return 'Sorry, something went wrong. Please try again.';
  }

  // Server-Sent Events over fetch, because EventSource cannot send the key header.
  function readStream(response, onEvent) {
    var reader = response.body.getReader();
    var decoder = new TextDecoder();
    var buffer = '';
    function pump() {
      return reader.read().then(function (chunk) {
        if (chunk.done) return;
        buffer += decoder.decode(chunk.value, { stream: true });
        var blocks = buffer.split('\n\n');
        buffer = blocks.pop();
        blocks.forEach(function (block) {
          var event = 'message';
          var data = '';
          block.split('\n').forEach(function (line) {
            if (line.indexOf('event:') === 0) event = line.slice(6).trim();
            else if (line.indexOf('data:') === 0) data += line.slice(5).trim();
          });
          try { onEvent(event, data ? JSON.parse(data) : {}); } catch (e) { /* skip a bad event */ }
        });
        return pump();
      });
    }
    return pump();
  }

  // In-browser AI (opt-in per workspace): when this visitor's Chrome already
  // has its built-in model downloaded, the browser writes the answer and the
  // server only searches. Never starts a model download for a visitor.
  var device = { ready: false };
  var DEVICE_LANGS = { type: 'text', languages: ['en'] };
  function deviceOptions() { return { expectedInputs: [DEVICE_LANGS], expectedOutputs: [DEVICE_LANGS] }; }
  function checkDevice() {
    device.ready = false;
    if (!state.config.device_ai || typeof window.LanguageModel === 'undefined') return;
    try {
      window.LanguageModel.availability(deviceOptions()).then(function (a) { device.ready = a === 'available'; }, function () {});
    } catch (e) { /* unsupported */ }
  }

  function askOnDevice(question, answer, done, fallback) {
    var conversationId = null;
    var token = null;
    function post(path, body) {
      return fetch(BASE + path, { method: 'POST', headers: headers(), body: JSON.stringify(body) })
        .then(function (r) { return r.json().catch(function () { return null; }).then(function (b) { return { ok: r.ok, status: r.status, body: b }; }); });
    }
    function show(text) {
      answer.classList.remove('dots');
      answer.textContent = '';
      answer.appendChild(rich(text));
      log.scrollTop = log.scrollHeight;
    }
    return post('/api/v1/ask/device', { question: question, external_conversation_id: state.conversation }).then(function (res) {
      if (!res.ok || !res.body || !res.body.data) {
        if (res.status === 403) { device.ready = false; return fallback(); }
        return done(null, errorText(res.status, res.body));
      }
      var data = res.body.data;
      if (data.status === 'answered') return done(data);
      conversationId = data.conversation_id;
      token = data.token;
      var messages = data.messages || [];
      var last = messages.pop() || { content: question };
      var text = '';
      var session = null;
      return window.LanguageModel.create(Object.assign(deviceOptions(), { initialPrompts: messages })).then(function (s) {
        session = s;
        var reader = s.promptStreaming(last.content).getReader();
        function pump() {
          return reader.read().then(function (chunk) {
            if (chunk.done) return;
            text += chunk.value;
            show(text);
            return pump();
          });
        }
        return pump();
      }).then(function () {
        if (session) session.destroy();
        return post('/api/v1/ask/device/complete', { conversation_id: conversationId, token: token, answer: text, engine: 'chrome' }).then(function (r) {
          if (r.ok && r.body && r.body.data) return done(r.body.data);
          return done(null, errorText(r.status, r.body));
        });
      }, function (error) {
        if (session) session.destroy();
        // The model failed on this device: record it, then ask the server instead.
        device.ready = false;
        post('/api/v1/ask/device/complete', { conversation_id: conversationId, token: token, answer: '', error: String((error && error.name) || 'error').slice(0, 200), engine: 'chrome' });
        return fallback();
      });
    });
  }

  function ask() {
    var question = input.value.trim();
    if (!question || state.busy) return;
    state.busy = true;
    send.disabled = true;
    input.value = '';
    input.style.height = 'auto';

    remember({ role: 'user', text: question });
    log.appendChild(bubbleFor({ role: 'user', text: question }));
    var answer = el('div', { class: 'msg bot dots' });
    log.appendChild(answer);
    log.scrollTop = log.scrollHeight;

    var text = '';
    var finished = false;
    function fail(message) {
      if (finished) return;
      finished = true;
      answer.remove();
      remember({ role: 'error', text: message });
      log.appendChild(bubbleFor({ role: 'error', text: message }));
    }

    function finish(data, error) {
      if (error || !data) return fail(error || errorText(0, null));
      finished = true;
      var m = { role: 'bot', text: data.answer || text, citations: data.citations || [], refused: !!data.refused };
      remember(m);
      answer.replaceWith(bubbleFor(m));
      log.scrollTop = log.scrollHeight;
    }

    var request = device.ready
      ? askOnDevice(question, answer, finish, serverAsk)
      : serverAsk();

    request.catch(function () {
      fail('Could not reach the assistant. Check your connection.');
    }).then(function () {
      state.busy = false;
      send.disabled = false;
    });

    function serverAsk() {
    answer.classList.add('dots');
    text = '';
    return fetch(BASE + '/api/v1/ask/stream', {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify({ question: question, external_conversation_id: state.conversation }),
    }).then(function (response) {
      var type = response.headers.get('Content-Type') || '';
      if (!response.ok || type.indexOf('text/event-stream') === -1) {
        return response.json().catch(function () { return null; }).then(function (body) { fail(errorText(response.status, body)); });
      }
      return readStream(response, function (event, data) {
        if (event === 'token') {
          text += data.text || '';
          answer.classList.remove('dots');
          answer.textContent = '';
          answer.appendChild(rich(text));
          log.scrollTop = log.scrollHeight;
        } else if (event === 'done') {
          finished = true;
          var m = { role: 'bot', text: data.answer || text, citations: data.citations || [], refused: !!data.refused };
          remember(m);
          answer.replaceWith(bubbleFor(m));
          log.scrollTop = log.scrollHeight;
        } else if (event === 'error') {
          fail(errorText(0, { error: data }));
        }
      }).then(function () { if (!finished) fail(errorText(0, null)); });
    });
    }
  }

  function applyConfig(config) {
    state.config = Object.assign(state.config, config || {});
    title.textContent = state.config.title;
    if (/^#[0-9a-fA-F]{3,8}$/.test(state.config.color || '')) wrap.style.setProperty('--c', state.config.color);
    wrap.classList.toggle('left', state.config.position === 'left');
    checkDevice();
    render();
  }

  function mount() {
    document.body.appendChild(host);
    render();
    fetch(BASE + '/api/v1/widget', { headers: headers() })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (body) { if (body && body.data) applyConfig(body.data); })
      .catch(function () { /* keep the defaults */ });
    if (OPEN) toggle(true);
  }

  if (document.body) mount();
  else document.addEventListener('DOMContentLoaded', mount);
})();
