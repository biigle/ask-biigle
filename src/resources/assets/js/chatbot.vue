<template>
    <modal
        v-model="showModal"
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
                @scroll="updateScrollShadows"
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
                        <div class="ask-biigle-bubble__content" v-html="renderMessageHtml(message)"></div>
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
                <div v-if="pending" class="ask-biigle-row ask-biigle-row--assistant">
                    <div class="ask-biigle-bubble">
                        <div class="ask-biigle-bubble__role">BIIGLE</div>
                        <div class="ask-biigle-typing-indicator">
                            <span class="ask-biigle-typing-dot"></span>
                            <span class="ask-biigle-typing-dot"></span>
                            <span class="ask-biigle-typing-dot"></span>
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
                    <button class="btn btn-success pull-right" :disabled="pending || !canSend" title="Send message" @click="sendMessage">
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
            showTopShadow: false,
            showBottomShadow: false,
        };
    },
    computed: {
        canSend() {
            return this.input.trim().length > 0;
        },
        requestHistory() {
            return this.messages
                .filter((message) => message.role === 'user' || message.role === 'assistant')
                .slice(-MAX_HISTORY_ITEMS)
                .map((message) => ({
                    role: message.role,
                    content: message.content,
                }));
        },
    },
    watch: {
        messages: {
            deep: true,
            handler(newMessages) {
                saveMessages(newMessages);
            },
        },
        showModal(show) {
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

            return DOMPurify.sanitize(marked.parse(message.content));
        },
        clearChat() {
            this.messages = [];
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

            try {
                const response = await AskBiigleApi.save({}, {
                    message,
                    history: this.requestHistory,
                });

                const data = response.body;
                this.addMessage(
                    'assistant',
                    data && data.assistant ? data.assistant : '',
                    data && Array.isArray(data.sources) ? data.sources : []
                );
            } catch (response) {
                const data = response && response.body;
                let errorMessage = 'Request failed.';
                if (data && typeof data.message === 'string' && data.message.length > 0) {
                    errorMessage = data.message;
                } else if (response && response.status === 504) {
                    errorMessage = 'The AI service timed out. Click Retry to try again.';
                } else if (response && response.status === 500) {
                    errorMessage = 'AskBiigle server error. Click Retry to try again.';
                }
                this.addMessage('error', errorMessage, [], message);
            } finally {
                this.pending = false;
                this.focusInput();
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
        if (this.openHandler) {
            window.removeEventListener('ask-biigle:open', this.openHandler);
        }
    },
};
</script>

<style lang="scss">
@use "bootstrap/variables" as *;

.ask-biigle-chat {
    margin-bottom: 0;
}

.ask-biigle-messages {
    display: flex;
    flex-direction: column;
    height: 340px;
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
    margin-top: 10px;
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
