<template>
    <modal
        v-model="showModal"
        class="ask-biigle-modal"
        size="lg"
        :footer="false"
        append-to-body
        @show="onModalShown"
        >
        <template #header>
            <div class="ask-biigle-modal-header">
                <h4 class="modal-title">Ask BIIGLE</h4>
                <button
                    type="button"
                    class="close ask-biigle-header-close"
                    @click="closeModal"
                    >
                    <span>&times;</span>
                </button>
            </div>
        </template>
        <div class="ask-biigle-chat panel panel-default">
            <div
                ref="messages"
                class="panel-body ask-biigle-messages"
                :class="{'ask-biigle-messages--shadow-top': showTopShadow, 'ask-biigle-messages--shadow-bottom': showBottomShadow}"
                @scroll="handleScroll"
                >
                <p v-if="messages.length === 0" class="text-muted ask-biigle-empty">
                    Ask anything about using BIIGLE.
                </p>
                <div
                    v-for="(message, index) in messages"
                    :key="index"
                    class="ask-biigle-row"
                    :class="`ask-biigle-row--${message.role}`"
                    >
                    <div class="ask-biigle-bubble">
                        <div class="ask-biigle-bubble__role">{{ roleLabel(message.role) }}</div>
                        <div v-if="message.role === 'assistant' && !message.content" class="ask-biigle-typing-indicator">
                            <span class="ask-biigle-typing-dot"></span>
                            <span class="ask-biigle-typing-dot"></span>
                            <span class="ask-biigle-typing-dot"></span>
                        </div>
                        <div v-else class="ask-biigle-bubble__content" v-html="renderMessageHtml(message)"></div>
                        <button
                            v-if="message.role === 'error'"
                            type="button"
                            class="btn btn-default btn-xs ask-biigle-retry-btn"
                            title="Retry"
                            :disabled="pending"
                            @click="retryMessage(index)"
                            >
                            <i class="fa fa-redo"></i> Retry
                        </button>
                        <div v-if="message.role === 'assistant' && message.sources.length > 0" class="ask-biigle-sources">
                            <div class="ask-biigle-source-chips">
                                <button
                                    v-for="(source, sourceIndex) in message.sources"
                                    :key="`${index}-chip-${sourceIndex}`"
                                    type="button"
                                    class="label label-default"
                                    :title="source.title"
                                    @click="openSource(index, source.id)"
                                    >
                                    {{ source.id }}
                                </button>
                            </div>
                            <button
                                type="button"
                                class="btn btn-link btn-xs"
                                @click="toggleSources(index)"
                                >
                                {{ message.sourcesExpanded ? 'Hide sources' : `Show sources (${message.sources.length})` }}
                            </button>
                            <div v-if="message.sourcesExpanded" class="list-group ask-biigle-sources-panel">
                                <div
                                    v-for="(source, sourceIndex) in message.sources"
                                    :key="`${index}-source-${sourceIndex}`"
                                    :id="sourceElementId(index, source.id)"
                                    class="list-group-item"
                                    :class="{'list-group-item-info': message.activeSourceId === source.id}"
                                    >
                                    <div class="ask-biigle-source-item__title">
                                        <strong>{{ source.id }}</strong>
                                        <span>{{ source.title }}</span>
                                        <span v-if="source.score !== null" class="ask-biigle-source-score">
                                            {{ source.score.toFixed(3) }}
                                        </span>
                                    </div>
                                    <div v-if="source.snippet" class="ask-biigle-source-item__snippet">
                                        {{ source.snippet }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="panel-footer ask-biigle-footer">
                <textarea
                    ref="input"
                    v-model="input"
                    class="form-control"
                    rows="3"
                    placeholder="Ask BIIGLE..."
                    :disabled="pending"
                    @keydown="handleKeyDown"
                    ></textarea>
                <div class="ask-biigle-actions">
                    <button class="btn btn-default" :disabled="pending" title="Clear chat" @click="clearChat">
                        Clear conversation
                    </button>
                    <p class="text-muted ask-biigle-disclaimer">
                        Answers are generated by an AI and may be inaccurate. Please verify important information.
                    </p>
                    <button class="btn btn-success" :disabled="pending || !canSend" title="Send message" @click="sendMessage">
                        <i class="fa fa-paper-plane"></i> Send
                    </button>
                </div>
            </div>
        </div>
    </modal>
</template>

<script>
import DOMPurify from 'dompurify';
import {marked, Renderer} from 'marked';
import AskBiigleApi from './api/ask-biigle.js';

const Modal = biigle.$require('uiv.modal');
const MAX_HISTORY_ITEMS = 20;

// Open links in a new tab; rel="noopener noreferrer" prevents reverse tabnabbing.
// This must happen in a hook because "target" is not in DOMPurify's default list of
// allowed attributes, so it would be stripped from the HTML generated by marked.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A' && node.hasAttribute('href')) {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer');
    }
});

