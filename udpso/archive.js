const ARCHIVE_REGIONS = [
  'Все регионы', 'Республика Адыгея', 'Республика Алтай', 'Республика Башкортостан', 'Республика Бурятия',
  'Республика Дагестан', 'Республика Ингушетия', 'Кабардино-Балкарская Республика', 'Республика Калмыкия',
  'Карачаево-Черкесская Республика', 'Республика Карелия', 'Республика Коми', 'Республика Крым',
  'Республика Марий Эл', 'Республика Мордовия', 'Республика Саха (Якутия)', 'Республика Северная Осетия — Алания',
  'Республика Татарстан', 'Республика Тыва', 'Удмуртская Республика', 'Республика Хакасия', 'Чеченская Республика',
  'Чувашская Республика', 'Алтайский край', 'Забайкальский край', 'Камчатский край', 'Краснодарский край',
  'Красноярский край', 'Пермский край', 'Приморский край', 'Ставропольский край', 'Хабаровский край',
  'Амурская область', 'Архангельская область', 'Астраханская область', 'Белгородская область', 'Брянская область',
  'Владимирская область', 'Волгоградская область', 'Вологодская область', 'Воронежская область', 'Ивановская область',
  'Иркутская область', 'Калининградская область', 'Калужская область', 'Кемеровская область — Кузбасс',
  'Кировская область', 'Костромская область', 'Курганская область', 'Курская область', 'Ленинградская область',
  'Липецкая область', 'Магаданская область', 'Московская область', 'Мурманская область', 'Нижегородская область',
  'Новгородская область', 'Новосибирская область', 'Омская область', 'Оренбургская область', 'Орловская область',
  'Пензенская область', 'Псковская область', 'Ростовская область', 'Рязанская область', 'Самарская область',
  'Саратовская область', 'Сахалинская область', 'Свердловская область', 'Смоленская область', 'Тамбовская область',
  'Тверская область', 'Томская область', 'Тульская область', 'Тюменская область', 'Ульяновская область',
  'Челябинская область', 'Ярославская область', 'Москва', 'Санкт-Петербург', 'Севастополь',
  'Еврейская автономная область', 'Ненецкий автономный округ', 'Ханты-Мансийский автономный округ — Югра',
  'Чукотский автономный округ', 'Ямало-Ненецкий автономный округ', 'Донецкая Народная Республика',
  'Луганская Народная Республика', 'Запорожская область', 'Херсонская область'
];

