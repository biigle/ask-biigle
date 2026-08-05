# AskBiigle - An LLM using RAG to answer questions about BIIGLE

[![Test status](https://github.com/biigle/ask-biigle/workflows/Tests/badge.svg)](https://github.com/biigle/ask-biigle/actions?query=workflow%3ATests)

The RAG was built using the [GWDG RAG-Manager](https://docs.hpc.gwdg.de/services/ai-services/arcana/index.html) using the BIIGLE Manual, the [BIIGLE paper](https://www.frontiersin.org/journals/marine-science/articles/10.3389/fmars.2017.00083/full), the [MAIA paper](https://journals.plos.org/plosone/article?id=10.1371/journal.pone.0207498), and the [LabelBot paper](https://ieeexplore.ieee.org/abstract/document/11496644/), as well as [An Ecologist's Guide to BIIGLE](https://zenodo.org/records/7728927/files/An_Ecologist's_Guide_to_BIIGLE.pdf?download=1)

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

### API Key

To retrieve an API key (`ASK_BIIGLE_LLM_API_KEY`), please refer to the [GWDG SAIA API Request Documentation](https://docs.hpc.gwdg.de/services/ai-services/saia/index.html#api-request).