// Mirrors cleanAssistantContent() of the ChatController. The backend sends the
// cleaned answer only once the stream is finished, so the markers have to be
// hidden while the answer is still streaming in.
function stripReferences(value) {
    return value
        .replace(/\n?-{3,}\s*References?:[\s\S]*$/i, '')
        .replace(/\s*References?:\s*\[[A-Z]*REF\d+\][\s\S]*$/i, '')
        .replace(/\s*\[(?:RREF|REF)\d+\]/gi, '')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

marked.use({
    gfm: true,
    breaks: true,
    renderer: {
        // Reimplementing the table renderer to add the class would require duplicating
        // marked's header/row/cell assembly logic, so patch the tag after the fact instead.
        table(token) {
            return Renderer.prototype.table.call(this, token)
                .replace('<table>', '<table class="table table-bordered">');
        },
    },
});

const STORAGE_KEY = 'ask-biigle.messages';

function loadMessages() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
            const parsed = JSON.parse(stored);
            if (Array.isArray(parsed)) {
                return parsed;
            }
        }
    } catch {
        // Fallback if localStorage access or JSON parsing fails.
    }

    return [];
}

function saveMessages(messages) {
    try {
        if (messages && messages.length > 0) {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(messages));
        } else {
            localStorage.removeItem(STORAGE_KEY);
        }
    } catch {
        // Fallback if localStorage access fails.
    }
}

