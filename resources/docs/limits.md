## Limits on this server

| Limit | Default |
|---|---|
| File size | 10 MB |
| File types | PDF with text, TXT, Markdown |
| Questions per person | 20 a minute |
| API requests per key | 60 a minute |
| Widget questions per visitor | 10 a minute |
| Question length | 2,000 characters |
| Call length | 10 minutes, 30 answers |
| Speech recognition per workspace | 300 recordings a day |
| Phone line (Twilio trial) | 75 minutes over 30 days, only to numbers you verified |

The app's AI keys are on free tiers that all workspaces share, so answers can be briefly busy at peak times. Your own [AI key](/docs/ai-keys) or [answers in the browser](/docs/device-ai) avoid that.

## Your data

- Documents and their passages are stored on this server, private to your workspace. Every query is limited to your workspace.
- To answer, the matching passages and your question are sent to the AI provider in use: Groq or Google for server answers, or nobody for answers written in the browser.
- Usage logs keep counts, tokens and times, never question or document text.
- Call recordings are only used to write down what was said (with Groq's Whisper) and are not kept. The written transcript is kept with the call.
- API keys are stored only as hashes. Your own AI keys and webhook secrets are encrypted.

The hosted version is a portfolio project running on free tiers for testing. Please upload test documents only. For anything private, [host DocChat yourself](/docs/self-hosting).
