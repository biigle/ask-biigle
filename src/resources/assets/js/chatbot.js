const MODAL_ID = 'biiglebot-modal';
const OPEN_BUTTON_ID = 'biiglebot-open-button';
const CHAT_ENDPOINT = '/biiglebot/chat';
const MAX_HISTORY_ITEMS = 20;

let history = [];
let pending = false;

function getCsrfToken() {
    const tokenTag = document.querySelector('meta[name="csrf-token"]');

    return tokenTag ? tokenTag.getAttribute('content') : '';
}

function createMessage(role, content) {
    return {role, content};
}

function appendMessage(messagesEl, role, content) {
    const row = document.createElement('div');
    row.className = `biiglebot-message biiglebot-message--${role}`;

    const roleEl = document.createElement('span');
    roleEl.className = 'biiglebot-message__role';
    roleEl.textContent = role === 'assistant' ? 'BIIGLEBot' : role === 'user' ? 'You' : 'Error';

    const contentEl = document.createElement('span');
    contentEl.className = 'biiglebot-message__content';
    contentEl.textContent = content;

    row.appendChild(roleEl);
    row.appendChild(contentEl);
    messagesEl.appendChild(row);
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

function renderMessages(messagesEl) {
    messagesEl.innerHTML = '';
    history.forEach((message) => {
        appendMessage(messagesEl, message.role, message.content);
    });
}

function setSendState(sending) {
    pending = sending;
    const sendButton = document.getElementById('biiglebot-send');
    const clearButton = document.getElementById('biiglebot-clear');
    const input = document.getElementById('biiglebot-input');

    if (sendButton) {
        sendButton.disabled = sending;
        sendButton.textContent = sending ? 'Sending...' : 'Send';
    }
    if (clearButton) {
        clearButton.disabled = sending;
    }
    if (input) {
        input.disabled = sending;
    }
}

async function sendMessage() {
    if (pending) {
        return;
    }

    const input = document.getElementById('biiglebot-input');
    const messagesEl = document.getElementById('biiglebot-messages');

    if (!input || !messagesEl) {
        return;
    }

    const message = input.value.trim();
    if (!message) {
        return;
    }

    history.push(createMessage('user', message));
    appendMessage(messagesEl, 'user', message);
    input.value = '';
    setSendState(true);

    try {
        const response = await fetch(CHAT_ENDPOINT, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                message,
                history: history.slice(-MAX_HISTORY_ITEMS),
            }),
        });

        const data = await response.json();
        if (!response.ok) {
            const details = data && data.message ? data.message : 'Request failed.';
            appendMessage(messagesEl, 'error', details);
            return;
        }

        const assistant = data && data.assistant ? data.assistant : '';
        history.push(createMessage('assistant', assistant));
        appendMessage(messagesEl, 'assistant', assistant);
    } catch {
        appendMessage(messagesEl, 'error', 'Could not reach BIIGLEBot backend.');
    } finally {
        setSendState(false);
    }
}

function clearChat() {
    history = [];
    const messagesEl = document.getElementById('biiglebot-messages');
    if (messagesEl) {
        renderMessages(messagesEl);
    }
}

function ensureModal() {
    if (document.getElementById(MODAL_ID)) {
        return;
    }

    const modal = document.createElement('div');
    modal.id = MODAL_ID;
    modal.className = 'modal fade';
    modal.tabIndex = -1;
    modal.setAttribute('role', 'dialog');
    modal.innerHTML = `
<div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title">BIIGLEBot</h4>
        </div>
        <div class="modal-body">
            <div id="biiglebot-messages" class="biiglebot-messages"></div>
            <div class="biiglebot-input">
                <textarea id="biiglebot-input" class="form-control" rows="3" placeholder="Ask BIIGLEBot..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" id="biiglebot-clear" class="btn btn-default">Clear</button>
            <button type="button" id="biiglebot-send" class="btn btn-primary">Send</button>
        </div>
    </div>
</div>`;
    document.body.appendChild(modal);

    const sendButton = document.getElementById('biiglebot-send');
    const clearButton = document.getElementById('biiglebot-clear');
    const input = document.getElementById('biiglebot-input');
    if (sendButton) {
        sendButton.addEventListener('click', sendMessage);
    }
    if (clearButton) {
        clearButton.addEventListener('click', clearChat);
    }
    if (input) {
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            }
        });
    }

    const jq = window.jQuery || window.$;
    if (jq) {
        jq(`#${MODAL_ID}`).on('shown.bs.modal', () => {
            const field = document.getElementById('biiglebot-input');
            if (field) {
                field.focus();
            }
        });
    }
}

function showModal() {
    ensureModal();

    const jq = window.jQuery || window.$;
    if (jq) {
        jq(`#${MODAL_ID}`).modal('show');
    }
}

function insertButton() {
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
    item.addEventListener('click', (event) => {
        event.preventDefault();
        showModal();
    });

    const firstDropdown = navList.querySelector('li[is="vue:dropdown"]');
    if (firstDropdown) {
        navList.insertBefore(item, firstDropdown);
    } else {
        navList.appendChild(item);
    }
}

insertButton();
