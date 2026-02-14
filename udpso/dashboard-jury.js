document.addEventListener('DOMContentLoaded', async () => {
  const list = document.getElementById('jury-olympiads-list');
  if (!list) return;

  const appealsList = document.getElementById('jury-appeals-list');
  const appealModal = document.getElementById('jury-appeal-modal');
  const appealModalBody = document.getElementById('jury-appeal-modal-body');
  const appealForm = document.getElementById('jury-appeal-response-form');
  const closeAppealBtn = document.getElementById('close-jury-appeal-modal');
  const appealIdInput = document.getElementById('jury-appeal-id');

  const parseFiles = (files) => {
    if (Array.isArray(files)) return files;
    if (typeof files === 'string') {
      try {
        const parsed = JSON.parse(files);
        return Array.isArray(parsed) ? parsed : [];
      } catch (error) {
        return [];
      }
    }
    return [];
  };

  const openAppealModal = () => {
    if (!appealModal) return;
    appealModal.classList.add('open');
    appealModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  };

  const closeAppealModal = () => {
    if (!appealModal) return;
    appealModal.classList.remove('open');
    appealModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (appealForm) appealForm.reset();
    if (appealModalBody) appealModalBody.innerHTML = '';
  };

  closeAppealBtn?.addEventListener('click', closeAppealModal);
  appealModal?.addEventListener('click', (event) => {
    if (event.target === appealModal) {
      closeAppealModal();
    }
  });

  async function openAppealById(appealId) {
    try {
      const res = await fetch(`api/get-appeal-detail.php?appeal_id=${encodeURIComponent(appealId)}`);
      const data = await res.json();
      if (!res.ok || !data.success || !data.appeal) {
        throw new Error(data.error || 'Не удалось загрузить апелляцию');
      }

      const appeal = data.appeal;
      const appealFiles = parseFiles(appeal.appeal_files);
      const responseFiles = parseFiles(appeal.response_files);

      const appealFilesHtml = appealFiles.length
        ? `<div class="appeal-files">${appealFiles.map((file) => `<a href="api/get-file.php?type=appeal&id=${file.id}" target="_blank">${file.name || 'Файл апелляции'}</a>`).join('')}</div>`
        : '<span>Файлы не приложены</span>';

      const responseFilesHtml = responseFiles.length
        ? `<div class="appeal-files">${responseFiles.map((file) => `<a href="api/get-file.php?type=appeal_response&id=${file.id}" target="_blank">${file.name || 'Файл ответа'}</a>`).join('')}</div>`
        : '<span>Файлы не приложены</span>';

      if (appealModalBody) {
        appealModalBody.innerHTML = `
          <div class="participant-appeal-panel ${appeal.status === 'pending' ? 'pending' : 'resolved'}">
            <p><strong>Олимпиада:</strong> ${appeal.subject || '—'}</p>
            <p><strong>Участник:</strong> ${appeal.student_name || '—'}</p>
            <p><strong>Класс:</strong> ${appeal.grade || '—'}</p>
            <p><strong>Текущие баллы:</strong> ${appeal.score ?? '—'}</p>
            <p><strong>Статус:</strong> ${appeal.status === 'pending' ? 'На рассмотрении' : 'Рассмотрена'}</p>
            <p><strong>Дата подачи:</strong> ${appeal.created_at ? new Date(appeal.created_at).toLocaleString('ru-RU') : '—'}</p>
            <p><strong>Комментарий участника:</strong> ${appeal.description || '—'}</p>
            <p><strong>Файлы участника:</strong> ${appealFilesHtml}</p>
            ${appeal.status === 'resolved' ? `
              <hr>
              <p><strong>Комментарий жюри:</strong> ${appeal.response_comment || '—'}</p>
              <p><strong>Новые баллы:</strong> ${appeal.response_score ?? '—'}</p>
              <p><strong>Файлы ответа:</strong> ${responseFilesHtml}</p>
            ` : ''}
          </div>
        `;
      }

      if (appealIdInput) {
        appealIdInput.value = String(appeal.id || appealId);
      }

      const scoreInput = document.getElementById('jury-appeal-score');
      if (scoreInput) {
        scoreInput.value = appeal.score !== null && appeal.score !== undefined ? String(appeal.score) : '';
      }

      const isResolved = appeal.status === 'resolved';
      if (appealForm) {
        appealForm.style.display = isResolved ? 'none' : 'grid';
      }

      openAppealModal();
    } catch (error) {
      console.error('Ошибка загрузки апелляции:', error);
      alert(error.message || 'Не удалось загрузить апелляцию');
    }
  }

  async function loadAppeals() {
    if (!appealsList) return;
    try {
      const res = await fetch('api/get-jury-appeals.php');
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Ошибка загрузки апелляций');
      }

      const appeals = Array.isArray(data.appeals) ? data.appeals : [];
      appealsList.innerHTML = '';

      if (!appeals.length) {
        appealsList.innerHTML = '<div class="empty-state"><i class="fas fa-check-circle"></i><p>Новых апелляций нет.</p></div>';
        return;
      }

      appeals.forEach((appeal) => {
        const item = document.createElement('li');
        item.className = 'olympiad-row card-ongoing';
        item.innerHTML = `
          <div class="olympiad-row-content">
            <div>
              <div class="olympiad-title"><i class="fas fa-bell"></i> Апелляция: ${appeal.subject || '—'}</div>
              <div class="olympiad-meta">
                <span><i class="fas fa-user"></i> ${appeal.student_name || '—'}</span>
                <span><i class="fas fa-calendar-alt"></i> ${appeal.created_at ? new Date(appeal.created_at).toLocaleString('ru-RU') : '—'}</span>
              </div>
              <div class="appeal-notification-text">${appeal.description || 'Без комментария'}</div>
            </div>
            <div class="olympiad-arrow"><i class="fas fa-arrow-right"></i></div>
          </div>
        `;
        item.addEventListener('click', () => openAppealById(appeal.id));
        appealsList.appendChild(item);
      });
    } catch (error) {
      console.error('Ошибка загрузки апелляций жюри:', error);
      appealsList.innerHTML = '<p class="error-message">Ошибка загрузки апелляций.</p>';
    }
  }

  appealForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitBtn = appealForm.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    try {
      const formData = new FormData(appealForm);

      const res = await fetch('api/respond-appeal.php', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Не удалось отправить ответ');
      }

      alert('Ответ по апелляции отправлен.');
      closeAppealModal();
      await loadAppeals();
    } catch (error) {
      console.error('Ошибка отправки ответа:', error);
      alert(error.message || 'Ошибка отправки ответа');
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  try {
    const welcome = document.getElementById('welcome-message');
    if (welcome) {
      try {
        const userRes = await fetch('api/get-user-info.php');
        const user = await userRes.json();
        const displayName = user.display_name || user.full_name;
        if (displayName) {
          welcome.innerHTML = `<div class="welcome-box">
            <i class="fas fa-user-circle"></i>
            <span>Добро пожаловать, <strong>${displayName}</strong>!</span>
          </div>`;
        }
      } catch (e) {
        console.warn('Ошибка загрузки имени пользователя');
      }
    }

    document.getElementById('settings')?.addEventListener('click', () => {
      window.location.href = 'settings.html';
    });

    await loadAppeals();

    const res = await fetch('api/get-jury-olympiads.php');
    const data = await res.json();
    if (!res.ok || data.error) {
      throw new Error(data.error || 'Ошибка загрузки данных');
    }

    const olympiads = data.olympiads || [];
    list.innerHTML = '';

    if (!olympiads.length) {
      list.innerHTML = '<div class="empty-state"><i class="fas fa-info-circle"></i><p>Олимпиад пока нет.</p></div>';
      return;
    }

    olympiads.forEach(o => {
      const orgName = o.school_id ? (o.school_name || '—') : (o.organization_short_name || o.school_name || '—');
      const item = document.createElement('li');
      item.className = `olympiad-row card-${o.status}`;
      item.innerHTML = `
        <div class="olympiad-row-content">
          <div>
            <div class="olympiad-meta">
              <span><i class="fas fa-book"></i> ${o.subject}</span>
              <span><i class="fas fa-calendar-alt"></i> ${new Date(o.datetime).toLocaleString()}</span>
              <span><i class="fas fa-user-graduate"></i> ${o.grades}</span>
              <span><i class="fas fa-user-tag"></i> ${o.jury_role}</span>
              <span><i class="fas fa-building"></i> ${orgName}</span>
            </div>
            <span class="status-tag status-${o.status}">${mapStatus(o.status)}</span>
          </div>
          <div class="olympiad-arrow"><i class="fas fa-arrow-right"></i></div>
        </div>
      `;
      item.addEventListener('click', () => {
        window.location.href = `olympiad-detail.html?id=${o.id}&mode=jury`;
      });
      list.appendChild(item);
    });
  } catch (error) {
    console.error('Ошибка загрузки олимпиад жюри:', error);
    list.innerHTML = '<p class="error-message">Ошибка загрузки данных.</p>';
  }

  function mapStatus(status) {
    switch (status) {
      case 'upcoming': return 'Ожидается';
      case 'ongoing': return 'В процессе';
      case 'completed': return 'Завершена';
      case 'archived': return 'Архив';
      case 'cancelled': return 'Отменена';
      default: return 'Неизвестно';
    }
  }
});
