/*
 * In-browser AI for the chat page. DocChat still searches the documents on
 * the server; this writes the answer on the user's own device with:
 *   1. Chrome's built-in AI (Prompt API, Gemini Nano; Chrome 148+ on
 *      desktop), or
 *   2. WebLLM (a small open model in WebGPU), loaded from a CDN only when
 *      the user picks it.
 * Anything unsupported falls back to normal server answers.
 */

const CHROME_LANGUAGES = ['en', 'es', 'ja', 'de', 'fr'];

function chromeOptions() {
    const lang = (navigator.language || 'en').slice(0, 2).toLowerCase();
    const languages = lang !== 'en' && CHROME_LANGUAGES.includes(lang) ? ['en', lang] : ['en'];

    return {
        expectedInputs: [{ type: 'text', languages }],
        expectedOutputs: [{ type: 'text', languages }],
    };
}

/**
 * What this browser can do. chrome is the Prompt API availability:
 * "available", "downloadable", "downloading" or "unavailable".
 */
export async function detect() {
    const result = { chrome: 'unavailable', webgpu: false };

    if (typeof window.LanguageModel !== 'undefined') {
        try {
            result.chrome = await window.LanguageModel.availability(chromeOptions());
        } catch {
            result.chrome = 'unavailable';
        }
    }

    if (navigator.gpu) {
        try {
            result.webgpu = Boolean(await navigator.gpu.requestAdapter());
        } catch {
            result.webgpu = false;
        }
    }

    return result;
}

/**
 * Split OpenAI-style messages into the Prompt API's initial prompts and
 * the final question.
 */
function splitMessages(messages) {
    const last = messages[messages.length - 1];

    return { initialPrompts: messages.slice(0, -1), question: last ? last.content : '' };
}

async function generateChrome(messages, onDelta, onProgress, signal) {
    const { initialPrompts, question } = splitMessages(messages);
    const session = await window.LanguageModel.create({
        ...chromeOptions(),
        initialPrompts,
        signal,
        monitor(m) {
            m.addEventListener('downloadprogress', (e) => onProgress?.(e.loaded, 'Downloading Chrome’s AI model'));
        },
    });

    try {
        let text = '';

        for await (const chunk of session.promptStreaming(question, { signal })) {
            text += chunk;
            onDelta(chunk);
        }

        return text;
    } finally {
        session.destroy();
    }
}

let webllmEngine = null;

function loadWebllm(config, onProgress) {
    if (!webllmEngine) {
        webllmEngine = import(/* @vite-ignore */ config.webllmUrl)
            .then((webllm) =>
                webllm.CreateMLCEngine(config.webllmModel, {
                    initProgressCallback: (report) => onProgress?.(report.progress ?? 0, report.text || 'Loading the model'),
                }),
            )
            .catch((error) => {
                webllmEngine = null;
                throw error;
            });
    }

    return webllmEngine;
}

async function generateWebllm(messages, config, onDelta, onProgress) {
    const engine = await loadWebllm(config, onProgress);
    const chunks = await engine.chat.completions.create({
        messages,
        stream: true,
        temperature: 0.2,
        max_tokens: config.maxOutputTokens || 800,
    });

    let text = '';

    for await (const chunk of chunks) {
        const delta = chunk.choices?.[0]?.delta?.content || '';

        if (delta) {
            text += delta;
            onDelta(delta);
        }
    }

    return text;
}

/**
 * Write the answer on the device. engine is "chrome" or "webllm".
 */
export function generate(engine, messages, config, onDelta, onProgress, signal) {
    return engine === 'webllm'
        ? generateWebllm(messages, config, onDelta, onProgress)
        : generateChrome(messages, onDelta, onProgress, signal);
}

export function errorText(error) {
    const name = error && error.name;

    if (name === 'QuotaExceededError') return 'The question and sources are too long for the on-device model.';
    if (name === 'NotAllowedError') return 'This page is not allowed to use the browser’s built-in AI.';
    if (name === 'NotSupportedError') return 'The on-device model does not support this language.';

    return (error && error.message ? String(error.message) : 'The on-device model failed.').slice(0, 200);
}

/**
 * Alpine component for the chat page: x-data="deviceAi({...})".
 */
function deviceAi(config) {
    const STORE = 'docchat:device-ai';

    return {
        // "server", "chrome" or "webllm"
        engine: 'server',
        chrome: 'unavailable',
        webgpu: false,
        checked: false,
        busy: false,
        progress: null,
        progressText: '',

        async init() {
            try {
                this.engine = localStorage.getItem(STORE) || 'server';
            } catch {
                this.engine = 'server';
            }

            const found = await detect();
            this.chrome = found.chrome;
            this.webgpu = found.webgpu;
            this.checked = true;

            if (!this.supports(this.engine)) this.engine = 'server';
        },

        supports(engine) {
            if (engine === 'chrome') return this.chrome !== 'unavailable';
            if (engine === 'webllm') return this.webgpu;

            return true;
        },

        choose(engine) {
            this.engine = this.supports(engine) ? engine : 'server';

            try {
                localStorage.setItem(STORE, this.engine);
            } catch {
                // storage blocked: keep the choice for this page only
            }
        },

        get onDevice() {
            return this.engine !== 'server';
        },

        async ask() {
            if (!this.onDevice) return this.$wire.send();
            if (this.busy) return;

            this.busy = true;

            try {
                const prepared = await this.$wire.sendOnDevice();

                if (!prepared || prepared.status !== 'generate') return;

                await this.$nextTick();
                const target = () => this.$root.querySelector('[data-device-answer]');
                let text = '';
                let error = null;

                try {
                    text = await generate(
                        this.engine,
                        prepared.messages,
                        { ...config, maxOutputTokens: prepared.max_output_tokens },
                        (delta) => {
                            const node = target();
                            if (node) {
                                if (node.dataset.started !== '1') {
                                    node.textContent = '';
                                    node.dataset.started = '1';
                                }
                                node.textContent += delta;
                            }
                            this.progress = null;
                        },
                        (loaded, label) => {
                            this.progress = Math.round((loaded || 0) * 100);
                            this.progressText = label;
                        },
                    );
                } catch (e) {
                    error = errorText(e);
                }

                this.progress = null;
                await this.$wire.completeOnDevice(prepared.token, text || '', error);
            } finally {
                this.busy = false;
            }
        },
    };
}

window.DocChatDeviceAi = { detect, generate, errorText };

document.addEventListener('alpine:init', () => {
    window.Alpine.data('deviceAi', deviceAi);
});
