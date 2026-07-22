<template>
    <div :class="modalWrapperClasses">
        <modal v-model="showModal" size="lg" :footer="false" append-to-body @shown="onModalShown">
            <template #header>
                <div class="biiglebot-modal-header">
                    <h4 class="modal-title">BIIGLEBot</h4>
                    <div class="biiglebot-header-actions">
                        <button
                            type="button"
                            class="biiglebot-header-btn"
                            :title="maximized ? 'Restore' : 'Maximize'"
                            @click="toggleMaximize"
                            >
                            <i class="fa" :class="maximized ? 'fa-compress' : 'fa-expand'"></i>
                        </button>
                        <button
                            type="button"
                            class="biiglebot-header-btn"
                            :title="fullscreen ? 'Exit fullscreen' : 'Fullscreen'"
                            @click="toggleFullscreen"
                            >
                            <i class="fa" :class="fullscreen ? 'fa-compress-arrows-alt' : 'fa-expand-arrows-alt'"></i>
                        </button>
                        <button
                            type="button"
                            class="close biiglebot-header-close"
                            @click="closeModal"
                            >
                            <span>&times;</span>
                        </button>
                    </div>
                </div>
            </template>
            <div class="biiglebot-chat panel panel-default">
                <div ref="messages" class="panel-body biiglebot-messages">
                    <p v-if="messages.length === 0" class="text-muted biiglebot-empty">
                        Ask BIIGLEBot anything about using BIIGLE.
                    </p>
                    <div
                        v-for="(message, index) in messages"
                        :key="index"
                        class="biiglebot-row"
                        :class="`biiglebot-row--${message.role}`"
                        >
                        <div class="biiglebot-bubble">
                            <div class="biiglebot-bubble__role">{{ roleLabel(message.role) }}</div>
                            <div class="biiglebot-bubble__content" v-html="renderMessageHtml(message)"></div>
                            <button
                                v-if="message.role === 'error'"
                                type="button"
                                class="biiglebot-retry-btn"
                                title="Retry"
                                :disabled="pending"
                                @click="retryMessage(index)"
                                >
                                <i class="fa fa-refresh"></i> Retry
                            </button>
                            <div v-if="message.role === 'assistant' && message.sources.length > 0" class="biiglebot-sources">
                                <div class="biiglebot-source-chips">
                                    <button
                                        v-for="(source, sourceIndex) in message.sources"
                                        :key="`${index}-chip-${sourceIndex}`"
                                        type="button"
                                        class="biiglebot-source-chip"
                                        :title="source.title"
                                        @click="openSource(index, source.id)"
                                        >
                                        {{ source.id }}
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    class="btn btn-link btn-xs biiglebot-sources-toggle"
                                    @click="toggleSources(index)"
                                    >
                                    {{ message.sourcesExpanded ? 'Hide sources' : `Show sources (${message.sources.length})` }}
                                </button>
                                <div v-if="message.sourcesExpanded" class="biiglebot-sources-panel">
                                    <div
                                        v-for="(source, sourceIndex) in message.sources"
                                        :key="`${index}-source-${sourceIndex}`"
                                        :id="sourceElementId(index, source.id)"
                                        class="biiglebot-source-item"
                                        :class="{'biiglebot-source-item--active': message.activeSourceId === source.id}"
                                        >
                                        <div class="biiglebot-source-item__title">
                                            <strong>{{ source.id }}</strong>
                                            <span>{{ source.title }}</span>
                                            <span v-if="source.score !== null" class="biiglebot-source-score">
                                                {{ source.score.toFixed(3) }}
                                            </span>
                                        </div>
                                        <div v-if="source.snippet" class="biiglebot-source-item__snippet">
                                            {{ source.snippet }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="pending" class="biiglebot-row biiglebot-row--assistant">
                        <div class="biiglebot-bubble">
                            <div class="biiglebot-bubble__role">BIIGLEBot</div>
                            <div class="biiglebot-typing-indicator">
                                <span class="biiglebot-typing-dot"></span>
                                <span class="biiglebot-typing-dot"></span>
                                <span class="biiglebot-typing-dot"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="panel-footer biiglebot-footer">
                    <textarea
                        ref="input"
                        v-model="input"
                        class="form-control"
                        rows="3"
                        placeholder="Ask BIIGLEBot..."
                        :disabled="pending"
                        @keydown="handleKeyDown"
                        ></textarea>
                    <div class="biiglebot-actions">
                        <button class="btn btn-default biiglebot-btn-icon" :disabled="pending" title="Clear chat" @click="clearChat">
                            <i class="fa fa-trash"></i>
                        </button>
                        <button class="btn btn-primary biiglebot-btn-icon" :disabled="pending || !canSend" title="Send message" @click="sendMessage">
                            <i class="fa fa-paper-plane"></i>
                        </button>
                    </div>
                </div>
            </div>
        </modal>
    </div>
</template>

<script>
import BiiglebotApi from './api/biiglebot.js';

const Modal = biigle.$require('uiv.modal');
const MAX_HISTORY_ITEMS = 20;

function escapeHtml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderInlineMarkdown(value) {
    let html = escapeHtml(value);
    html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');

    return html;
}

function renderMarkdown(value) {
    const lines = value.split('\n');
    const html = [];
    let inUnorderedList = false;
    let inOrderedList = false;
    let inCodeBlock = false;
    let codeLanguage = '';
    let codeLines = [];

    const closeLists = () => {
        if (inUnorderedList) {
            html.push('</ul>');
            inUnorderedList = false;
        }

        if (inOrderedList) {
            html.push('</ol>');
            inOrderedList = false;
        }
    };

    const closeCodeBlock = () => {
        const languageClass = codeLanguage ? ` class="language-${escapeHtml(codeLanguage)}"` : '';
        const code = escapeHtml(codeLines.join('\n'));
        html.push(`<pre><code${languageClass}>${code}</code></pre>`);
        inCodeBlock = false;
        codeLanguage = '';
        codeLines = [];
    };

    lines.forEach((line) => {
        const fenceMatch = line.match(/^\s*```([^\s`]+)?\s*$/);
        if (fenceMatch) {
            if (inCodeBlock) {
                closeCodeBlock();
            } else {
                closeLists();
                inCodeBlock = true;
                codeLanguage = fenceMatch[1] || '';
                codeLines = [];
            }
            return;
        }

        if (inCodeBlock) {
            codeLines.push(line);
            return;
        }

        const headingMatch = line.match(/^\s*(#{1,6})\s*(.+)$/);
        const unorderedMatch = line.match(/^\s*[-*]\s+(.*)$/);
        const orderedMatch = line.match(/^\s*\d+\.\s+(.*)$/);

        if (headingMatch) {
            closeLists();
            const level = headingMatch[1].length;
            html.push(`<h${level}>${renderInlineMarkdown(headingMatch[2].trim())}</h${level}>`);
            return;
        }

        if (unorderedMatch) {
            if (!inUnorderedList) {
                closeLists();
                html.push('<ul>');
                inUnorderedList = true;
            }
            html.push(`<li>${renderInlineMarkdown(unorderedMatch[1])}</li>`);
            return;
        }

        if (orderedMatch) {
            if (!inOrderedList) {
                closeLists();
                html.push('<ol>');
                inOrderedList = true;
            }
            html.push(`<li>${renderInlineMarkdown(orderedMatch[1])}</li>`);
            return;
        }

        closeLists();
        const trimmed = line.trim();
        if (!trimmed) {
            return;
        }

        html.push(`<p>${renderInlineMarkdown(trimmed)}</p>`);
    });

    if (inCodeBlock) {
        closeCodeBlock();
    }

    closeLists();

    return html.join('');
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
                'biiglebot-maximized': this.maximized,
                'biiglebot-fullscreen': this.fullscreen,
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
                return 'BIIGLEBot';
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
            });
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
        },
        sourceElementId(messageIndex, sourceId) {
            const safeId = String(sourceId).replace(/[^a-zA-Z0-9_-]/g, '').toLowerCase();

            return `biiglebot-source-${messageIndex}-${safeId}`;
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
                const response = await BiiglebotApi.save({}, {
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
                    errorMessage = 'BIIGLEBot server error. Click Retry to try again.';
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
        },
    },
    mounted() {
        this.openHandler = () => this.openModal();
        window.addEventListener('biiglebot:open', this.openHandler);
        document.addEventListener('fullscreenchange', this.handleFullscreenChange);
        document.addEventListener('webkitfullscreenchange', this.handleFullscreenChange);
    },
    beforeUnmount() {
        if (this.openHandler) {
            window.removeEventListener('biiglebot:open', this.openHandler);
        }
        document.removeEventListener('fullscreenchange', this.handleFullscreenChange);
        document.removeEventListener('webkitfullscreenchange', this.handleFullscreenChange);
    },
};
</script>

