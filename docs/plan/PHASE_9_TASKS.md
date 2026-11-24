# Phase 9 tasks: embeddable website widget

Branch `phase-9`, PR stacked on `phase-8`.

Most end users are guests on the client's website, so this is the main user path. Guest conversations: `channel = widget`, no user, `external_id` = a random visitor id kept in the browser.

- [x] **P9-1 Public keys.** `dc_pub_` keys: ask ability only, allowed_origins required, own lower rate limit. Origin header validated server-side, CORS headers per key, preflight handled. Tests for origin enforcement, abilities and CORS.
- [x] **P9-2 widget.js.** Vanilla JS under 30 KB, Shadow DOM, floating bubble and panel, SSE streaming (fetch + ReadableStream, since EventSource can't send the key header), source chips, localStorage history in try/catch, new chat, mobile layout, dark mode. Served at `/widget.js` with caching headers. A size check in CI.
- [x] **P9-3 Widget settings.** Title, welcome message, brand color, allowed domains, handoff email, live preview, embed snippet with copy button. Settings are public config read by the widget.
- [x] **P9-4 Handoff on refusals.** When the answer is the "not found" refusal, the widget shows the handoff line.
- [x] **P9-5 Browser test and handoff.** Playwright check of the widget on a test page against a fake chat provider. Handoff file.