document.addEventListener('DOMContentLoaded', () => {
  const regionSelect = document.getElementById('archive-region-select');
  const regionToggle = document.getElementById('archive-region-toggle');
  const regionSearch = document.getElementById('archive-region-search');
  const regionOptions = document.getElementById('archive-region-options');
  const schoolFilterInput = document.getElementById('archive-school-filter');
  const classesFilterInput = document.getElementById('archive-classes-filter');
  const datetimeFilterInput = document.getElementById('archive-datetime-filter');
  const olympiadList = document.getElementById('archive-list');
  const emptyState = document.getElementById('archive-empty');

  if (!regionSelect || !regionToggle || !regionSearch || !regionOptions || !olympiadList) {
    return;
  }

  let currentRegion = '';
  let isLoading = false;
  let allOlympiads = [];
  const defaultEmptyText = emptyState ? emptyState.textContent.trim() : '';

  function renderRegionOptions(filterText = '') {
    const normalizedFilter = filterText.trim().toLowerCase();
    const options = ARCHIVE_REGIONS.filter(region => region.toLowerCase().includes(normalizedFilter));
    regionOptions.innerHTML = '';

    const label = document.createElement('li');
    label.className = 'region-select__label';
    label.textContent = 'Выберите регион';
    regionOptions.appendChild(label);

    options.forEach(region => {
      const li = document.createElement('li');
      li.className = 'region-select__option';
      li.dataset.value = region === 'Все регионы' ? 'all' : region;
      li.textContent = region;
      if (currentRegion === li.dataset.value) {
        li.classList.add('active');
      }
      li.addEventListener('click', () => {
        currentRegion = li.dataset.value;
        regionToggle.innerHTML = `${region}<i class="fas fa-chevron-down"></i>`;
        regionSelect.classList.remove('open');
        regionSearch.value = '';
        renderRegionOptions();
        loadOlympiads();
      });
      regionOptions.appendChild(li);
    });
  }

  function setEmptyState(message, isVisible) {
    if (!emptyState) return;
    emptyState.textContent = message || defaultEmptyText;
    emptyState.classList.toggle('hidden', !isVisible);
  }

  function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString('ru-RU');
  }

  function normalizeDateForInput(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');

    return `${year}-${month}-${day}T${hours}:${minutes}`;
  }

  function setLoading(state) {
    isLoading = state;
    if (state) {
      olympiadList.innerHTML = '<p class="loading">Загрузка...</p>';
      setEmptyState('', false);
    }
  }

  function renderOlympiads(items = []) {
    olympiadList.innerHTML = '';

    if (!items.length) {
      setEmptyState('По заданным фильтрам ничего не найдено.', true);
      return;
    }

    setEmptyState('', false);

    items.forEach(olympiad => {
      const card = document.createElement('div');
      card.className = 'olympiad-card archive-card';
      card.dataset.region = olympiad.region || '';

      const schoolParam = olympiad.school_id ? `&school_id=${encodeURIComponent(olympiad.school_id)}` : '';
      const reportUrl = `api/publish-school-results.php?id=${encodeURIComponent(olympiad.id)}&view=1${schoolParam}`;
      card.innerHTML = `
        <div class="olympiad-card__header archive-card__header">
          <div class="archive-card__title-block">
            <p class="olympiad-card__school archive-card__subject">${olympiad.subject || 'Без названия'}</p>
            <span class="archive-card__status">Архив</span>
          </div>
          <a class="archive-report-link" href="${reportUrl}" target="_blank" rel="noopener">
            <i class="fas fa-file-pdf"></i>
            <span>PDF отчёт</span>
          </a>
        </div>
        <div class="olympiad-card__meta archive-card__meta">
          <span class="archive-meta-item"><span class="archive-meta-label">Регион:</span><span class="archive-meta-value">${olympiad.region || 'Не указан'}</span></span>
          <span class="archive-meta-item"><span class="archive-meta-label">Школа:</span><span class="archive-meta-value">${olympiad.school_name || 'Школа не указана'}</span></span>
          <span class="archive-meta-item"><span class="archive-meta-label">Классы:</span><span class="archive-meta-value">${olympiad.grades || '—'}</span></span>
          <span class="archive-meta-item"><span class="archive-meta-label">Дата:</span><span class="archive-meta-value">${formatDate(olympiad.datetime)}</span></span>
        </div>
      `;

      olympiadList.appendChild(card);
    });
  }

  function applyLocalFilters() {
    const schoolFilter = (schoolFilterInput?.value || '').trim().toLowerCase();
    const classesFilter = (classesFilterInput?.value || '').trim().toLowerCase();
    const datetimeFilter = (datetimeFilterInput?.value || '').trim();

    const filtered = allOlympiads.filter((olympiad) => {
      const schoolName = (olympiad.school_name || '').toLowerCase();
      const grades = (olympiad.grades || '').toLowerCase();
      const olympiadDateTime = normalizeDateForInput(olympiad.datetime);

      if (schoolFilter && !schoolName.includes(schoolFilter)) {
        return false;
      }

      if (classesFilter && !grades.includes(classesFilter)) {
        return false;
      }

      if (datetimeFilter && olympiadDateTime !== datetimeFilter) {
        return false;
      }

      return true;
    });

    renderOlympiads(filtered);
  }

  async function loadOlympiads() {
    if (isLoading) return;

    if (!currentRegion) {
      olympiadList.innerHTML = '';
      setEmptyState('Выберите регион, чтобы показать архив олимпиад.', true);
      return;
    }

    setLoading(true);

    try {
      const regionParam = encodeURIComponent(currentRegion || 'all');
      const response = await fetch(`api/get-archived-olympiads.php?region=${regionParam}`);
      const data = await response.json();
      if (!response.ok || data.success === false) {
        throw new Error(data.error || 'Ошибка загрузки данных');
      }
      allOlympiads = data.olympiads || [];
      applyLocalFilters();
    } catch (error) {
      console.error('Ошибка загрузки архива:', error);
      olympiadList.innerHTML = '<p class="error-message">Ошибка загрузки данных.</p>';
      setEmptyState('', false);
    } finally {
      setLoading(false);
    }
  }

  regionToggle.addEventListener('click', () => {
    regionSelect.classList.toggle('open');
    if (regionSelect.classList.contains('open')) {
      regionSearch.focus();
    }
  });

  regionSearch.addEventListener('input', (event) => {
    renderRegionOptions(event.target.value);
  });

  schoolFilterInput?.addEventListener('input', applyLocalFilters);
  classesFilterInput?.addEventListener('input', applyLocalFilters);
  datetimeFilterInput?.addEventListener('change', applyLocalFilters);

  document.addEventListener('click', (event) => {
    if (!regionSelect.contains(event.target)) {
      regionSelect.classList.remove('open');
    }
  });

  regionToggle.innerHTML = 'Выберите регион<i class="fas fa-chevron-down"></i>';
  renderRegionOptions();
  setEmptyState('Выберите регион, чтобы показать архив олимпиад.', true);
});
