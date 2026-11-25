# Conversations inbox, summaries and scheduled calls

Asked by Moiz on 2026-11-25: owners should see every past conversation (chats, widget guests, bots, calls with transcripts), get a one-click summary, and from the same page add a phone number or an invitation link and schedule an AI call at a set time with an agenda.

- [x] C1 Migration 000015: `calls.agenda`, `agenda_step`, `scheduled_at`, `timezone`, encrypted `link_token`; `conversations.summary`, `summary_message_id`, `summarized_at`.
- [x] C2 `CallStatus::Scheduled`, `isPending()`; `Call::agendaPoints()`, `isTooEarly()`, `scheduledLocal()`.
- [x] C3 `OutboundCalls` service: create (now or scheduled, consent and calling hours checked), reschedule, cancel, `startDue()`; Calls settings page reuses it.
- [x] C4 `docchat:start-scheduled-calls` every minute (claims calls atomically, cancels calls more than 30 min late, sends the "it's time" link email).
- [x] C5 Call link page and `/api/v1/call/answer` wait until 5 minutes before a scheduled time (425 `call_too_early`).
- [x] C6 `CallAgent` follows the agenda: first point in the opening, ack or document answer then the next point, "anything else" at the end, short "no thanks" doesn't hang up; transcript keeps what was said. `agenda_ack` phrases in all 7 languages.
- [x] C7 `ConversationSummarizer`: prompt with channel, call purpose and agenda; transcript as data; long transcripts keep start and end; cached by last message id; usage logged as `summary`.
- [x] C8 Owner-only `/conversations` page (sidebar): list with channel, period and search filters, transcript view, Summarize / Update; Schedule calls tab with new or saved contact, link or phone, now or date/time/time zone, agenda, opening; upcoming list with change time and cancel.
- [x] C9 Tests: tests/Feature/ConversationsPageTest.php, tests/Feature/ScheduledCallsTest.php.
- [x] C10 README, /docs/conversations, /docs/calls, docs/calls.md, landing feature, pricing page.
