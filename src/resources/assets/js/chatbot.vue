<template>
    <div :class="modalWrapperClasses">
        <modal v-model="showModal" size="lg" :footer="false" append-to-body @shown="onModalShown">
            <template #header>
                <div class="ask-biigle-modal-header">
                    <h4 class="modal-title">Ask BIIGLE</h4>
                    <div class="ask-biigle-header-actions">
                        <button
                            type="button"
                            class="ask-biigle-header-btn"
                            :title="maximized ? 'Restore' : 'Maximize'"
                            @click="toggleMaximize"
                            >
                            <i class="fa" :class="maximized ? 'fa-compress' : 'fa-expand'"></i>
                        </button>
                        <button
                            type="button"
                            class="ask-biigle-header-btn"
                            :title="fullscreen ? 'Exit fullscreen' : 'Fullscreen'"
                            @click="toggleFullscreen"
                            >
                            <i class="fa" :class="fullscreen ? 'fa-compress-arrows-alt' : 'fa-expand-arrows-alt'"></i>
                        </button>
                        <button
                            type="button"
                            class="close ask-biigle-header-close"
                            @click="closeModal"
                            >
                            <span>&times;</span>
                        </button>
                    </div>
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
    </div>
</template>

<script>
import { marked } from 'marked';
import AskBiigleApi from './api/ask-biigle.js';

const Modal = biigle.$require('uiv.modal');
const MAX_HISTORY_ITEMS = 20;

marked.use({
    gfm: true,
    breaks: true,
    renderer: {
        link({ href, title, text }) {
            const titleAttr = title ? ` title="${title}"` : '';
            return `<a href="${href}"${titleAttr} target="_blank" rel="noopener noreferrer">${text}</a>`;
        },
    },
});

function escapeHtml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderMarkdown(value) {
    if (!value) {
        return '';
    }
    return marked.parse(value);
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
            messages: [],
            maximized: false,
            fullscreen: false,
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
        modalWrapperClasses() {
            return {
                'ask-biigle-maximized': this.maximized,
                'ask-biigle-fullscreen': this.fullscreen,
            };
        },
    },
    watch: {
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
            if (message.role === 'user') {
                return `<p>${escapeHtml(message.content)}</p>`;
            }

            return renderMarkdown(message.content);
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

            const message = this.input.trim();
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
            if (this.fullscreen) {
                this.exitFullscreen();
            }
            this.showModal = false;
        },
        toggleMaximize() {
            this.maximized = !this.maximized;
        },
        toggleFullscreen() {
            if (this.fullscreen) {
                this.exitFullscreen();
            } else {
                this.enterFullscreen();
            }
        },
        enterFullscreen() {
            const wrapper = this.$el;
            const modalEl = wrapper.querySelector('.modal');
            if (!modalEl) {
                return;
            }

            const request = modalEl.requestFullscreen
                || modalEl.webkitRequestFullscreen
                || modalEl.msRequestFullscreen;

            if (request) {
                request.call(modalEl).then(() => {
                    this.fullscreen = true;
                }).catch(() => {
                    // Fullscreen not supported or denied.
                });
            }
        },
        exitFullscreen() {
            if (document.fullscreenElement || document.webkitFullscreenElement) {
                const exit = document.exitFullscreen
                    || document.webkitExitFullscreen
                    || document.msExitFullscreen;

                if (exit) {
                    exit.call(document);
                }
            }
            this.fullscreen = false;
        },
        handleFullscreenChange() {
            if (!document.fullscreenElement && !document.webkitFullscreenElement) {
                this.fullscreen = false;
            }
        },
        onModalShown() {
            this.focusInput();
            this.updateScrollShadows();
        },
    },
    mounted() {
        this.openHandler = () => this.openModal();
        window.addEventListener('ask-biigle:open', this.openHandler);
        document.addEventListener('fullscreenchange', this.handleFullscreenChange);
        document.addEventListener('webkitfullscreenchange', this.handleFullscreenChange);
    },
    beforeUnmount() {
        if (this.openHandler) {
            window.removeEventListener('ask-biigle:open', this.openHandler);
        }
        document.removeEventListener('fullscreenchange', this.handleFullscreenChange);
        document.removeEventListener('webkitfullscreenchange', this.handleFullscreenChange);
    },
};
</script>

<style lang="scss">
.ask-biigle-chat {
    margin-bottom: 0;
}

.ask-biigle-messages {
    background: #1f2731;
    border: 1px solid #34414f;
    border-radius: 4px;
    height: 340px;
    overflow-y: auto;
    padding: 12px;
}

