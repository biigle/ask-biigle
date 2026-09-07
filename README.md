# AskBiigle - An LLM using RAG to answer questions about BIIGLE

[![Test status](https://github.com/biigle/ask-biigle/workflows/Tests/badge.svg)](https://github.com/biigle/ask-biigle/actions?query=workflow%3ATests)

The RAG was built using the [GWDG RAG-Manager](https://docs.hpc.gwdg.de/services/ai-services/arcana/index.html) using the BIIGLE Manual, the [BIIGLE paper](https://www.frontiersin.org/journals/marine-science/articles/10.3389/fmars.2017.00083/full), the [MAIA paper](https://journals.plos.org/plosone/article?id=10.1371/journal.pone.0207498), and the [LabelBot paper](https://ieeexplore.ieee.org/abstract/document/11496644/), as well as [An Ecologist's Guide to BIIGLE](https://zenodo.org/records/7728927/files/An_Ecologist's_Guide_to_BIIGLE.pdf?download=1)

## Installation

1. Run `composer require biigle/ask-biigle`.
2. Run `php artisan vendor:publish --tag=public` to refresh the public assets of the modules. Do this for every update of this module.

## Configuration

Configure the chatbot backend in the BIIGLE `.env`:

- `ASK_BIIGLE_LLM_API_URL` (default: `https://chat-ai.academiccloud.de/v1/chat/completions`)
- `ASK_BIIGLE_LLM_API_KEY` (required, see [API Key](#api-key))
- `ASK_BIIGLE_LLM_ALGORITHM` (default: `qwen3-30b-a3b-instruct-2507`)
- `ASK_BIIGLE_LLM_INFERENCE_SERVICE` (default: `saia-openai-gateway`)
- `ASK_BIIGLE_LLM_ARCANA_ID` (default: `d.langenkaemper/BiigleManual`, see [Arcana](#arcana))
- `ASK_BIIGLE_LLM_SYSTEM_PROMPT` (default: the prompt at the top of `src/config/ask-biigle.php`)
- `ASK_BIIGLE_LLM_ENABLE_TOOLS` (default: `true`)
- `ASK_BIIGLE_LLM_TEMPERATURE` (default: `0.0`)
- `ASK_BIIGLE_LLM_TOP_P` (default: `0.05`)
- `ASK_BIIGLE_LLM_TIMEOUT` (default: `29`, see [Timeout](#timeout))

Only the API key has to be configured. All other variables have a default that
works with the Chat AI service of the GWDG.

After configuration, a new chat button appears in the BIIGLE navbar-right and opens the ask BIIGLE modal.

Each answer has a button to report it as incorrect. It opens the mail client of
the user with the question, the answer, its sources and an MD5 checksum of that
text. The report is sent to the `ADMIN_EMAIL` of biigle/core, so the button is
hidden if that variable is not set.

The chat also has a "Contact a human" button, which opens an empty mail to the
same address. It is hidden along with the report button if `ADMIN_EMAIL` is not
set.

### API Key

To retrieve an API key (`ASK_BIIGLE_LLM_API_KEY`), please refer to the [GWDG SAIA API Request Documentation](https://docs.hpc.gwdg.de/services/ai-services/saia/index.html#api-request).

### Arcana

The arcana is the RAG index that the answers are based on. If
`ASK_BIIGLE_LLM_ARCANA_ID` is not set, the module falls back to
`d.langenkaemper/BiigleManual`. This arcana is available to all Chat AI users,
so the chatbot works with the BIIGLE documentation out of the box and you only
need to set this variable if you want to use an arcana of your own.

### Timeout

`ASK_BIIGLE_LLM_TIMEOUT` is the timeout in seconds for the request to the LLM
API. The response is streamed with the PHP stream handler, so the timeout
applies to a single read and not to the whole transfer. This allows long
answers to be streamed completely, as long as the service keeps sending.

A failed request is retried, with three attempts in total, so a value that is
close to the timeout of a reverse proxy in front of BIIGLE can still result in
a gateway timeout for the browser.

## Metrics

The chat emits the `ask-biigle.asked_question` and `ask-biigle.reported_answer`
events whenever a question is sent or an answer is reported as incorrect. The
[metrics](https://github.com/biigle/metrics) module counts these events and
shows the counts in its admin area. Nothing is counted if that module is not
installed, and no content of a question or answer is recorded either way.

## Arcana Update
To update the arcana RAG system, execute `arcanaUpdate/rebuildRAG.py`. This script scrapes [https://biigle.de/manual](https://biigle.de/manual) and updates any modified files.

The scraper stores the manual pages with file names that are derived from their URL
(e.g. `manual_tutorials_notifications.html`) and writes the mapping between the two to
`src/resources/manual-url-map.json`. Commit that file together with an arcana update, as
the chat backend uses it to turn the file names into manual URLs: the file names are all
the LLM knows about a page, so it would otherwise link to
`manual_tutorials_notifications.html.md` instead of
<https://biigle.de/manual/tutorials/notifications>. Links of an answer are repaired and
the sources of an answer are shown as links whenever a file name can be resolved this
way.
