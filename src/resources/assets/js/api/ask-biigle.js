import { Resource } from '../import.js';

const api = Resource('ask-biigle/chat');

const metaContent = function (name) {
    const element = document.querySelector(`meta[name="${name}"]`);

    return element ? element.getAttribute('content') : null;
};

const chatUrl = function () {
    const httpRoot = (metaContent('http-root') || '').replace(/\/$/, '');

    return `${httpRoot}/ask-biigle/chat`;
};

const sessionExpiredError = function () {
    return new Error('Your session has expired. Please refresh the page and log in again.');
};

/**
 * Parse a chunk of the event stream and dispatch its events.
 *
 * Returns the "done" event if the chunk contained one.
 */
const processChunk = function (chunk, onDelta) {
    let done = null;

    for (const rawLine of chunk.split(/\r?\n/)) {
        const line = rawLine.trim();
        if (!line.startsWith('data:')) {
            continue;
        }

        const data = line.slice(5).trim();
        if (!data || data === '[DONE]') {
            continue;
        }

        let event;
        try {
            event = JSON.parse(data);
        } catch {
            // Ignore frames that are not valid JSON.
            continue;
        }

        if (event.type === 'error') {
            const error = new Error(event.message || 'The AI service encountered an issue.');
            error.data = event;
            throw error;
        }

        if (event.type === 'delta') {
            if (event.content && typeof onDelta === 'function') {
                onDelta(event.content);
            }
        } else if (event.type === 'done') {
            done = event;
        }
    }

    return done;
};

/**
 * Send a chat message and receive the answer token by token.
 *
 * onDelta is called with each new piece of text. onDone is called once with the
 * cleaned answer and the retrieval sources.
 *
 * Pass an AbortSignal as options.signal to cancel a running request.
 */
api.chatStream = async function (data, onDelta, onDone, options = {}) {
    const response = await fetch(chatUrl(), {
        method: 'POST',
        credentials: 'same-origin',
        signal: options.signal,
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'text/event-stream, application/json',
            'X-CSRF-TOKEN': metaContent('csrf-token') || '',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({...data, stream: true}),
    });

    const contentType = response.headers.get('content-type') || '';
    if (response.redirected || contentType.includes('text/html')) {
        throw sessionExpiredError();
    }

    if (!response.ok) {
        let errorData = null;
        try {
            errorData = await response.json();
        } catch {
            // Response was not JSON.
        }

        const error = new Error((errorData && errorData.message) || `Request failed with status ${response.status}`);
        error.status = response.status;
        error.data = errorData;
        throw error;
    }

    // The endpoint falls back to a plain JSON response if streaming is not
    // available.
    if (!contentType.includes('text/event-stream')) {
        const body = await response.json();
        if (typeof onDone === 'function') {
            onDone({
                assistant: body.assistant,
                sources: Array.isArray(body.sources) ? body.sources : [],
            });
        }

        return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let buffer = '';
    let doneEvent = null;

    try {
        for (;;) {
            const {done, value} = await reader.read();
            if (done) {
                break;
            }

            buffer += decoder.decode(value, {stream: true});
            const lastNewline = buffer.lastIndexOf('\n');
            if (lastNewline !== -1) {
                const chunk = buffer.slice(0, lastNewline + 1);
                buffer = buffer.slice(lastNewline + 1);
                doneEvent = processChunk(chunk, onDelta) || doneEvent;
            }
        }

        if (buffer.trim()) {
            doneEvent = processChunk(buffer, onDelta) || doneEvent;
        }
    } finally {
        reader.cancel().catch(() => {
            // The stream was already closed.
        });
    }

    if (!doneEvent) {
        throw new Error('The AI service did not send a complete response. Please click Retry to try again.');
    }

    if (typeof onDone === 'function') {
        onDone({
            assistant: doneEvent.assistant,
            sources: Array.isArray(doneEvent.sources) ? doneEvent.sources : [],
        });
    }
};

export default api;