export default {
    components: {
        modal: Modal,
    },
    data() {
        return {
            showModal: false,
            input: '',
            pending: false,
            messages: loadMessages(),
            openHandler: null,
            abortController: null,
            showTopShadow: false,
            showBottomShadow: false,
            pinnedMessageIndex: null,
            pinnedScrollTop: null,
        };
    },
    computed: {
        canSend() {
            return this.input.trim().length > 0;
        },
        requestHistory() {
            return this.messages
                .filter((message) => (message.role === 'user' || message.role === 'assistant') && typeof message.content === 'string' && message.content.trim().length > 0)
                .slice(-MAX_HISTORY_ITEMS)
                .map((message) => ({
                    role: message.role,
                    content: message.content,
                }));
        },
    },
    watch: {
        showModal(show) {
            if (!show) {
                // Don't keep a pending answer running in the background.
                this.abortRequest();
            }

            try {
                const Keyboard = biigle.$require('keyboard');
                if (Keyboard && typeof Keyboard.disable === 'function') {
                    if (show) {
                        Keyboard.disable();
                    } else {
                        Keyboard.enable();
                    }
                }
            } catch {
                // Keyboard module not available.
            }
        },
    },
    methods: {
        roleLabel(role) {
            if (role === 'assistant') {
                return 'BIIGLE';
            }
            if (role === 'user') {
                return 'You';
            }

            return 'Error';
        },
        focusInput() {
            this.$nextTick(() => {
                if (this.$refs.input) {
                    this.$refs.input.focus();
                }
            });
        },
        scrollToBottom() {
            this.$nextTick(() => {
                if (this.$refs.messages) {
                    this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight;
                }
                this.updateScrollShadows();
            });
        },
        // Keep the question at the top of the visible area instead of following the
        // growing answer at the bottom. As long as the answer is shorter than the visible
        // area this scrolls to the bottom, so the pin has to be applied on every update
        // until the answer is long enough to actually reach the top.
        scrollPinnedMessageToTop() {
            this.$nextTick(() => {
                const container = this.$refs.messages;
                if (!container || this.pinnedMessageIndex === null) {
                    this.updateScrollShadows();

                    return;
                }

                const row = container.querySelectorAll('.ask-biigle-row')[this.pinnedMessageIndex];
                if (row) {
                    container.scrollTop += row.getBoundingClientRect().top - container.getBoundingClientRect().top;
                    this.pinnedScrollTop = container.scrollTop;
                }

                this.updateScrollShadows();
            });
        },
        handleScroll() {
            const el = this.$refs.messages;
            // Stop pinning as soon as the user scrolls somewhere else on their own.
            if (el && this.pinnedMessageIndex !== null && this.pinnedScrollTop !== null
                && Math.abs(el.scrollTop - this.pinnedScrollTop) > 1) {
                this.unpinMessage();
            }

            this.updateScrollShadows();
        },
        unpinMessage() {
            this.pinnedMessageIndex = null;
            this.pinnedScrollTop = null;
        },
        updateScrollShadows() {
            const el = this.$refs.messages;
            if (!el) {
                return;
            }

            this.showTopShadow = el.scrollTop > 0;
            this.showBottomShadow = el.scrollTop + el.clientHeight < el.scrollHeight - 1;
        },
        addMessage(role, content, sources = [], failedUserMessage = null) {
            this.messages.push({
                role,
                content,
                sources: Array.isArray(sources) ? sources : [],
                sourcesExpanded: false,
                activeSourceId: null,
                failedUserMessage,
            });
            saveMessages(this.messages);
            this.scrollToBottom();
        },
        toggleSources(index) {
            if (!this.messages[index]) {
                return;
            }

            this.messages[index].sourcesExpanded = !this.messages[index].sourcesExpanded;
            this.$nextTick(() => this.updateScrollShadows());
        },
        sourceElementId(messageIndex, sourceId) {
            const safeId = String(sourceId).replace(/[^a-zA-Z0-9_-]/g, '').toLowerCase();

            return `ask-biigle-source-${messageIndex}-${safeId}`;
        },
        openSource(messageIndex, sourceId) {
            const message = this.messages[messageIndex];
            if (!message) {
                return;
            }

            message.sourcesExpanded = true;
            message.activeSourceId = sourceId;
            this.$nextTick(() => {
                const target = document.getElementById(this.sourceElementId(messageIndex, sourceId));
                if (target) {
                    target.scrollIntoView({behavior: 'smooth', block: 'nearest'});
                }
            });
        },
        renderMessageHtml(message) {
            // User content is already sanitized in sendMessage().
            if (message.role === 'user') {
                return `<p>${message.content}</p>`;
            }

            const content = message.role === 'assistant'
                ? stripReferences(message.content)
                : message.content;

            return DOMPurify.sanitize(marked.parse(content));
        },
        clearChat() {
            this.messages = [];
            saveMessages(this.messages);
            this.unpinMessage();
            this.showTopShadow = false;
            this.showBottomShadow = false;
        },
        handleKeyDown(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                this.sendMessage();
            }
        },
        async sendMessage() {
            if (this.pending || !this.canSend) {
                return;
            }

            // Sanitize here and not during rendering so the message that is displayed is
            // identical to the one that is sent to the API (also as chat history).
            const message = DOMPurify.sanitize(this.input.trim(), {ALLOWED_TAGS: []});
            this.input = '';
            this.addMessage('user', message);
            await this.doSend(message);
        },
        async doSend(message) {
            this.pending = true;
            this.abortController = new AbortController();

            this.messages.push({
                role: 'assistant',
                content: '',
                sources: [],
                sourcesExpanded: false,
                activeSourceId: null,
            });
            const assistantMsg = this.messages[this.messages.length - 1];
            // Pin the question so it stays in view while the answer is streamed below it.
            this.pinnedMessageIndex = Math.max(this.messages.length - 2, 0);
            this.pinnedScrollTop = null;
            this.scrollPinnedMessageToTop();

            // Deltas arrive line by line but the whole message is rendered
            // anew on each update, so they are applied once per frame.
            let buffered = '';
            let frame = null;
            const flushBuffer = () => {
                if (frame !== null) {
                    window.cancelAnimationFrame(frame);
                    frame = null;
                }

                if (buffered) {
                    assistantMsg.content += buffered;
                    buffered = '';
                    this.scrollPinnedMessageToTop();
                }
            };

            try {
                await AskBiigleApi.chatStream({
                    message,
                    history: this.requestHistory,
                }, (content) => {
                    buffered += content;
                    if (frame === null) {
                        frame = window.requestAnimationFrame(flushBuffer);
                    }
                }, (doneEvent) => {
                    flushBuffer();
                    if (typeof doneEvent.assistant === 'string') {
                        assistantMsg.content = doneEvent.assistant;
                    }
                    assistantMsg.sources = doneEvent.sources;
                    this.scrollPinnedMessageToTop();
                }, {signal: this.abortController.signal});
            } catch (error) {
                flushBuffer();
                this.unpinMessage();
                const index = this.messages.indexOf(assistantMsg);
                if (index !== -1) {
                    this.messages.splice(index, 1);
                }

                if (error && error.name === 'AbortError') {
                    return;
                }

                const data = error && error.data;
                let errorMessage = 'Request failed.';
                if (data && typeof data.message === 'string' && data.message.length > 0) {
                    errorMessage = data.message;
                } else if (error && typeof error.message === 'string' && error.message.length > 0) {
                    errorMessage = error.message;
                } else if (error && error.status === 504) {
                    errorMessage = 'The AI service timed out. Click Retry to try again.';
                } else if (error && error.status === 500) {
                    errorMessage = 'AskBiigle server error. Click Retry to try again.';
                }
                this.addMessage('error', errorMessage, [], message);
            } finally {
                flushBuffer();
                // Unpin only after the pending scroll of the last update was applied.
                this.$nextTick(() => this.unpinMessage());
                // The messages are stored only here and not on every update of the
                // stream, as writing to localStorage is expensive.
                saveMessages(this.messages);
                this.abortController = null;
                this.pending = false;
                this.focusInput();
            }
        },
        abortRequest() {
            if (this.abortController) {
                this.abortController.abort();
            }
        },
        retryMessage(errorIndex) {
            const errorMsg = this.messages[errorIndex];
            if (!errorMsg || errorMsg.role !== 'error' || !errorMsg.failedUserMessage) {
                return;
            }

            const message = errorMsg.failedUserMessage;
            this.messages.splice(errorIndex, 1);
            this.doSend(message);
        },
        openModal() {
            this.showModal = true;
        },
        closeModal() {
            this.showModal = false;
        },
        onModalShown() {
            this.focusInput();
            this.scrollToBottom();
            this.updateScrollShadows();
        },
    },
    mounted() {
        this.openHandler = () => this.openModal();
        window.addEventListener('ask-biigle:open', this.openHandler);
    },
    beforeUnmount() {
        this.abortRequest();
        if (this.openHandler) {
            window.removeEventListener('ask-biigle:open', this.openHandler);
        }
    },
};
</script>