<style lang="scss">
.biiglebot-chat {
    margin-bottom: 0;
}

.biiglebot-messages {
    background: #1f2731;
    border: 1px solid #34414f;
    border-radius: 4px;
    height: 340px;
    overflow-y: auto;
    padding: 12px;
}

.biiglebot-empty {
    color: #c3cfdb;
    margin: 0;
    text-align: center;
}

.biiglebot-row {
    display: flex;
    margin-bottom: 10px;
}

.biiglebot-row--assistant {
    justify-content: flex-start;
}

.biiglebot-row--user {
    justify-content: flex-end;
}

.biiglebot-row--error {
    justify-content: center;
}

.biiglebot-bubble {
    background: #2b3542;
    border: 1px solid #415062;
    border-radius: 14px;
    max-width: 85%;
    padding: 10px 12px;
    position: relative;
    color: #dfe8f2;
}

.biiglebot-row--assistant .biiglebot-bubble {
    background: #233746;
    border-color: #35566d;
    box-shadow: inset 0 0 0 1px #2d4a5e;
}

.biiglebot-row--user .biiglebot-bubble {
    background: #273b31;
    border-color: #3f614f;
    box-shadow: inset 0 0 0 1px #335041;
}

.biiglebot-row--error .biiglebot-bubble {
    border-color: #ebccd1;
    box-shadow: inset 0 0 0 1px #d9534f;
}

