One shared DocChat bot per app serves every workspace. You link a chat to your workspace with a one-time code, and from then on that chat is answered from your documents.

## Link a chat

1. Open **Workspace > Chat apps** (owners only) and press **Create a link code**.
2. Follow the steps for your app:
   - **Telegram**: open the link on the device with the chat and press **Start**. For a group, add the bot to the group, then send `/start` followed by the code there.
   - **WhatsApp**: open the link on the phone and send the prepared message, or type it to the business number.
   - **Discord**: add DocChat to your server, then someone with Manage Server runs `/link` with the code in any channel.
3. The chat appears under **Linked chats**. **Unlink** stops it answering from your workspace.

Codes work once and expire after 15 minutes.

## Using it

- **Telegram**: ask in the chat. In groups the bot only answers when mentioned. `/new` starts a new conversation. Send a file to add it to your documents.
- **WhatsApp**: ask in the chat, or send a file to add it. Replies follow WhatsApp's 24-hour window.
- **Discord**: `/ask` asks a question, `/new` starts over, `/unlink` removes the link.

Answers are formatted for each app and split to fit its message limits, with the sources listed at the end.

## Not seeing the apps?

If the page says no chat bot is set up, the person running this DocChat server hasn't created the bots yet. Setup guides are in the repository: `n8n/README.md` for Telegram and WhatsApp, and `docs/discord.md` for Discord.