.ask-biigle-empty {
    color: #c3cfdb;
    margin: 0;
    text-align: center;
}

.ask-biigle-row {
    display: flex;
    margin-bottom: 10px;
}

.ask-biigle-row--assistant {
    justify-content: flex-start;
}

.ask-biigle-row--user {
    justify-content: flex-end;
}

.ask-biigle-row--error {
    justify-content: center;
}

.ask-biigle-bubble {
    background: #2b3542;
    border: 1px solid #415062;
    border-radius: 14px;
    max-width: 85%;
    padding: 10px 12px;
    position: relative;
    color: #dfe8f2;
}

.ask-biigle-row--assistant .ask-biigle-bubble {
    background: #233746;
    border-color: #35566d;
    box-shadow: inset 0 0 0 1px #2d4a5e;
}

.ask-biigle-row--user .ask-biigle-bubble {
    background: #273b31;
    border-color: #3f614f;
    box-shadow: inset 0 0 0 1px #335041;
}

.ask-biigle-row--error .ask-biigle-bubble {
    border-color: #ebccd1;
    box-shadow: inset 0 0 0 1px #d9534f;
}

.ask-biigle-retry-btn {
    background: rgba(217, 83, 79, 0.15);
    border: 1px solid rgba(217, 83, 79, 0.4);
    border-radius: 999px;
    color: #e8a09e;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
    margin-top: 6px;
    outline: none;
    padding: 4px 10px;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;

    &:hover,
    &:focus {
        background: rgba(217, 83, 79, 0.28);
        border-color: rgba(217, 83, 79, 0.6);
        color: #f2c4c3;
    }

    &:hover .fa-refresh {
        animation: ask-biigle-spin 0.5s ease-in-out;
    }

    &:disabled {
        cursor: not-allowed;
        opacity: 0.5;
    }
}

@keyframes ask-biigle-spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

.ask-biigle-bubble__role {
    font-weight: 700;
    margin-bottom: 4px;
}

.ask-biigle-row--assistant .ask-biigle-bubble__role {
    color: #7fc4ea;
}

.ask-biigle-row--user .ask-biigle-bubble__role {
    color: #8bdeb0;
}

.ask-biigle-row--error .ask-biigle-bubble__role {
    color: #a94442;
}

.ask-biigle-bubble__content {
    color: #dfe8f2;
    word-break: break-word;

    p {
        white-space: pre-wrap;
        margin-bottom: 8px;
    }

    h1, h2, h3, h4, h5, h6 {
        font-weight: 700;
        margin: 12px 0 6px;
    }

    h1 { font-size: 20px; }
    h2 { font-size: 18px; }
    h3 { font-size: 16px; }
    h4, h5, h6 { font-size: 14px; }

    p:last-child,
    ul:last-child,
    ol:last-child,
    table:last-child,
    pre:last-child,
    h1:last-child,
    h2:last-child,
    h3:last-child,
    h4:last-child,
    h5:last-child,
    h6:last-child {
        margin-bottom: 0;
    }

    ul, ol {
        margin: 0 0 10px 20px;
    }

    a {
        color: #9fd6ff;
        text-decoration: underline;
    }

    table {
        width: 100%;
        max-width: 100%;
        margin: 10px 0;
        border-collapse: collapse;
        border-spacing: 0;
        background: rgba(0, 0, 0, 0.25);
        border: 1px solid #3d4b5a;
        border-radius: 6px;
        overflow: hidden;
        white-space: normal;

        th, td {
            padding: 8px 12px;
            border: 1px solid #3d4b5a;
            text-align: left;
            font-size: 13px;
        }

        th {
            background: rgba(255, 255, 255, 0.1);
            font-weight: 700;
            color: #7fc4ea;
        }

        tr:nth-child(even) {
            background: rgba(255, 255, 255, 0.03);
        }

        tr:hover {
            background: rgba(255, 255, 255, 0.06);
        }
    }

    pre {
        background: #141a21;
        border: 1px solid #34414f;
        border-radius: 6px;
        padding: 10px 12px;
        margin: 10px 0;
        white-space: pre-wrap;
        word-break: break-all;

        code {
            background: transparent;
            color: #7fc4ea;
            padding: 0;
            border-radius: 0;
            font-family: Menlo, Monaco, Consolas, "Courier New", monospace;
            font-size: 12px;
        }
    }

    code {
        background: rgba(255, 255, 255, 0.1);
        color: #7fc4ea;
        padding: 2px 5px;
        border-radius: 4px;
        font-family: Menlo, Monaco, Consolas, "Courier New", monospace;
        font-size: 85%;
    }
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

.ask-biigle-source-chip {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 999px;
    color: #d9e4ef;
    cursor: pointer;
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
    outline: none;
    padding: 4px 8px;
    transition: background-color 0.15s ease, border-color 0.15s ease;

    &:hover,
    &:focus {
        background: rgba(255, 255, 255, 0.2);
        border-color: rgba(255, 255, 255, 0.34);
    }
}

.ask-biigle-sources-toggle {
    color: #9fd6ff;
    padding: 0;

    &:hover,
    &:focus {
        color: #c4e7ff;
        text-decoration: none;
    }
}

.ask-biigle-sources-panel {
    background: rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 8px;
    margin-top: 6px;
    padding: 8px;
}

.ask-biigle-source-item + .ask-biigle-source-item {
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    margin-top: 7px;
    padding-top: 7px;
}

.ask-biigle-source-item--active {
    background: rgba(159, 214, 255, 0.12);
    border-radius: 6px;
    padding: 6px;
}

.ask-biigle-source-item__title {
    align-items: baseline;
    display: flex;
    gap: 6px;
}

.ask-biigle-source-score {
    color: #a7b7c7;
    font-size: 11px;
    margin-left: auto;
}

.ask-biigle-source-item__snippet {
    color: #c8d6e4;
    font-size: 12px;
    margin-top: 3px;
}

.ask-biigle-footer {
    background: #1f2731;
    border-top: 1px solid #34414f;

    .form-control {
        background: #27313d;
        border-color: #3d4b5a;
        color: #e7edf4;

        &::placeholder {
            color: #a6b3c1;
        }
    }
}

.ask-biigle-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 10px;
}