<style lang="scss">
@use "bootstrap/variables" as *;

// The dialog fills the available space instead of using the fixed width of .modal-lg
// and the fixed height of the message list, so the chat grows with the screen.
.ask-biigle-modal {
    --ask-biigle-modal-margin: 10px;

    .modal-dialog {
        margin: var(--ask-biigle-modal-margin) auto;
        // Keep the lines readable instead of stretching them across a wide screen and
        // always leave the margin free on the left and right.
        max-width: calc(min(1200px, 100% - 2 * var(--ask-biigle-modal-margin)));
        width: auto;
    }

    .modal-content {
        display: flex;
        flex-direction: column;
        height: calc(100vh - 2 * var(--ask-biigle-modal-margin));
    }

    // min-height allows the flex items to shrink below their content height, which is
    // required for the message list to scroll instead of overflowing the dialog.
    .modal-body {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        min-height: 0;
    }
}

@media (min-width: $screen-sm-min) {

    .ask-biigle-modal {
        --ask-biigle-modal-margin: 30px;
    }
}

.ask-biigle-chat {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    margin-bottom: 0;
    min-height: 0;
}

.ask-biigle-messages {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-height: 0;
    // Scroll anchoring would adjust scrollTop while the streamed answer grows, which
    // fights the pin that keeps the top of the answer in view.
    overflow-anchor: none;
    overflow-y: auto;
    padding: 12px;
}

