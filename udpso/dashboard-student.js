document.addEventListener('DOMContentLoaded', async () => {
  try {
    document.getElementById('settings')?.addEventListener('click', () => {
      window.location.href = 'settings.html';
    });

    const res = await fetch('api/get-student-olympiads.php');
    const data = await res.json();

    if (!data.success) {
      console.error('Ошибка:', data.error);
      return;
    }

    const greetingEl = document.getElementById('greeting');
    if (greetingEl && data.name) {
      greetingEl.textContent = `Добро пожаловать, ${data.name}!`;
    }

    const getTrophyIcon = (place) => {
      if (!place) return '';
      let color = '#bdc3c7';
      if (place === 1) color = '#f1c40f';
      else if (place === 2) color = '#c0c0c0';
      else if (place === 3) color = '#cd7f32';

      return `<i class="fas fa-trophy" style="color: ${color}; margin-left: 6px;" title="Место: ${place}"></i>`;
    };

    const renderStatus = (status) => {
      const map = {
        upcoming:  { text: 'Ожидается', class: 'status status-upcoming' },
        ongoing:   { text: 'В процессе', class: 'status status-ongoing' },
        completed: { text: 'Завершена', class: 'status status-completed' },
        cancelled: { text: 'Отменена', class: 'status status-cancelled' }
      };
      const s = map[status] || { text: status, class: 'status' };
      return `<span class="status-tag ${s.class}">${s.text}</span>`;
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

    const formatDateTime = (value) => {
      if (!value) return '-';
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return '-';
      return date.toLocaleString('ru-RU');
    };

    const detailModal = document.getElementById('olympiad-detail-modal');
    const appealModal = document.getElementById('appeal-modal');
    const detailBody = document.getElementById('olympiad-detail-body');
    const openAppealBtn = document.getElementById('open-appeal');
    const appealHint = document.getElementById('appeal-hint');
    const appealForm = document.getElementById('appeal-form');
    const appealOlympiadId = document.getElementById('appeal-olympiad-id');
    const appealDescription = document.getElementById('appeal-description');
    const appealFiles = document.getElementById('appeal-files');

    const updateBodyScroll = () => {
      const hasOpenModal = document.querySelector('.modal.open');
      document.body.style.overflow = hasOpenModal ? 'hidden' : '';
    };

    const openModal = (modal) => {
      if (!modal) return;
      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
      updateBodyScroll();
    };

    const closeModal = (modal) => {
      if (!modal) return;
      modal.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true');
      updateBodyScroll();
    };

    document.querySelectorAll('.modal .close-modal, .modal .modal-close').forEach(btn => {
      btn.addEventListener('click', () => {
        closeModal(btn.closest('.modal'));
      });
    });

    document.querySelectorAll('.modal').forEach(modal => {
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeModal(modal);
        }
      });
    });

    const openAppealForm = (olympiadId) => {
      if (!appealForm) return;
      appealOlympiadId.value = olympiadId;
      appealDescription.value = '';
      if (appealFiles) {
        appealFiles.value = '';
      }
      openModal(appealModal);
    };

    if (appealForm) {
      appealForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submitButton = appealForm.querySelector('button[type="submit"]');
        if (submitButton) submitButton.disabled = true;
        try {
          const formData = new FormData(appealForm);
          const response = await fetch('api/submit-appeal.php', {
            method: 'POST',
            body: formData
          });
          const result = await response.json();
          if (result.success) {
            alert('Апелляция отправлена.');
            closeModal(appealModal);
          } else {
            alert(result.error || 'Не удалось отправить апелляцию');
          }
        } catch (error) {
          console.error('Ошибка отправки апелляции:', error);
          alert('Ошибка отправки апелляции');
        } finally {
          if (submitButton) submitButton.disabled = false;
        }
      });
    }

    const body = document.getElementById('student-olympiads-body');
    const searchInput = document.getElementById('olympiads-search');
    const sortButtons = document.querySelectorAll('.sort-button');
    body.innerHTML = '';

    const olympiads = Array.isArray(data.olympiads) ? data.olympiads : [];
    let currentSort = { key: 'date', dir: 'desc' };

    const updateSortIndicators = () => {
      sortButtons.forEach(btn => {
        const indicator = btn.querySelector('.sort-indicator');
        if (!indicator) return;
        if (btn.dataset.sort === currentSort.key) {
          indicator.textContent = currentSort.dir === 'asc' ? '▲' : '▼';
        } else {
          indicator.textContent = '↕';
        }
      });
    };

    const getSortValue = (o, key) => {
      switch (key) {
        case 'name':
          return String(o.subject || '').toLowerCase();
        case 'date':
          return o.datetime ? new Date(o.datetime).getTime() : 0;
        case 'status':
          return String(o.status || '').toLowerCase();
        case 'organization':
          return String(o.organization_name || '').toLowerCase();
        case 'score':
          return o.score === null || o.score === undefined ? -Infinity : Number(o.score);
        default:
          return '';
      }
    };

    const openOlympiadDetail = async (olympiadId) => {
      if (!detailBody) return;
      try {
        const response = await fetch(`api/get-student-olympiad-detail.php?id=${encodeURIComponent(olympiadId)}`);
        const result = await response.json();
        if (!result.success) {
          alert(result.error || 'Не удалось загрузить данные олимпиады');
          return;
        }

        const olympiad = result.olympiad;
        const description = olympiad.description ? escapeHtml(olympiad.description) : 'Описание отсутствует';
        const grades = olympiad.grades ? escapeHtml(olympiad.grades) : '-';
        const organizer = olympiad.organizer_name ? escapeHtml(olympiad.organizer_name) : '-';

        const responseFiles = Array.isArray(olympiad.appeal_response_files)
          ? olympiad.appeal_response_files
          : [];

        const responseFilesHtml = responseFiles.length > 0
          ? `<div class="work-files">${responseFiles.map((file, index) => {
              const label = file.name ? escapeHtml(file.name) : `Файл ответа ${index + 1}`;
              return `<a class="work-file-link" href="api/get-file.php?type=appeal_response&id=${file.id}" target="_blank"><i class="fas fa-paperclip"></i><span>${label}</span></a>`;
            }).join('')}</div>`
          : '<span>Файлы не прикреплены</span>';

        const appealResponseInfo = olympiad.appeal_status === 'resolved'
          ? `
            <div class="olympiad-detail-item">
              <strong>Ответ на апелляцию</strong>
              <div>${escapeHtml(olympiad.appeal_response_comment || 'Комментарий не указан')}</div>
            </div>
            <div class="olympiad-detail-item">
              <strong>Новые баллы по апелляции</strong>
              <div>${olympiad.appeal_response_score ?? '—'}</div>
            </div>
            <div class="olympiad-detail-item">
              <strong>Материалы ответа жюри</strong>
              <div>${responseFilesHtml}</div>
            </div>
          `
          : '';

        detailBody.innerHTML = `
          <div class="olympiad-detail-item">
            <strong>Название олимпиады</strong>
            <div>${escapeHtml(olympiad.subject || '-')}</div>
          </div>
          <div class="olympiad-detail-item">
            <strong>Организация</strong>
            <div>${organizer}</div>
          </div>
          <div class="olympiad-detail-item">
            <strong>Дата проведения</strong>
            <div>${formatDateTime(olympiad.datetime)}</div>
          </div>
          <div class="olympiad-detail-item">
            <strong>Классы</strong>
            <div>${grades}</div>
          </div>
          <div class="olympiad-detail-item">
            <strong>Описание</strong>
            <div>${description}</div>
          </div>
          ${appealResponseInfo}
        `;

        const canAppeal = Boolean(olympiad.can_appeal);
        if (openAppealBtn) {
          openAppealBtn.disabled = !canAppeal;
          openAppealBtn.onclick = () => openAppealForm(olympiad.id);
        }
        if (appealHint) {
          if (!canAppeal) {
            appealHint.hidden = false;
            appealHint.textContent = 'Апелляция доступна в течение 24 часов после публикации результатов.';
          } else {
            appealHint.hidden = true;
          }
        }

        openModal(detailModal);
      } catch (error) {
        console.error('Ошибка загрузки олимпиады:', error);
        alert('Ошибка загрузки олимпиады');
      }
    };

    const renderRows = () => {
      body.innerHTML = '';
      const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
      const filtered = olympiads.filter(o => {
        if (!query) return true;
        const name = String(o.subject || '').toLowerCase();
        const date = String(o.datetime || '').toLowerCase();
        const organization = String(o.organization_name || '').toLowerCase();
        return name.includes(query) || date.includes(query) || organization.includes(query);
      });

      if (filtered.length === 0) {
        const row = document.createElement('tr');
        row.innerHTML = `<td colspan="6" style="text-align:center; padding:14px;">Пока нет олимпиад</td>`;
        body.appendChild(row);
        return;
      }

      filtered.sort((a, b) => {
        const aVal = getSortValue(a, currentSort.key);
        const bVal = getSortValue(b, currentSort.key);
        if (aVal === bVal) return 0;
        if (currentSort.dir === 'asc') {
          return aVal > bVal ? 1 : -1;
        }
        return aVal < bVal ? 1 : -1;
      });

      filtered.forEach(o => {
        const workFiles = Array.isArray(o.work_files)
          ? o.work_files.map(file => ({ ...file, _type: 'work' }))
          : (o.work_file_id ? [{ id: o.work_file_id, name: o.work_file_name || '', _type: 'work' }] : []);

        const appealResponseFiles = Array.isArray(o.appeal_response_files)
          ? o.appeal_response_files.map(file => ({ ...file, _type: 'appeal_response' }))
          : [];

        const allWorkFiles = [...workFiles, ...appealResponseFiles];

        const workLink = allWorkFiles.length > 0
          ? `<div class="work-files">${allWorkFiles.map((file, index) => {
            const label = file.name ? escapeHtml(file.name) : `Файл ${index + 1}`;
            const fileTypeLabel = file._type === 'appeal_response' ? ' (ответ жюри)' : '';
            return `<a class="work-file-link" href="api/get-file.php?type=${file._type}&id=${file.id}" target="_blank"><i class="fas fa-download"></i><span>${label}${fileTypeLabel}</span></a>`;
          }).join('')}</div>`
          : '-';

        const scoreCell = (o.score !== null && o.score !== undefined)
          ? `${o.score}${getTrophyIcon(Number(o.place))}`
          : '-';

        const row = document.createElement('tr');
        row.dataset.olympiadId = o.id;
        row.innerHTML = `
          <td data-label="Название">${o.subject || '-'}</td>
          <td data-label="Дата">${o.datetime || '-'}</td>
          <td data-label="Статус">${renderStatus(o.status)}</td>
          <td data-label="Школа">${o.organization_name || '-'}</td>
          <td data-label="Баллы">${scoreCell}</td>
          <td data-label="Моя работа">${workLink}</td>
        `;
        row.addEventListener('click', (event) => {
          if (event.target.closest('a, button, input, textarea, label')) {
            return;
          }
          const olympiadId = row.dataset.olympiadId;
          if (olympiadId) {
            openOlympiadDetail(olympiadId);
          }
        });
        body.appendChild(row);
      });
    };

    sortButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        const key = btn.dataset.sort;
        if (!key) return;
        if (currentSort.key === key) {
          currentSort.dir = currentSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
          currentSort = { key, dir: 'asc' };
        }
        updateSortIndicators();
        renderRows();
      });
    });

    if (searchInput) {
      searchInput.addEventListener('input', () => {
        renderRows();
      });
    }

    updateSortIndicators();
    renderRows();

  } catch (error) {
    console.error('Ошибка загрузки олимпиад:', error);
  }
});
