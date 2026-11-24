# Discord

DocChat answers Discord slash commands itself, with no n8n in between. Discord sends each command to `POST /discord/interactions`, signed with Ed25519. DocChat checks the signature and the timestamp (5 minutes, against replays), answers `/ask` with "thinking...", and a queued job then edits that message with the answer. Long answers continue in follow-up messages under Discord's 2000 characters. Mentions in answers are disabled, so an answer can never ping `@everyone`.

Discord is free: an application with slash commands needs no paid plan, and no bot user has to be online.

## Commands

| Command | Who | What it does |
|---|---|---|
| `/link code:<code>` | Manage Server | Links this server to the workspace that made the code |
| `/ask question:<text>` | anyone | Answers from the linked workspace's documents, with sources. Each channel keeps its own conversation. |
| `/new` | anyone | Starts a new conversation in this channel |
| `/unlink` | Manage Server | Unlinks the server |

Replies to `/link`, `/new`, `/unlink` and errors are only visible to the person who ran the command. In a direct message with the app, the DM itself is linked.

## Set it up

1. Create an application at the [Discord Developer Portal](https://discord.com/developers/applications). From **General Information**, copy the **Application ID** and **Public Key**. Under **Bot**, reset and copy the **token**; it is only used to register the commands.
2. Put them in `.env`:
   ```
   DISCORD_APPLICATION_ID=...
   DISCORD_PUBLIC_KEY=...
   DISCORD_BOT_TOKEN=...
   ```
   Restart the app: `docker compose -f docker-compose.prod.yml up -d`.
3. Register the commands:
   ```bash
   docker compose -f docker-compose.prod.yml exec app php artisan docchat:discord-register-commands
   ```
   Global commands can take a few minutes to appear. To test in one server right away, add `--guild=<server id>`.
4. In the Developer Portal, set **Interactions Endpoint URL** to `https://<your domain>/discord/interactions` and save. Discord sends a signed PING first, and DocChat answers it; if saving fails, check `DISCORD_PUBLIC_KEY`. Discord needs a public HTTPS address, so for local tests use a [tunnel](../n8n/README.md#testing-webhooks-from-the-internet-free-tunnel) to the app's port.
5. Make sure the queue worker runs: answers to `/ask` come from it.

## Link a server

In DocChat, a workspace owner opens **Workspace > Chat apps** and clicks **Create a link code** under Discord. **Add to Discord** adds the app to a server (scope `applications.commands`). Then someone with Manage Server runs `/link code:<code>` in any channel. Codes work once, for 15 minutes.
