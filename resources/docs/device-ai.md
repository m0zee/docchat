DocChat can let the asker's own browser write the answer. DocChat still searches your documents and picks the sources; only the writing happens on the device. These answers cost no tokens, and the question never reaches an AI company.

## Which browsers

- **Chrome's built-in AI** (Gemini Nano): Chrome 148 or newer on Windows 10 or 11, macOS 13 or newer, Linux, or a Chromebook Plus. It needs about 22 GB of free disk space, and a graphics card with more than 4 GB of memory or 16 GB of RAM. Phones aren't supported yet. Chrome downloads the model once, the first time it is used.
- **WebLLM**: any browser with WebGPU, such as recent Chrome or Edge on a laptop. It downloads a small open model (about 1 GB) once and keeps it in the browser.

Firefox and Safari don't have either yet. Where nothing is supported, DocChat simply answers from the server.

## In the chat page

When your browser supports one of them, an **AI** menu appears at the top of the chat. Choose **This device: Chrome built-in AI** or **This device: WebLLM**. Your choice is remembered in this browser. The first answer can take a while as the model downloads; later answers are faster.

On-device models are small, so answers are shorter and can be less accurate than the server's. DocChat sends them three sources instead of five to fit their memory. Switch back to **DocChat server** any time.

## On your website

In **Workspace > Widget**, turn on **Answer on visitors' devices when they can**. Visitors whose Chrome already has the built-in model get answers from their own browser. Nobody is asked to download a model, and if the model fails, the widget asks the server instead.

## How DocChat keeps it safe

Text written in a browser can't be trusted like the server's own answers, so:

- Each answer needs a one-time token that DocChat issues with the sources, valid for 10 minutes and only for that conversation.
- Citations are still matched on the server, against the passages DocChat found.
- On-device answers are shown only in their own conversation, and never go into the answer cache that other people can be served from.

## Usage

Answers written on a device count as questions on the **Usage** page, with no tokens, and the page shows how many were written on devices.