.ask-biigle-messages--shadow-top {
    box-shadow: inset 0 8px 8px -8px #000;
}

.ask-biigle-messages--shadow-bottom {
    box-shadow: inset 0 -8px 8px -8px #000;
}

.ask-biigle-messages--shadow-top.ask-biigle-messages--shadow-bottom {
    box-shadow: inset 0 8px 8px -8px #000, inset 0 -8px 8px -8px #000;
}

.ask-biigle-empty {
    color: $brand-info;
    margin: auto;
    text-align: center;
}

.ask-biigle-row {
    display: flex;
    margin-bottom: 10px;
}

.ask-biigle-row--assistant, .ask-biigle-row--error {
    justify-content: flex-start;
}

.ask-biigle-row--user {
    justify-content: flex-end;
}

.ask-biigle-bubble {
    border: 1px solid $panel-default-border;
    border-radius: $border-radius-base;
    max-width: 85%;
    padding: 10px 12px;
    position: relative;
}

.ask-biigle-row--assistant .ask-biigle-bubble {
    border-color: $panel-info-border;
}

.ask-biigle-row--error .ask-biigle-bubble {
    border-color: $panel-danger-border;
}

.ask-biigle-retry-btn {
    margin-top: 6px;
}

.ask-biigle-bubble__role {
    font-weight: 700;
    margin-bottom: 4px;
}

.ask-biigle-row--assistant .ask-biigle-bubble__role {
    color: $brand-info;
}

.ask-biigle-bubble__content {
    word-break: break-word;
}

// User messages are not rendered as Markdown, so their newlines must be preserved here.
.ask-biigle-row--user .ask-biigle-bubble__content {
    white-space: pre-wrap;
}

.ask-biigle-sources {
    border-top: 1px solid rgba(255, 255, 255, 0.12);
    margin-top: 8px;
    padding-top: 8px;
}

.ask-biigle-source-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 4px;
}

.ask-biigle-sources-panel {
    margin-top: 6px;
}

.ask-biigle-source-item__title {
    align-items: baseline;
    display: flex;
    gap: 6px;
}

.ask-biigle-source-score {
    font-size: 11px;
    margin-left: auto;
    opacity: 0.7;
}

.ask-biigle-source-item__snippet {
    font-size: 12px;
    margin-top: 3px;
    opacity: 0.85;
}

.ask-biigle-footer {
    background: transparent;
    border-top: 1px solid $panel-default-border;
}

.ask-biigle-actions {
    align-items: center;
    display: flex;
    gap: 10px;
    margin-top: 10px;
}

// Keep the buttons at their natural width so only the disclaimer between them wraps.
.ask-biigle-actions .btn {
    flex: none;
}

.ask-biigle-disclaimer {
    font-size: 11px;
    margin: 0;
    // min-width allows the text to wrap into the space that is left between the buttons
    // instead of pushing them apart on a narrow screen.
    min-width: 0;
    text-align: center;
    width: 100%;
}

#biiglebot-open-button .btn {
    min-width: 44px;
}

.ask-biigle-typing-indicator {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 2px;
}

.ask-biigle-typing-dot {
    background: $brand-info;
    border-radius: 50%;
    display: inline-block;
    height: 8px;
    opacity: 0.4;
    width: 8px;
    animation: biiglebot-bounce 1.4s ease-in-out infinite both;
}

.ask-biigle-typing-dot:nth-child(1) {
    animation-delay: 0s;
}

.ask-biigle-typing-dot:nth-child(2) {
    animation-delay: 0.2s;
}

.ask-biigle-typing-dot:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes biiglebot-bounce {

    0%,
    80%,
    100% {
        transform: scale(0.6);
        opacity: 0.4;
    }

    40% {
        transform: scale(1);
        opacity: 1;
    }
}

.ask-biigle-modal-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
    width: 100%;
}

.ask-biigle-modal-header .modal-title {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
}

.ask-biigle-header-close {
    font-size: 22px;
}
</style>
