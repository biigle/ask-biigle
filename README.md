# AskBiigle - An LLM using RAG to answer questions about BIIGLE

[![Test status](https://github.com/biigle/ask-biigle/workflows/Tests/badge.svg)](https://github.com/biigle/ask-biigle/actions?query=workflow%3ATests)

## Installation

1. Run `composer require biigle/ask-biigle`.
2. Run `php artisan vendor:publish --tag=public` to refresh the public assets of the modules. Do this for every update of this module.

## Configuration

Configure the chatbot backend in the BIIGLE `.env`:

- `ASK_BIIGLE_LLM_API_URL` (default: `https://chat-ai.academiccloud.de/v1/chat/completions`)
- `ASK_BIIGLE_LLM_API_KEY`
- `ASK_BIIGLE_LLM_ALGORITHM`
- `ASK_BIIGLE_LLM_INFERENCE_SERVICE` (default: `saia-openai-gateway`)
- `ASK_BIIGLE_LLM_ARCANA_ID` (optional)
- `ASK_BIIGLE_LLM_SYSTEM_PROMPT` (optional)
- `ASK_BIIGLE_LLM_ENABLE_TOOLS` (default: `true`)
- `ASK_BIIGLE_LLM_TEMPERATURE` (default: `0.2`)
- `ASK_BIIGLE_LLM_TOP_P` (default: `1.0`)

After configuration, a new chat button appears in the BIIGLE navbar-right and opens the ask BIIGLE modal.