.ask-biigle-typing-indicator {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 2px;
}

.ask-biigle-typing-dot {
    background: #7fc4ea;
    border-radius: 50%;
    display: inline-block;
    height: 8px;
    opacity: 0.4;
    width: 8px;
    animation: ask-biigle-bounce 1.4s ease-in-out infinite both;

    &:nth-child(1) { animation-delay: 0s; }
    &:nth-child(2) { animation-delay: 0.2s; }
    &:nth-child(3) { animation-delay: 0.4s; }
}

@keyframes ask-biigle-bounce {
    0%, 80%, 100% {
        transform: scale(0.6);
        opacity: 0.4;
    }
    40% {
        transform: scale(1);
        opacity: 1;
    }
}

.ask-biigle-btn-icon {
    align-items: center;
    display: inline-flex;
    font-size: 16px;
    height: 34px;
    justify-content: center;
    padding: 0;
    width: 38px;
}

.ask-biigle-modal-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
    width: 100%;

    .modal-title {
        color: #dfe8f2;
        font-size: 18px;
        font-weight: 700;
        margin: 0;
    }
}

.ask-biigle-header-actions {
    align-items: center;
    display: flex;
    gap: 4px;
    margin-left: auto;
}

.ask-biigle-header-btn {
    align-items: center;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 6px;
    color: #a6b3c1;
    cursor: pointer;
    display: inline-flex;
    font-size: 15px;
    height: 30px;
    justify-content: center;
    outline: none;
    padding: 0;
    transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
    width: 30px;

    &:hover,
    &:focus {
        background: rgba(255, 255, 255, 0.1);
        border-color: rgba(255, 255, 255, 0.15);
        color: #e7edf4;
    }
}

.ask-biigle-header-close {
    font-size: 22px;
    margin-left: 2px;
}

.ask-biigle-maximized {
    .modal-dialog {
        margin: 1.5vh auto;
        max-width: none;
        width: 95vw;
    }

    .ask-biigle-chat {
        display: flex;
        flex-direction: column;
    }

    .ask-biigle-messages {
        flex: 1 1 auto;
        height: calc(90vh - 200px);
    }
}

.ask-biigle-fullscreen {
    .modal-dialog {
        height: 100%;
        margin: 0;
        max-width: none;
        width: 100%;
    }

    .modal-content {
        border: 0;
        border-radius: 0;
        display: flex;
        flex-direction: column;
        height: 100%;
    }

    .modal-body {
        flex: 1 1 auto;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .ask-biigle-chat {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        overflow: hidden;
    }

    .ask-biigle-messages {
        flex: 1 1 auto;
        height: auto;
    }
}
</style>
