# BIIGLEBot - An LLM using RAG to answer questions about BIIGLE

[![Test status](https://github.com/biigle/module/workflows/Tests/badge.svg)](https://github.com/biigle/module/actions?query=workflow%3ATests)

## Installation

1. Run `composer require biigle/biiglebot:dev-main --ignore-platform-req=ext-ffi`.
2. Add `Biigle\Modules\BIIGLEBot\BIIGLEBotServiceProvider::class` to the `providers` array in `config/app.php`.
3. Run `php artisan vendor:publish --tag=public` to refresh the public assets of the modules. Do this for every update of this module.

## Configuration

Configure the chatbot backend in the BIIGLE `.env`:

- `BIIGLEBOT_LLM_API_URL` (default: `https://chat-ai.academiccloud.de/v1/chat/completions`)
- `BIIGLEBOT_LLM_API_KEY`
- `BIIGLEBOT_LLM_ALGORITHM`
- `BIIGLEBOT_LLM_INFERENCE_SERVICE` (default: `saia-openai-gateway`)
- `BIIGLEBOT_LLM_ARCANA_ID` (optional)
- `BIIGLEBOT_LLM_SYSTEM_PROMPT` (optional)
- `BIIGLEBOT_LLM_ENABLE_TOOLS` (default: `true`)
- `BIIGLEBOT_LLM_TEMPERATURE` (default: `0.2`)
- `BIIGLEBOT_LLM_TOP_P` (default: `1.0`)

After configuration, a new chat button appears in the BIIGLE navbar-right and opens the BIIGLEBot modal.
