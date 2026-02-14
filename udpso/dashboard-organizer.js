import { SchoolRegistrationModal } from './school-registration-modal.js';

document.addEventListener('DOMContentLoaded', async () => {
  document.getElementById('add-school')?.addEventListener('click', () => {
    SchoolRegistrationModal.open();
  });

  document.getElementById('settings')?.addEventListener('click', () => {
    window.location.href = 'settings.html';
  });

  async function loadRecentActivity() {
    const activityList = document.getElementById('activity-list');
    if (!activityList) return;

    try {
      const response = await fetch('api/get-activity.php?limit=5');
      const data = await response.json();

      if (!data.success) {
        activityList.innerHTML = '<div class="error-message">Не удалось загрузить последние действия</div>';
        return;
      }

      if (!data.activities || data.activities.length === 0) {
        activityList.innerHTML = '<div class="empty-message">Нет последних действий</div>';
        return;
      }

      activityList.innerHTML = data.activities.map((activity) => `
        <div class="activity-item">
          <div class="activity-icon ${getActivityIconClass(activity.type)}">
            <i class="${getActivityIcon(activity.type)}"></i>
          </div>
          <div class="activity-content">
            <div class="activity-title">${activity.title}</div>
            <div class="activity-date">
              ${new Date(activity.created_at).toLocaleString()}
            </div>
          </div>
        </div>
      `).join('');
    } catch (error) {
      console.error('Ошибка загрузки активности:', error);
      activityList.innerHTML = '<div class="error-message">Не удалось загрузить последние действия</div>';
    }
  }

  function getActivityIcon(type) {
    const icons = {
      school_registered: 'fas fa-school',
      user_created: 'fas fa-user-plus',
      olympiad_created: 'fas fa-trophy',
      application_processed: 'fas fa-file-signature'
    };
    return icons[type] || 'fas fa-info-circle';
  }

  function getActivityIconClass(type) {
    const classes = {
      school_registered: 'icon-school',
      user_created: 'icon-user',
      olympiad_created: 'icon-olympiad',
      application_processed: 'icon-school'
    };
    return classes[type] || '';
  }

  // Просмотр зарегистрированных образовательных учреждений
  const schoolsModal = document.getElementById('schools-modal');
  const schoolsModalClose = document.getElementById('schools-modal-close');
  const schoolsBody = document.getElementById('schools-modal-body');
  const schoolDetailsPanel = document.getElementById('school-details-panel');
  const schoolDetailsContent = document.getElementById('school-details-content');

  let organizerSchools = [];
  let selectedSchoolId = null;

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function renderSchoolDetails(school) {
    if (!schoolDetailsPanel || !schoolDetailsContent || !school) return;

    if (selectedSchoolId === Number(school.id) && schoolDetailsPanel.style.display !== 'none') {
      schoolDetailsPanel.style.display = 'none';
      schoolDetailsContent.innerHTML = '';
      selectedSchoolId = null;
      return;
    }

    const documents = Array.isArray(school.documents) ? school.documents : [];
    const docsHtml = documents.length > 0
      ? `<ul style="margin:8px 0 0 18px; padding:0;">${documents.map((doc) =>
          `<li><a href="api/get-organizer-school-document.php?id=${Number(doc.id)}" target="_blank" rel="noopener">${escapeHtml(doc.original_name)}</a></li>`
        ).join('')}</ul>`
      : '<p style="margin:8px 0 0 0;">Документы не прикреплены.</p>';

    schoolDetailsContent.innerHTML = `
      <p><strong>Полное наименование:</strong> ${escapeHtml(school.full_name || '-')}</p>
      <p><strong>Краткое наименование:</strong> ${escapeHtml(school.short_name || '-')}</p>
      <p><strong>Регион:</strong> ${escapeHtml(school.region || '-')}</p>
      <p><strong>Адрес:</strong> ${escapeHtml(school.address || '-')}</p>
      <p><strong>ИНН:</strong> ${escapeHtml(school.inn || '-')}</p>
      <p><strong>ОГРН:</strong> ${escapeHtml(school.ogrn || '-')}</p>
      <p><strong>Дата регистрации:</strong> ${escapeHtml(school.ogrn_date || '-')}</p>
      <p><strong>Руководитель:</strong> ${escapeHtml(school.director_fio || '-')}</p>
      <p><strong>ИНН руководителя:</strong> ${escapeHtml(school.director_inn || '-')}</p>
      <p><strong>Должность руководителя:</strong> ${escapeHtml(school.director_position || '-')}</p>
      <p><strong>Email:</strong> ${escapeHtml(school.contact_email || '-')}</p>
      <p><strong>Телефон:</strong> ${escapeHtml(school.contact_phone || '-')}</p>
      <p><strong>Логин:</strong> ${escapeHtml(school.login || '-')}</p>
      <div><strong>Прикрепленные документы:</strong>${docsHtml}</div>
    `;

    schoolDetailsPanel.style.display = 'block';
    selectedSchoolId = Number(school.id);
  }

  async function loadOrganizerSchools() {
    if (!schoolsBody) return;

    schoolsBody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:12px;">Загрузка...</td></tr>`;
    if (schoolDetailsPanel) {
      schoolDetailsPanel.style.display = 'none';
      schoolDetailsContent.innerHTML = '';
    }
    selectedSchoolId = null;

    try {
      const res = await fetch('api/get-organizer-schools.php');
      const data = await res.json();

      if (!data.success) {
        schoolsBody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:12px;">Ошибка: ${data.error || 'Не удалось загрузить данные'}</td></tr>`;
        return;
      }

      organizerSchools = data.schools || [];

      if (organizerSchools.length === 0) {
        schoolsBody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:12px;">Нет зарегистрированных учреждений</td></tr>`;
        return;
      }

      schoolsBody.innerHTML = '';
      organizerSchools.forEach((s) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${escapeHtml(s.full_name || '-')}</td>
          <td>${escapeHtml(s.region || '-')}</td>
          <td>${escapeHtml(s.contact_email || '-')}</td>
          <td>${escapeHtml(s.login || '-')}</td>
          <td>${escapeHtml(formatDateTime(s.approved_at))}</td>
          <td>
            <div class="table-actions">
              <button class="table-action-btn action-edit" data-action="edit-school" data-id="${Number(s.id)}" type="button" title="Редактировать" aria-label="Редактировать">
                <i class="fas fa-pen"></i>
              </button>
              <button class="table-action-btn action-delete" data-action="delete-school" data-id="${Number(s.id)}" type="button" title="Удалить" aria-label="Удалить">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </td>
        `;

        tr.addEventListener('click', (event) => {
          const target = event.target;
          if (target?.closest('button')) return;
          renderSchoolDetails(s);
        });

        schoolsBody.appendChild(tr);
      });
    } catch (e) {
      console.error(e);
      schoolsBody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:12px;">Ошибка загрузки</td></tr>`;
    }
  }

  async function openSchoolsModal() {
    if (!schoolsModal || !schoolsBody) return;
    schoolsModal.classList.add('open');
    document.body.classList.add('no-scroll');
    await loadOrganizerSchools();
  }

  async function deleteSchool(schoolId) {
    if (!confirm('Вы уверены, что хотите полностью удалить школу из базы данных?')) return;

    try {
      const res = await fetch('api/delete-organizer-school.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ school_id: schoolId })
      });
      const data = await res.json();
      if (!data.success) {
        alert(data.error || 'Не удалось удалить школу');
        return;
      }
      if (schoolDetailsPanel) {
        schoolDetailsPanel.style.display = 'none';
        schoolDetailsContent.innerHTML = '';
      }
      selectedSchoolId = null;
      await loadOrganizerSchools();
    } catch (error) {
      console.error(error);
      alert('Ошибка при удалении школы');
    }
  }

  schoolsBody?.addEventListener('click', (event) => {
    const btn = event.target?.closest('button[data-action]');
    if (!btn) return;

    const schoolId = Number(btn.dataset.id || 0);
    const school = organizerSchools.find((item) => Number(item.id) === schoolId);
    if (!school) return;

    if (btn.dataset.action === 'edit-school') {
      schoolsModal?.classList.remove('open');
      document.body.classList.remove('no-scroll');

      SchoolRegistrationModal.open({
        mode: 'edit',
        school,
        onSuccess: async () => {
          schoolsModal?.classList.add('open');
          document.body.classList.add('no-scroll');
          await loadOrganizerSchools();
        }
      });
      return;
    }

    if (btn.dataset.action === 'delete-school') {
      deleteSchool(schoolId);
    }
  });

  function formatDateTime(value) {
    if (!value) return '-';
    return String(value).split('.')[0].replace('T', ' ');
  }

  document.getElementById('view-schools')?.addEventListener('click', openSchoolsModal);
  schoolsModalClose?.addEventListener('click', () => {
    schoolsModal?.classList.remove('open');
    document.body.classList.remove('no-scroll');
  });
  schoolsModal?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
      schoolsModal.classList.remove('open');
      document.body.classList.remove('no-scroll');
    }
  });


  const welcome = document.getElementById('welcome-message');
  try {
    const res = await fetch('api/get-user-info.php');
    const user = await res.json();
    if (user.full_name) {
      welcome.innerHTML = `<div class="welcome-box">
        <i class="fas fa-user-circle"></i>
        <span>Добро пожаловать, <strong>${user.full_name}</strong>!</span>
      </div>`;
    }
  } catch (e) {
    console.warn('Ошибка загрузки имени пользователя');
  }

  await loadRecentActivity();


  // Обновляем статусы олимпиад при входе
  await fetch('api/update-olympiad-statuses.php');

  // Обновляем статусы и статистику каждые 60 секунд
  setInterval(async () => {
    await fetch('api/update-olympiad-statuses.php');
    try {
      const res = await fetch('api/get-dashboard-stats.php');
      const text = await res.text();
      console.log('Автообновление статистики:', text);
      const stats = JSON.parse(text);
      if (!stats.error) {
        document.getElementById('active-count').textContent = stats.active;
        document.getElementById('completed-count').textContent = stats.completed;
        document.getElementById('my-schools-count').textContent = stats.my_schools;

      }
    } catch (e) {
      console.error('Ошибка автообновления статистики', e);
    }
  }, 60000);

  document.getElementById('view-olympiads')?.addEventListener('click', () => {
    window.location.href = 'all-olympiads.html';
  });

  try {
    const res = await fetch('api/get-dashboard-stats.php');
    const text = await res.text();
    console.log('Ответ от сервера:', text);
    const stats = JSON.parse(text);
    if (!stats.error) {
      document.getElementById('active-count').textContent = stats.active;
      document.getElementById('completed-count').textContent = stats.completed;
      document.getElementById('my-schools-count').textContent = stats.my_schools;

    }
  } catch (e) {
    console.error('Ошибка загрузки статистики', e);
  }

  const modal = document.getElementById('olympiad-modal');
  const form = document.getElementById('olympiad-form');

  const subjectSelect = form?.querySelector('select[name="subject"]');
  const gradesInput = form?.querySelector('input[name="grades"]');

  function getGradeBoundsBySubject(subject) {
    const s = (subject || '').trim().toLowerCase();
    // Для русского и математики допускаем с 4 класса, для остальных — с 5.
    const min = (s === 'русский язык' || s === 'математика') ? 4 : 5;
      return { min, max: 11 };
    }

    function parseGradesRange(raw) {
    const text = String(raw || '').trim();

    // Один класс: "5"
    const single = text.match(/^\s*(\d{1,2})\s*$/);
    if (single) {
      const v = Number(single[1]);
      if (!Number.isFinite(v)) return null;
      return { from: v, to: v };
    }

    // Диапазон: "5-11" (разрешаем пробелы вокруг дефиса)
    const range = text.match(/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/);
    if (range) {
      const from = Number(range[1]);
      const to = Number(range[2]);
      if (!Number.isFinite(from) || !Number.isFinite(to)) return null;
      return { from, to };
    }

    return null;
  }


  function validateGradesBySubject() {
    if (!gradesInput) return { ok: true };
    const subject = subjectSelect?.value || '';
    const bounds = getGradeBoundsBySubject(subject);
    const range = parseGradesRange(gradesInput.value);

    if (!range) {
      return { ok: false, message: 'Введите класс (например 5) или диапазон (например 5-11).' };
    }
    if (range.from > range.to) {
      return { ok: false, message: 'Нижняя граница классов не может быть больше верхней.' };
    }
    if (range.from < bounds.min) {
      return { ok: false, message: `Для выбранного предмета минимальный класс — ${bounds.min}.` };
    }
    if (range.to > bounds.max) {
      return { ok: false, message: `Максимальный класс — ${bounds.max}.` };
    }
    return { ok: true };
  }

  subjectSelect?.addEventListener('change', () => {
    updateGradesHint();
    const v = validateGradesBySubject();
    gradesInput?.setCustomValidity(v.ok ? '' : (v.message || 'Некорректный диапазон классов'));
  });

  gradesInput?.addEventListener('input', () => {
    const v = validateGradesBySubject();
    gradesInput.setCustomValidity(v.ok ? '' : (v.message || 'Некорректный диапазон классов'));
  });

  function updateGradesHint() {
    if (!gradesInput) return;
    const subject = subjectSelect?.value || '';
    const { min, max } = getGradeBoundsBySubject(subject);
    gradesInput.placeholder = `${min}-${max}`;
  }


  document.getElementById('create-olympiad')?.addEventListener('click', () => {
    form?.reset();
    const startInput = form.querySelector('input[name="window_start"]');
    const endInput = form.querySelector('input[name="window_end"]');

    const now = new Date();
    now.setDate(now.getDate() + 1);
    const tomorrow = now.toISOString().slice(0, 16);

    if (startInput) startInput.min = tomorrow;
    if (endInput) endInput.min = tomorrow;

    updateGradesHint();
    modal?.classList.add('open');
    document.body.classList.add('no-scroll');
  });

  document.getElementById('modal-close')?.addEventListener('click', () => {
    if (form) {
      const hasData = Array.from(form.elements).some(el => el.value && el.type !== 'submit');
      if (hasData && !confirm('Вы уверены, что хотите закрыть форму? Данные будут потеряны.')) return;
    }
    modal?.classList.remove('open');
    document.body.classList.remove('no-scroll');
  });

  modal?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
      if (form) {
        const hasData = Array.from(form.elements).some(el => el.value && el.type !== 'submit');
        if (hasData && !confirm('Вы уверены, что хотите закрыть форму? Данные будут потеряны.')) return;
      }
      modal.classList.remove('open');
      document.body.classList.remove('no-scroll');
    }
  });

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const check = validateGradesBySubject();     
    if (!check.ok) {                             
      alert(check.message || 'Некорректно указаны классы участников');
      return;
    }

    const ws = form.querySelector('input[name="window_start"]')?.value;
    const we = form.querySelector('input[name="window_end"]')?.value;

    if (!ws || !we) {
      alert('Укажите допустимый диапазон проведения');
      return;
    }

    if (new Date(ws) > new Date(we)) {
      alert('Начало диапазона не может быть позже конца');
      return;
    }


    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());

    try {
      const res = await fetch('api/create-olympiad.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();

      if (data.success) {
        alert('Олимпиада успешно создана');
        modal?.classList.remove('open');
        document.body.classList.remove('no-scroll');
        form.reset();
        const activeCount = document.getElementById('active-count');
        if (activeCount) {
          const current = parseInt(activeCount.textContent) || 0;
          activeCount.textContent = current + 1;
        }
      } else {
        alert('Ошибка: ' + (data.error || 'Не удалось создать олимпиаду'));
      }
    } catch (err) {
      console.error(err);
      alert('Произошла ошибка при создании олимпиады');
    }
  });
});
