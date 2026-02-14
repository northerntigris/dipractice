document.addEventListener('DOMContentLoaded', async () => {
  const welcome = document.getElementById('welcome-message');
  try {
    const res = await fetch('api/get-user-info.php');
    const user = await res.json();
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

  const addCoordinatorBtn = document.getElementById('add-coordinator');
  const manageUsersBtn = document.getElementById('manage-users');
  const role = localStorage.getItem('userRole');
  if (role === 'school_coordinator') {
    if (addCoordinatorBtn) addCoordinatorBtn.style.display = 'none';
    if (manageUsersBtn) manageUsersBtn.style.display = 'none';
      const totalCoords = document.getElementById('total-coordinators');
      const totalCoordsItem = totalCoords?.closest('.stat-item');
      if (totalCoordsItem) totalCoordsItem.style.display = 'none';
  }


  async function loadDashboardStats() {
    try {
      const res = await fetch('api/get-school-dashboard-stats.php');
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Ошибка загрузки статистики');
      }

      const totalEl = document.getElementById('total-coordinators');
      const activeEl = document.getElementById('active-olympiads');
      const completedEl = document.getElementById('completed-olympiads');

      if (totalEl) totalEl.textContent = String(data.total_coordinators ?? 0);
      if (activeEl) activeEl.textContent = String(data.active_olympiads ?? 0);
      if (completedEl) completedEl.textContent = String(data.completed_olympiads ?? 0);
    } catch (error) {
      console.error('Ошибка загрузки статистики школы:', error);
    }
  }

  const modal = document.getElementById('add-coordinator-modal');
  const closeModal = document.getElementById('close-coordinator-modal');
  const form = document.getElementById('add-coordinator-form');

  loadDashboardStats();
  loadRecentActivity();

  document.getElementById('add-olympiad')?.addEventListener('click', () => {
    window.location.href = 'all-olympiads.html?mode=choose';
  });

  document.getElementById('view-olympiads')?.addEventListener('click', () => {
    window.location.href = 'all-olympiads.html';
  });


    // --- СНИЛС: маска + валидация ---
  const snilsInput = document.getElementById('coordinator-snils');

  function snilsFormat(value) {
    const digits = String(value || '').replace(/\D+/g, '').slice(0, 11);
    // формат: XXX-XXX-XXX XX
    const p1 = digits.slice(0, 3);
    const p2 = digits.slice(3, 6);
    const p3 = digits.slice(6, 9);
    const p4 = digits.slice(9, 11);

    let out = p1;
    if (p2) out += '-' + p2;
    if (p3) out += '-' + p3;
    if (p4) out += ' ' + p4;
    return out;
  }

  snilsInput?.addEventListener('input', () => {
    const before = snilsInput.value;
    snilsInput.value = snilsFormat(before);

    // не идеально “умно”, но достаточно: курсор в конец, чтобы не бесило при вводе
    snilsInput.setSelectionRange(snilsInput.value.length, snilsInput.value.length);

    // чистим сообщение об ошибке, пока человек набирает
    snilsInput.setCustomValidity('');
  });

  snilsInput?.addEventListener('blur', () => {
    const digits = snilsInput.value.replace(/\D+/g, '');
    if (digits.length !== 11) {
      snilsInput.setCustomValidity('СНИЛС должен содержать 11 цифр');
    } else {
      snilsInput.setCustomValidity('');
    }
    snilsInput.reportValidity();
  });


  addCoordinatorBtn?.addEventListener('click', () => {
    if (modal) {
      modal.style.display = 'flex';
      document.body.classList.add('no-scroll');
    }
  });

  manageUsersBtn?.addEventListener('click', () => {
    window.location.href = 'school-users.html';
  });

  document.getElementById('settings')?.addEventListener('click', () => {
    window.location.href = 'settings.html';
  });

  closeModal?.addEventListener('click', () => {
    if (modal) {
      modal.style.display = 'none';
      document.body.classList.remove('no-scroll');
    }
  });

  modal?.addEventListener('click', (event) => {
    if (event.target === modal) {
      modal.style.display = 'none';
      document.body.classList.remove('no-scroll');
    }
  });

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const snilsDigits = document.getElementById('coordinator-snils').value.replace(/\D+/g, '');
    if (snilsDigits.length !== 11) {
      alert('Проверьте СНИЛС: нужно 11 цифр в формате 000-000-000 00');
      return;
    }

    const payload = {
      full_name: document.getElementById('coordinator-fio').value,
      position: document.getElementById('coordinator-position').value,
      snils: document.getElementById('coordinator-snils').value.replace(/\D+/g,''),
      email: document.getElementById('coordinator-email').value
    };

    try {
      const response = await fetch('api/add-coordinator.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await response.json();

      if (!data.success) {
        alert(data.error || 'Не удалось добавить координатора');
        return;
      }

      alert('Информация отправлена на почту');
      form.reset();
      modal.style.display = 'none';
      document.body.classList.remove('no-scroll');
    } catch (error) {
      console.error('Ошибка добавления координатора:', error);
      alert('Произошла ошибка при добавлении координатора.');
    }
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
          <div class="activity-icon">
            <i class="fas fa-info-circle"></i>
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
});
