# askBIIGLE - An LLM using RAG to answer questions about BIIGLE

[![Test status](https://github.com/biigle/askbiigle/workflows/Tests/badge.svg)](https://github.com/biigle/module/actions?query=workflow%3ATests)

## Installation

1. Run `composer require biigle/askbiigle:dev-main --ignore-platform-req=ext-ffi`.
2. Run `php artisan vendor:publish --tag=public` to refresh the public assets of the modules. Do this for every update of this module.

## Configuration

Configure the chatbot backend in the BIIGLE `.env`:

- `ASKBIIGLE_LLM_API_URL` (default: `https://chat-ai.academiccloud.de/v1/chat/completions`)
- `ASKBIIGLE_LLM_API_KEY`
- `ASKBIIGLE_LLM_ALGORITHM`
- `ASKBIIGLE_LLM_INFERENCE_SERVICE` (default: `saia-openai-gateway`)
- `ASKBIIGLE_LLM_ARCANA_ID` (optional)
- `ASKBIIGLE_LLM_SYSTEM_PROMPT` (optional)
- `ASKBIIGLE_LLM_ENABLE_TOOLS` (default: `true`)
- `ASKBIIGLE_LLM_TEMPERATURE` (default: `0.2`)
- `ASKBIIGLE_LLM_TOP_P` (default: `1.0`)

After configuration, a new chat button appears in the BIIGLE navbar-right and opens the askBIIGLE modal.