.biiglebot-retry-btn {
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
        animation: biiglebot-spin 0.5s ease-in-out;
    }

    &:disabled {
        cursor: not-allowed;
        opacity: 0.5;
    }
}

@keyframes biiglebot-spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

.biiglebot-bubble__role {
    font-weight: 700;
    margin-bottom: 4px;
}

.biiglebot-row--assistant .biiglebot-bubble__role {
    color: #7fc4ea;
}

.biiglebot-row--user .biiglebot-bubble__role {
    color: #8bdeb0;
}

.biiglebot-row--error .biiglebot-bubble__role {
    color: #a94442;
}

.biiglebot-bubble__content {
    color: #dfe8f2;
    white-space: pre-wrap;
    word-break: break-word;

    h1, h2, h3, h4, h5, h6 {
        font-weight: 700;
        margin: 8px 0 6px;
    }

    h1 { font-size: 20px; }
    h2 { font-size: 18px; }
    h3 { font-size: 16px; }
    h4, h5, h6 { font-size: 14px; }

    p:last-child,
    ul:last-child,
    ol:last-child,
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
}

.biiglebot-sources {
    border-top: 1px solid rgba(255, 255, 255, 0.12);
    margin-top: 8px;
    padding-top: 8px;
}

.biiglebot-source-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 4px;
}

.biiglebot-source-chip {
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

.biiglebot-sources-toggle {
    color: #9fd6ff;
    padding: 0;

    &:hover,
    &:focus {
        color: #c4e7ff;
        text-decoration: none;
    }
}

.biiglebot-sources-panel {
    background: rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 8px;
    margin-top: 6px;
    padding: 8px;
}

.biiglebot-source-item + .biiglebot-source-item {
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    margin-top: 7px;
    padding-top: 7px;
}

.biiglebot-source-item--active {
    background: rgba(159, 214, 255, 0.12);
    border-radius: 6px;
    padding: 6px;
}

.biiglebot-source-item__title {
    align-items: baseline;
    display: flex;
    gap: 6px;
}

.biiglebot-source-score {
    color: #a7b7c7;
    font-size: 11px;
    margin-left: auto;
}

.biiglebot-source-item__snippet {
    color: #c8d6e4;
    font-size: 12px;
    margin-top: 3px;
}

.biiglebot-footer {
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

.biiglebot-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 10px;
}

.biiglebot-typing-indicator {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 2px;
}

.biiglebot-typing-dot {
    background: #7fc4ea;
    border-radius: 50%;
    display: inline-block;
    height: 8px;
    opacity: 0.4;
    width: 8px;
    animation: biiglebot-bounce 1.4s ease-in-out infinite both;

    &:nth-child(1) { animation-delay: 0s; }
    &:nth-child(2) { animation-delay: 0.2s; }
    &:nth-child(3) { animation-delay: 0.4s; }
}

@keyframes biiglebot-bounce {
    0%, 80%, 100% {
        transform: scale(0.6);
        opacity: 0.4;
    }
    40% {
        transform: scale(1);
        opacity: 1;
    }
}

.biiglebot-btn-icon {
    align-items: center;
    display: inline-flex;
    font-size: 16px;
    height: 34px;
    justify-content: center;
    padding: 0;
    width: 38px;
}

.biiglebot-modal-header {
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

.biiglebot-header-actions {
    align-items: center;
    display: flex;
    gap: 4px;
    margin-left: auto;
}

.biiglebot-header-btn {
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

.biiglebot-header-close {
    font-size: 22px;
    margin-left: 2px;
}

.biiglebot-maximized {
    .modal-dialog {
        margin: 1.5vh auto;
        max-width: none;
        width: 95vw;
    }

    .biiglebot-chat {
        display: flex;
        flex-direction: column;
    }

    .biiglebot-messages {
        flex: 1 1 auto;
        height: calc(90vh - 200px);
    }
}

.biiglebot-fullscreen {
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

    .biiglebot-chat {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        overflow: hidden;
    }

    .biiglebot-messages {
        flex: 1 1 auto;
        height: auto;
    }
}
</style>
