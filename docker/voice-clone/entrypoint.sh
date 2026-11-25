#!/bin/sh
# DOCCHAT_CLONE_LANGUAGE is a two-letter code (en, es, fr, de); Pocket TTS
# wants the language's name. One language per server.
set -e

case "${DOCCHAT_CLONE_LANGUAGE:-en}" in
  es) language=spanish ;;
  fr) language=french ;;
  de) language=german ;;
  *) language=english ;;
esac

if [ -z "${HF_TOKEN:-}" ]; then
  echo "HF_TOKEN is not set. Accept the Pocket TTS terms on Hugging Face and set HF_TOKEN to clone voices." >&2
fi

exec pocket-tts serve --host 0.0.0.0 --port 8000 --language "$language" "$@"
