The **Conversations** page shows every conversation in your workspace in one place. It is in the sidebar for workspace owners; members only see their own chats.

## What is listed

- Members' chats on the **Chat** page
- Website visitors who used the [widget](/docs/widget)
- Telegram, WhatsApp and Discord chats with the [bots](/docs/chat-apps)
- Chats started through the [API](/docs/api-guide)
- [Voice calls](/docs/calls) in the browser and on the phone, with their transcript, purpose, agenda, status and length

Filter by channel and by period (7, 30 or 90 days, or all time), and search for words in the messages, a member's name, or a contact's name or phone number. Click a conversation to read it in full.

## One-click summaries

Press **Summarize** on any conversation. DocChat writes a short summary for you:

- who it was with and what it was about
- the key points: what the person asked or said, and what they were told
- for calls with an agenda, each agenda point and the person's answer
- the follow-up, if any

The summary is saved with the conversation, so opening it again costs nothing. When new messages arrive, the button **Update with new messages** makes a fresh one. Summaries use the same AI model as your answers (your own key if you added one under [AI keys](/docs/ai-keys)) and appear on the **Usage** page.

## Scheduling calls

The **Schedule calls** tab lets you set up a call from one form:

1. **Who:** pick a saved contact, or add someone new with their name and a phone number or email. Tick **This person agreed to be called** and note how they agreed. DocChat never calls anyone without this.
2. **How:** an **invitation link** they open in their browser (free, emailed if they have an email, or copy it and send it yourself), or a **phone call** through Twilio, if the server has it set up.
3. **When:** now, or at a date and time. Pick the person's own time zone. Phone calls ring at that time, inside the server's calling hours. Invitation links open 5 minutes before the time and are emailed again when it is time.
4. **What the call is about**, an optional **agenda** and an optional opening line.

Calls that haven't started are listed on the right, with their time in the zone you chose. You can **Change time** or **Cancel** them.

### Agendas

Write the agenda one point per line, the way the assistant should ask it, for example:

```
Can you still make Tuesday at 3 pm?
Do you need parking at the venue?
Shall we send you a reminder the day before?
```

The assistant introduces itself as a virtual assistant, says what the call is about, and asks the first point. After each answer it says a short "Got it, thanks", or answers the person's question from your documents, then asks the next point. When the agenda is done it asks if there is anything else. Up to 8 points. The summary of the call lists each point with the answer.

Anyone can say "stop calling" at any time to end the call and never be called again.
