## Supported files

- **PDF** with selectable text. Scanned PDFs (pictures of pages) have no text to read and fail with a clear message.
- **TXT** and **Markdown**.
- Up to **10 MB** per file by default.

## What happens after upload

1. The file is stored privately, visible only inside your workspace.
2. DocChat reads it page by page and splits it into passages of about 800 tokens, overlapping a little so no sentence is cut off from its context. A passage never crosses a page, so every citation points to one page.
3. Each passage is turned into an embedding (a list of numbers that captures its meaning) and saved for search.
4. The badge turns **Ready**. If something fails, the badge turns **Failed** and shows why.

Uploading the same file again changes nothing. Uploading a new version replaces the old passages.

## Other ways to add documents

- **Google Drive**: with the n8n automation, files dropped in a Drive folder are added and kept in sync. Google Docs, Sheets and Slides are converted to PDF.
- **Chat apps**: send a file to the Telegram or WhatsApp bot in a linked chat.
- **API**: `POST /api/v1/documents` with a file. Sending the same `external_id` again replaces that document. See the [API guide](/docs/api-guide).

## Deleting

Deleting a document removes the file, its passages and their embeddings. Answers cached from it are cleared too.
