By default your workspace's answers use DocChat's own free AI keys, which every workspace shares. Add your own free key and your answers run on your own quota instead.

## Get a free key

- **Groq**: sign in at [console.groq.com/keys](https://console.groq.com/keys) and create a key. Fast answers on open models.
- **Google Gemini**: sign in at [aistudio.google.com/apikey](https://aistudio.google.com/apikey) and create a key. Used for answers and for search.

Neither needs a card for the free tier.

## Add it to DocChat

1. Open **Workspace > AI keys** (owners only).
2. Paste the key, and optionally a model id. Leave the model empty to use DocChat's default for that provider.
3. Press **Check and save**. DocChat checks the key with a free call that uses no tokens. A key the provider rejects isn't saved.
4. Under **Answers use**, pick your key and press **Save**.

## What a Gemini key does

A Gemini key also turns your documents and questions into embeddings for search. The embedding model stays the same, so nothing needs re-uploading when you add or remove the key. Turn off **Use my Gemini key for document search too** to keep search on DocChat's key.

## Is my key safe?

- It is encrypted before it is stored, and never shown again. The page only shows its last four characters.
- Only workspace owners can see the page or change the key.
- **Remove** deletes it, and answers go back to DocChat's keys.

## If something goes wrong

If the provider stops accepting your key (it was deleted, or the free quota ran out), questions show an error that points to the AI keys page, and the key is marked **Rejected** there. Paste a new key to fix it.

Don't use a ChatGPT, Gemini or Claude subscription login: those subscriptions can't be used by other apps. Free API keys are the allowed way.
