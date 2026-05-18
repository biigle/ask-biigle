<template>
    <div>
        <modal v-model="showModal" title="BIIGLEBot" size="lg" :footer="false" @shown="focusInput">
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
                        <button class="btn btn-default" :disabled="pending" @click="clearChat">Clear</button>
                        <button class="btn btn-primary" :disabled="pending || !canSend" @click="sendMessage">
                            {{ pending ? 'Sending...' : 'Send' }}
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

function stripRagArtifacts(value) {
    let cleaned = value.replace(/\r\n/g, '\n');
    cleaned = cleaned.replace(/\n?-{3,}\s*References?:[\s\S]*$/i, '');
    cleaned = cleaned.replace(/\n\s*References?:\s*\[[A-Z]*REF\d+\][\s\S]*$/i, '');
    cleaned = cleaned.replace(/\s*\[(?:RREF|REF)\d+\]/gi, '');
    cleaned = cleaned.replace(/\n{3,}/g, '\n\n');

    return cleaned.trim();
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

    lines.forEach((line) => {
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
            });
        },
        addMessage(role, content) {
            const normalizedContent = role === 'assistant' ? stripRagArtifacts(content) : content;
            this.messages.push({role, content: normalizedContent});
            this.scrollToBottom();
        },
        renderMessageHtml(message) {
            if (message.role === 'user') {
                return `<p>${escapeHtml(message.content)}</p>`;
            }

            return renderMarkdown(stripRagArtifacts(message.content));
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
                    this.addMessage('error', data && data.message ? data.message : 'Request failed.');
                    return;
                }

                this.addMessage('assistant', data && data.assistant ? data.assistant : '');
            } catch {
                this.addMessage('error', 'Could not reach BIIGLEBot backend.');
            } finally {
                this.pending = false;
                this.focusInput();
            }
        },
        openModal() {
            this.showModal = true;
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

            const firstDropdown = navList.querySelector('li[is="vue:dropdown"]');
            if (firstDropdown) {
                navList.insertBefore(item, firstDropdown);
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
    },
    beforeUnmount() {
        if (this.buttonClickHandler) {
            document.removeEventListener('click', this.buttonClickHandler);
        }
    },
};
</script>
