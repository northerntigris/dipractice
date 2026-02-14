document.addEventListener('DOMContentLoaded', async () => {
  const params = new URLSearchParams(window.location.search);
  const pageMode = params.get('mode'); // choose
  const role = localStorage.getItem('userRole');
  const isSchoolRole = role === 'school' || role === 'school_coordinator';
  const canSeeAvailableSynonym = (isSchoolRole || role === 'organizer');
  const isChooseForSchool = pageMode === 'choose' && (role === 'school' || role === 'school_coordinator');

  if (sessionStorage.getItem('school_setup_saved') === '1') {
    sessionStorage.removeItem('school_setup_saved');
    alert('Олимпиада сохранена и добавлена в ваши олимпиады.');
  }

  const list = document.getElementById('olympiads-list');
  const filter = document.getElementById('status-filter');
  if (filter && canSeeAvailableSynonym) {
    const opt = filter.querySelector('option[value="upcoming"]');
    if (opt) opt.textContent = 'Доступно для проведения';
  }
  const limitSelect = document.getElementById('limit-select');
  const pagination = document.getElementById('pagination');
  const sortBtn = document.getElementById('sort-toggle');

  let olympiads = [];
  let currentPage = 1;
  let limit = parseInt(limitSelect.value);
  let currentSort = 'desc';

  const normalizeOlympiads = (data) => {
    if (Array.isArray(data)) return data;
    if (data && Array.isArray(data.olympiads)) return data.olympiads;
    return [];
  };

  const olympiadsEndpoint = isSchoolRole
    ? 'api/get-school-available-olympiads.php'
    : 'api/get-olympiads.php';

  // Обновляем статусы при входе и каждые 60 секунд
  await fetch('api/update-olympiad-statuses.php');
  setInterval(async () => {
    await fetch('api/update-olympiad-statuses.php');
    try {
      const res = await fetch(olympiadsEndpoint);
      const data = await res.json();

      olympiads = normalizeOlympiads(data);
      renderPaginatedOlympiads();
    } catch (e) {
      console.error('Ошибка обновления списка олимпиад:', e);
    }
  }, 60000);

  try {
    const res = await fetch(olympiadsEndpoint);
    const data = await res.json();
    olympiads = normalizeOlympiads(data);
    renderPaginatedOlympiads();
  } catch (e) {
    console.error(e);
    list.innerHTML = '<p class="error-message">Ошибка загрузки данных.</p>';
  }

  filter.addEventListener('change', () => {
    currentPage = 1;
    renderPaginatedOlympiads();
  });

  limitSelect.addEventListener('change', () => {
    limit = parseInt(limitSelect.value);
    currentPage = 1;
    renderPaginatedOlympiads();
  });

  sortBtn.addEventListener('click', () => {
    currentSort = currentSort === 'asc' ? 'desc' : 'asc';
    sortBtn.innerHTML = currentSort === 'asc'
      ? '<i class="fas fa-sort-amount-up-alt"></i> Сначала старые'
      : '<i class="fas fa-sort-amount-down-alt"></i> Сначала новые';
    renderPaginatedOlympiads();
  });

  function renderPaginatedOlympiads() {
    const value = filter.value;
    const baseList = Array.isArray(olympiads) ? olympiads : [];
    let filtered = value === 'all' ? baseList : baseList.filter(o => o.status === value);
    if (isSchoolRole) {
      if (isChooseForSchool) {
        filtered = filtered.filter(o => !isSchoolInstance(o));
      } else {
        filtered = filtered.filter(o => isSchoolOwned(o));
      }
    }

    const getSortDate = (o) => o.window_start || o.datetime || o.created_at;
    filtered.sort((a, b) => currentSort === 'asc'
      ? new Date(getSortDate(a)) - new Date(getSortDate(b))
      : new Date(getSortDate(b)) - new Date(getSortDate(a)));

    const totalPages = Math.ceil(filtered.length / limit);
    const start = (currentPage - 1) * limit;
    const end = start + limit;
    const paginated = filtered.slice(start, end);

    renderOlympiads(paginated);
    renderPagination(totalPages);
  }

  function parseGradesToSet(gradesStr) {
    const set = new Set();
    const s = String(gradesStr || '').replace(/\s+/g, '');
    if (!s) return set;

    s.split(/[;,]+/).forEach(part => {
      if (!part) return;
      if (part.includes('-')) {
        const [a, b] = part.split('-').map(x => parseInt(x, 10));
        if (Number.isFinite(a) && Number.isFinite(b)) {
          const from = Math.min(a, b);
          const to = Math.max(a, b);
          for (let g = from; g <= to; g++) set.add(g);
        }
      } else {
        const g = parseInt(part, 10);
        if (Number.isFinite(g)) set.add(g);
      }
    });

    return set;
  }

  function hasGradesOverlap(a, b) {
    for (const grade of a) {
      if (b.has(grade)) return true;
    }
    return false;
  }

  function isSchoolInstance(o) {
    return Number(o?.school_id) > 0;
  }

  function isSchoolOwned(o) {
    return Boolean(o?.is_owned_by_school);
  }

  function hasSchoolSchedule(o) {
    return Boolean(o?.school_scheduled_at);
  }

  function renderDateInfo(o) {
    if (isSchoolInstance(o) && o.datetime) {
      return new Date(o.datetime).toLocaleString();
    }
    if (isSchoolOwned(o) && o.school_scheduled_at) {
      return new Date(o.school_scheduled_at).toLocaleString();
    }
    if (o.window_start && o.window_end) {
      return `${new Date(o.window_start).toLocaleString()} — ${new Date(o.window_end).toLocaleString()}`;
    }
    if (hasSchoolSchedule(o)) {
      return new Date(o.school_scheduled_at).toLocaleString();
    }
    return o.datetime ? new Date(o.datetime).toLocaleString() : '—';
  }

  function renderOlympiads(data) {
    list.innerHTML = '';

    if (!data || data.length === 0) {
      list.innerHTML = '<div class="empty-state"><i class="fas fa-info-circle"></i><p>Нет созданных олимпиад.</p></div>';
      return;
    }

    data.forEach(o => {
      const item = document.createElement('li');
      item.className = `olympiad-row card-${o.status}`;
      item.innerHTML = `
        <div class="olympiad-row-content">
          <div>
            <div class="olympiad-meta">
              <span><i class="fas fa-book"></i> ${o.subject}</span>
              <span><i class="fas fa-calendar-alt"></i> ${renderDateInfo(o)}</span>

              <span><i class="fas fa-user-graduate"></i> ${(o.already_chosen && o.school_grades) ? o.school_grades : o.grades}</span>
            </div>
            <span class="status-tag status-${o.status}">${mapStatus(o)}</span>
          </div>
          <div class="olympiad-arrow"><i class="fas fa-arrow-right"></i></div>
        </div>
      `;
      item.addEventListener('click', () => {
        const isOrganizerRole = role === 'organizer';
        const targetMode = isChooseForSchool
          ? 'school'
          : (isSchoolRole ? 'school' : (isOrganizerRole ? 'organizer' : 'public'));
        const shouldSetup = isChooseForSchool && !isSchoolInstance(o);

        // если пришли из "назначить проведение" — открываем detail в режиме setup
        const setupParam = shouldSetup ? '&setup=1' : '';

        window.location.href = `olympiad-detail.html?id=${o.id}&mode=${targetMode}${setupParam}`;
      });

      list.appendChild(item);
    });
  }

  function renderPagination(totalPages) {
    pagination.innerHTML = '';
    if (totalPages <= 1) return;

    for (let i = 1; i <= totalPages; i++) {
      const btn = document.createElement('button');
      btn.className = `page-btn${i === currentPage ? ' active' : ''}`;
      btn.textContent = i;
      btn.addEventListener('click', () => {
        currentPage = i;
        renderPaginatedOlympiads();
      });
      pagination.appendChild(btn);
    }
  }

  function mapStatus(o) {
    const status = o?.status;

    switch (status) {
      case 'upcoming': {
        const isTemplate = !isSchoolInstance(o);
        const isSchoolScheduled = hasSchoolSchedule(o) || isSchoolOwned(o);
        if (role === 'organizer') return 'Доступно для проведения';
        if (isSchoolRole) {
          if (!isTemplate || isSchoolScheduled) return 'Ожидается';
          return 'Доступно для проведения';
        }
        return 'Ожидается';
      }
      case 'ongoing': return 'В процессе';
      case 'completed': return 'Завершена';
      case 'archived': return 'Архив';
      case 'cancelled': return 'Отменена';
      default: return 'Неизвестно';
    }
  }

});
