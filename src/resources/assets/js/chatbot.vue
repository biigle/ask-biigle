<template>
    <div :class="modalWrapperClasses">
        <modal v-model="showModal" size="lg" :footer="false" @shown="onModalShown">
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
                <div
                    ref="messages"
                    class="panel-body biiglebot-messages"
                    :class="{'biiglebot-messages--shadow-top': showTopShadow, 'biiglebot-messages--shadow-bottom': showBottomShadow}"
                    @scroll="updateScrollShadows"
                    >
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
                                class="btn btn-default btn-xs biiglebot-retry-btn"
                                title="Retry"
                                :disabled="pending"
                                @click="retryMessage(index)"
                                >
                                <i class="fa fa-redo"></i> Retry
                            </button>
                            <div v-if="message.role === 'assistant' && message.sources.length > 0" class="biiglebot-sources">
                                <div class="biiglebot-source-chips">
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
                                <div v-if="message.sourcesExpanded" class="list-group biiglebot-sources-panel">
                                    <div
                                        v-for="(source, sourceIndex) in message.sources"
                                        :key="`${index}-source-${sourceIndex}`"
                                        :id="sourceElementId(index, source.id)"
                                        class="list-group-item"
                                        :class="{'list-group-item-info': message.activeSourceId === source.id}"
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
const Modal = biigle.$require('uiv.modal');

const CHAT_ENDPOINT = '/biiglebot/chat';
const MAX_HISTORY_ITEMS = 20;
const OPEN_BUTTON_ID = 'biiglebot-open-button';

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
            buttonClickHandler: null,
            maximized: false,
            fullscreen: false,
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
                'biiglebot-maximized': this.maximized,
                'biiglebot-fullscreen': this.fullscreen,
            };
        },
    },
    methods: {
        getCsrfToken() {
            const tokenTag = document.querySelector('meta[name="csrf-token"]');

            return tokenTag ? tokenTag.getAttribute('content') : '';
        },
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
                const response = await fetch(CHAT_ENDPOINT, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                    },
                    body: JSON.stringify({
                        message,
                        history: this.requestHistory,
                    }),
                });

                const data = await response.json();
                if (!response.ok) {
                    this.addMessage('error', data && data.message ? data.message : 'Request failed.', [], message);
                    return;
                }

                this.addMessage(
                    'assistant',
                    data && data.assistant ? data.assistant : '',
                    data && Array.isArray(data.sources) ? data.sources : []
                );
            } catch {
                this.addMessage('error', 'Could not reach BIIGLEBot backend.', [], message);
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
        insertButton() {
            if (document.getElementById(OPEN_BUTTON_ID)) {
                return;
            }

            const navbarRight = document.getElementById('navbar-right');
            if (!navbarRight) {
                return;
            }

            const navList = navbarRight.querySelector('ul.navbar-nav');
            if (!navList) {
                return;
            }

            const item = document.createElement('li');
            item.id = OPEN_BUTTON_ID;
            item.innerHTML = `
<a href="#" class="navbar-btn-link" title="Open BIIGLEBot">
    <span class="btn btn-default">
        <i class="fa fa-comments"></i>
    </span>
</a>`;

            const lastItem = navList.lastElementChild;
            if (lastItem) {
                navList.insertBefore(item, lastItem);
            } else {
                navList.appendChild(item);
            }
        },
    },
    mounted() {
        this.insertButton();
        this.buttonClickHandler = (event) => {
            const target = event.target.closest(`#${OPEN_BUTTON_ID}`);
            if (!target) {
                return;
            }

            event.preventDefault();
            this.openModal();
        };
        document.addEventListener('click', this.buttonClickHandler);
        document.addEventListener('fullscreenchange', this.handleFullscreenChange);
        document.addEventListener('webkitfullscreenchange', this.handleFullscreenChange);
    },
    beforeUnmount() {
        if (this.buttonClickHandler) {
            document.removeEventListener('click', this.buttonClickHandler);
        }
        document.removeEventListener('fullscreenchange', this.handleFullscreenChange);
        document.removeEventListener('webkitfullscreenchange', this.handleFullscreenChange);
    },
};
</script>
