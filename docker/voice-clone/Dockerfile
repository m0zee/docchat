# Voice clones for DocChat: Kyutai Pocket TTS (code MIT, weights CC-BY-4.0),
# a 100M-parameter model that runs on a CPU. Optional; see docs/calls.md.
#
#   docker compose --profile clone up -d voice-clone
#
# The voice-cloning weights are gated on Hugging Face: sign in with a free
# account, accept the model's terms (they forbid cloning a voice without the
# speaker's consent) and give the service HF_TOKEN.
FROM python:3.12-slim

ENV PIP_NO_CACHE_DIR=1 \
    PYTHONUNBUFFERED=1 \
    HF_HOME=/models

# CPU-only PyTorch keeps the image small (no CUDA).
RUN pip install --extra-index-url https://download.pytorch.org/whl/cpu "pocket-tts==4.0.1"

COPY entrypoint.sh /usr/local/bin/voice-clone
RUN chmod +x /usr/local/bin/voice-clone

VOLUME /models
EXPOSE 8000

ENTRYPOINT ["voice-clone"]
