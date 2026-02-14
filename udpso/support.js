const chatListEl = document.getElementById('support-chat-list');
const chatsPanel = document.getElementById('support-chats');
const messagesEl = document.getElementById('support-messages');
const messageInput = document.getElementById('support-message');
const supportForm = document.getElementById('support-form');
const chatTitle = document.getElementById('support-chat-title');
const chatSubtitle = document.getElementById('support-chat-subtitle');

let activeChatId = null;
let activeChatUserName = '';
let refreshTimer = null;
const userRole = localStorage.getItem('userRole') || '';

const isSupportAgent = ['admin', 'moderator'].includes(userRole);

const formatDate = (value) => {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleString('ru-RU');
};

const escapeHtml = (value) => {
  const str = String(value ?? '');
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
};

const renderMessages = (messages) => {
  messagesEl.innerHTML = '';
  if (!messages.length) {
    messagesEl.innerHTML = '<div class="support-message-meta">Пока нет сообщений.</div>';
    return;
  }

  messages.forEach(message => {
    const isSelf = isSupportAgent
      ? ['admin', 'moderator'].includes(message.sender_role)
      : message.sender_role === userRole;
    const senderLabel = ['admin', 'moderator'].includes(message.sender_role)
      ? 'Поддержка'
      : (message.sender_name || (message.sender_role === 'guest' ? 'Гость' : 'Пользователь'));

    const messageEl = document.createElement('div');
    messageEl.className = `support-message${isSelf ? ' support-message-self' : ''}`;
    messageEl.innerHTML = `
      <div class="support-message-bubble">${escapeHtml(message.message)}</div>
      <div class="support-message-meta">${senderLabel} · ${formatDate(message.created_at)}</div>
    `;
    messagesEl.appendChild(messageEl);
  });

  messagesEl.scrollTop = messagesEl.scrollHeight;
};

const loadMessages = async () => {
  if (!activeChatId) return;
  const response = await fetch(`api/get-support-messages.php?chat_id=${encodeURIComponent(activeChatId)}`);
  const data = await response.json();
  if (!data.success) {
    console.error('Ошибка загрузки сообщений:', data.error);
    return;
  }
  renderMessages(data.messages || []);
};

const setActiveChat = async (chatId, userName = '') => {
  activeChatId = chatId;
  activeChatUserName = userName;
  if (chatTitle) {
    chatTitle.textContent = userName ? `Чат с ${userName}` : 'Чат с поддержкой';
  }
  if (chatSubtitle) {
    chatSubtitle.textContent = isSupportAgent
      ? 'Ответьте пользователю в чате.'
      : 'Мы отвечаем в рабочее время.';
  }
  await loadMessages();
};

const loadChatList = async () => {
  if (!isSupportAgent) return;
  const response = await fetch('api/get-support-chats.php');
  const data = await response.json();
  if (!data.success) {
    console.error('Ошибка загрузки чатов:', data.error);
    return;
  }

  const chats = data.chats || [];
  chatListEl.innerHTML = '';
  if (!chats.length) {
    chatListEl.innerHTML = '<li class="support-message-meta">Пока нет обращений.</li>';
    return;
  }

  chats.forEach(chat => {
    const item = document.createElement('li');
    item.className = `support-chat-item${String(chat.id) === String(activeChatId) ? ' active' : ''}`;
    item.innerHTML = `
      <strong>${escapeHtml(chat.full_name || 'Пользователь')}</strong>
      <span>${chat.last_message_at ? `Последнее сообщение: ${formatDate(chat.last_message_at)}` : 'Нет сообщений'}</span>
    `;
    item.addEventListener('click', async () => {
      document.querySelectorAll('.support-chat-item').forEach(el => el.classList.remove('active'));
      item.classList.add('active');
      await setActiveChat(chat.id, chat.full_name || 'Пользователь');
    });
    chatListEl.appendChild(item);
  });

  if (!activeChatId && chats.length) {
    await setActiveChat(chats[0].id, chats[0].full_name || 'Пользователь');
    chatListEl.querySelector('.support-chat-item')?.classList.add('active');
  }
};

const initUserChat = async () => {
  const response = await fetch('api/get-support-chat.php');
  const data = await response.json();
  if (!data.success) {
    console.error('Ошибка загрузки чата:', data.error);
    return;
  }
  await setActiveChat(data.chat_id);
};

const init = async () => {
  if (isSupportAgent) {
    chatsPanel.hidden = false;
    await loadChatList();
  } else {
    chatsPanel.hidden = true;
    document.querySelector('.support-layout')?.classList.add('support-layout-single');
    await initUserChat();
  }

  refreshTimer = setInterval(async () => {
    if (isSupportAgent) {
      await loadChatList();
    }
    await loadMessages();
  }, 7000);
};

supportForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!activeChatId || !messageInput?.value.trim()) return;
  const message = messageInput.value.trim();
  messageInput.value = '';
  try {
    const response = await fetch('api/send-support-message.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ chat_id: activeChatId, message })
    });
    const data = await response.json();
    if (!data.success) {
      alert(data.error || 'Не удалось отправить сообщение');
      return;
    }
    await loadMessages();
    if (isSupportAgent) {
      await loadChatList();
    }
  } catch (error) {
    console.error('Ошибка отправки сообщения:', error);
  }
});

window.addEventListener('beforeunload', () => {
  if (refreshTimer) clearInterval(refreshTimer);
});

init();
