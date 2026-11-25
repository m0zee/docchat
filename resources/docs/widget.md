The widget is a chat bubble for your website. Visitors ask questions without an account and get answers with sources from your workspace's documents.

## Set it up

1. Open **Workspace > Widget** (owners only).
2. Set the title, welcome message, colour and side of the screen.
3. List the websites allowed to use it, one per line, like `example.com`. The widget refuses to work anywhere else.
4. Optionally add a handoff email. It is shown when the documents have no answer, so visitors can reach a person.
5. Save, then copy the embed code into your site, just before `</body>`:

```html
<script src="https://your-docchat-domain/widget.js" data-key="dc_pub_..." async></script>
```

The preview on the settings page shows exactly what visitors see.

## How it behaves

- Answers stream in, with the sources as small labels.
- The conversation is kept in the visitor's browser, and **New chat** starts over.
- It works on phones (full screen) and follows the visitor's dark mode.
- Add `data-open` to the script tag to open the chat when the page loads.

## Is the key safe in my page?

Yes. The `dc_pub_` key is public by design: it can only ask questions, only from the websites you listed, only in visitor conversations, and with a rate limit per visitor. It can't read or change your documents.

## Answers on the visitor's device

Turn on **Answer on visitors' devices when they can** to let visitors whose Chrome already has its built-in AI model write answers in their own browser. Those answers cost you no tokens. Everyone else is answered as usual, and the widget never starts a model download for a visitor. See [AI in the browser](/docs/device-ai).
