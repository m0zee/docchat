# Phase 9 handoff: website widget

Branch `phase-9`, stacked on `phase-8`.

## What was built

- **Public keys (`dc_pub_`).** One per workspace, made on the Widget page.
  - They work only from the listed websites, plus DocChat's own `APP_URL` for the preview. A missing or other `Origin` gets 403 `origin_not_allowed`.
  - They only ask. They are refused on documents and workspace routes (`wrong_key_type`).
  - Asks are always widget conversations with no user. The id must be a random 16 to 64 character visitor id, so a widget can never reach a Telegram or API conversation. `document_ids` is ignored.
  - The rate limit is per visitor IP (`DOCCHAT_API_PUBLIC_RATE_LIMIT_PER_MINUTE`, default 10 a minute), not per key, because every visitor of a site shares the key.
- **CORS.** Laravel's default CORS (`*`, no cookies) answers preflights, since a preflight never carries the key. The per-key origin check happens on the real request, on the server.
- **`GET /api/v1/widget`** returns the title, welcome text, color, position and handoff for the widget.
- **`/widget.js`** comes from `resources/widget/widget.js`. It is served outside the web middleware, so it sets no cookies, with a one-hour cache and an ETag. It is ASCII only and about 14 KB; Pest fails if it reaches 30 KB.
  - Shadow DOM, a bubble and a panel.
  - Streams over fetch with a ReadableStream.
  - Bold and line breaks are built as DOM nodes, never as HTML.
  - Source chips and a saved history of the last 40 messages. Storage errors are ignored.
  - New chat, a full-screen layout on phones, and dark mode.
- **Workspace > Widget** (owners only):
  - Settings: title, welcome text, color, position, allowed websites (bare domains become https), and handoff email and text.
  - Embed code with a copy button, and **New key**, which revokes the old key.
  - Live preview in an iframe.
- **Handoff.** When an answer is the "not found" refusal (`refused: true` in the stream's done event), the widget shows the handoff text and a mailto link.

## Checked here

- 316 Pest tests, Pint and PHPStan pass. New: tests/Feature/WidgetTest.php.
- `tests/browser/widget.cjs` passes all 6 checks in Chromium (Playwright 1.63):
  1. Streaming with sources.
  2. Answer HTML stays text (an `<img onerror>` did not run).
  3. History after a reload and the same conversation id; New chat starts a new id.
  4. The refusal handoff and the 429 message.
  5. Blocked localStorage.
  6. Full screen on a 375px phone, host-page CSS kept out, and dark mode.
- A new CI job runs that check. It installs Playwright in a temp folder, not as a project dependency.

## Notes

- Playwright stays out of package.json, because the spec says to ask before adding packages. If Moiz wants it as a dev dependency, the script becomes a normal `npm run` command.
- The preview uses the real key and documents, so preview questions count toward usage.

## Needs Moiz

Nothing to set up. Open Workspace > Widget, save once, and paste the snippet into any page on a listed site (`public/demo-site.html` comes in Phase 10).
