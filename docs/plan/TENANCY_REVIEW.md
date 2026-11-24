# Tenancy review before Phase 6

Written 2026-11-24 after reading the code on `main` (Phases 0 to 5 merged, CI green). Phases 0 to 5 are finished, so Phase 6 can start. What is still open from them: real-key runs (eval numbers for the README) and the Oracle deploy, both on Moiz's side. They don't block Phase 6.

## Where ownership lives today

Everything is owned by a user. There are 9 places in the app and 14 test files that depend on that.

| Place | Today | Change for workspaces |
|---|---|---|
| `documents.user_id`, `conversations.user_id` | Owner, required, cascade on user delete | Add `workspace_id` (required). Keep `user_id` as "created by", nullable, because API, Telegram and Drive documents have no user. |
| `chunks` | No owner column. `Chunk::scopeOwnedBy($user)` does a `whereHas` on documents. | Add `workspace_id` (copied from the document) so retrieval filters one column next to the vector scan, with no join for ownership. |
| `messages` | No owner column | Add `workspace_id` as the spec asks (usage queries and isolation checks). |
| `User::documents()`, `User::conversations()` | Used by the chat page, documents page, dashboard, eval runner | Replace with `Workspace::documents()` and so on, through the current workspace. |
| `DocumentPolicy`, `ConversationPolicy` | `user_id === $user->id` | Membership of the document's workspace, plus role checks. |
| `Retriever::retrieve(User ...)` / `search(User ...)` | Filters by user | Takes a `Workspace`. Always filters `chunks.workspace_id` explicitly, even with the global scope, so a job or console call can never search across tenants. |
| `AnswerService` | Uses `$conversation->user` for retrieval; logs to a file only | Uses `$conversation->workspace`. Also records a `usage_events` row (channel, tokens, latency). Becomes the one entry point for web, API, widget and bots. |
| `DocumentService::createFromUpload($file, User)` | Stores under `documents/{user_id}/` | Takes a workspace, optional user, source, external_id and meta. Stores under `documents/{workspace_id}/`. Handles re-upload by external_id and the content_hash skip. |
| `IngestDocumentJob` | Writes chunks without an owner | Copies `workspace_id` to chunks, skips re-embedding when `content_hash` matches, fires `document.ready` / `document.failed` webhooks, records ingest usage. |
| `ChatRateLimit` | Per user, Livewire only | Stays per user for the web. API keys get their own per-key limiter. |
| `EvalRunner`, `docchat:eval --user` | Creates conversations on the user | Runs in a workspace; `--user` keeps working by picking that user's first workspace, and `--workspace` is added. |
| `SampleDocumentSeeder`, `CreateNewUser` | Create a user only | Also create the user's personal workspace and set it as current. |
| Factories and 14 test files | `Document::factory()->for($user)` | Factories get a workspace; a `workspaceFor($user)` test helper keeps tests short. |

## How the current workspace is found

- `users.current_workspace_id` (nullable). Set on registration, changed by the switcher. If it points to a workspace the user has left, the first workspace they belong to is used instead.
- A `CurrentWorkspace` service holds the workspace for the request. Web middleware sets it from the user; API middleware sets it from the API key.
- A `BelongsToWorkspace` trait adds a global scope (`workspace_id = current`) **only when a current workspace is set**, and fills `workspace_id` on create. Queue jobs and console commands have no current workspace, so they pass the workspace explicitly. That's why the Retriever filters explicitly too.

## Decisions I made (say if you want them different)

1. **Conversations stay private to the person who started them on the web**, even in a shared workspace. Documents are shared with the whole workspace. Members asking questions about shared files shouldn't see each other's chats.
2. **Roles:** owner can do everything, including API keys, webhooks, usage, widget settings and channel links. A member can upload, delete documents and chat. There's no invite UI in the spec, so Phase 6 adds no invitations; members can be added in tinker or a later phase.
3. **API keys are custom, not Laravel Sanctum.** The spec defines its own key format, abilities, origins and per-key limits, and Sanctum isn't installed (adding it would be a new package). Keys are stored as a SHA-256 hash plus a visible prefix and compared with `hash_equals`.
4. **Existing data** is moved into one workspace per user ("Moiz's workspace"), with that user as owner, in the migration itself.
5. **The only new package in Phase 6 is `dedoc/scramble`**, which the spec lists. Discord signatures use PHP's built-in sodium, and the SSE endpoint uses Laravel's built-in `response()->eventStream()`.

## Risks

- **A missed scope is a data leak.** Isolation tests cover every web page and API route with two workspaces, and the Retriever filter doesn't depend on the global scope.
- **Unique indexes with NULL.** `(workspace_id, source, external_id)` with a NULL external_id doesn't collide in Postgres, which is what we want for plain uploads.
- **SSE holds a PHP-FPM worker per open stream**, the same trade-off as `wire:stream`. Fine for a demo; noted in the README.
