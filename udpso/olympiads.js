document.addEventListener('DOMContentLoaded', () => {
  const regionSelect = document.getElementById('region-select');
  const regionToggle = document.getElementById('region-toggle');
  const regionSearch = document.getElementById('region-search');
  const regionOptions = document.getElementById('region-options');
  const olympiadList = document.getElementById('olympiad-list');
  const emptyState = document.getElementById('olympiad-empty');

  if (!regionSelect || !regionToggle || !regionSearch || !regionOptions || !olympiadList) {
    return;
  }

  const role = localStorage.getItem('userRole');
  const canSeeAvailableSynonym = (role === 'school' || role === 'school_coordinator' || role === 'organizer');


  let currentRegion = '';
  let isLoading = false;
  const defaultEmptyText = emptyState ? emptyState.textContent.trim() : '';

  const regions = [
    'Все регионы',
    'Республика Адыгея',
    'Республика Алтай',
    'Республика Башкортостан',
    'Республика Бурятия',
    'Республика Дагестан',
    'Республика Ингушетия',
    'Кабардино-Балкарская Республика',
    'Республика Калмыкия',
    'Карачаево-Черкесская Республика',
    'Республика Карелия',
    'Республика Коми',
    'Республика Крым',
    'Республика Марий Эл',
    'Республика Мордовия',
    'Республика Саха (Якутия)',
    'Республика Северная Осетия — Алания',
    'Республика Татарстан',
    'Республика Тыва',
    'Удмуртская Республика',
    'Республика Хакасия',
    'Чеченская Республика',
    'Чувашская Республика',
    'Алтайский край',
    'Забайкальский край',
    'Камчатский край',
    'Краснодарский край',
    'Красноярский край',
    'Пермский край',
    'Приморский край',
    'Ставропольский край',
    'Хабаровский край',
    'Амурская область',
    'Архангельская область',
    'Астраханская область',
    'Белгородская область',
    'Брянская область',
    'Владимирская область',
    'Волгоградская область',
    'Вологодская область',
    'Воронежская область',
    'Ивановская область',
    'Иркутская область',
    'Калининградская область',
    'Калужская область',
    'Кемеровская область — Кузбасс',
    'Кировская область',
    'Костромская область',
    'Курганская область',
    'Курская область',
    'Ленинградская область',
    'Липецкая область',
    'Магаданская область',
    'Московская область',
    'Мурманская область',
    'Нижегородская область',
    'Новгородская область',
    'Новосибирская область',
    'Омская область',
    'Оренбургская область',
    'Орловская область',
    'Пензенская область',
    'Псковская область',
    'Ростовская область',
    'Рязанская область',
    'Самарская область',
    'Саратовская область',
    'Сахалинская область',
    'Свердловская область',
    'Смоленская область',
    'Тамбовская область',
    'Тверская область',
    'Томская область',
    'Тульская область',
    'Тюменская область',
    'Ульяновская область',
    'Челябинская область',
    'Ярославская область',
    'Москва',
    'Санкт-Петербург',
    'Севастополь',
    'Еврейская автономная область',
    'Ненецкий автономный округ',
    'Ханты-Мансийский автономный округ — Югра',
    'Чукотский автономный округ',
    'Ямало-Ненецкий автономный округ',
    'Донецкая Народная Республика',
    'Луганская Народная Республика',
    'Запорожская область',
    'Херсонская область'
  ];

  function renderRegionOptions(filterText = '') {
    const normalizedFilter = filterText.trim().toLowerCase();
    const options = regions.filter(region => {
      if (!normalizedFilter) return true;
      return region.toLowerCase().includes(normalizedFilter);
    });

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

  function mapStatus(status) {
    switch (status) {
      case 'upcoming':
        return canSeeAvailableSynonym ? 'Доступно для проведения' : 'Ожидается';
      case 'ongoing':
        return 'В процессе';
      default:
        return 'Неизвестно';
    }
  }

  function setEmptyState(message, isVisible) {
    if (!emptyState) return;
    if (message) {
      emptyState.textContent = message;
    } else {
      emptyState.textContent = defaultEmptyText;
    }
    emptyState.classList.toggle('hidden', !isVisible);
  }

  function formatDate(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString('ru-RU');
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
      setEmptyState('', true);
      return;
    }

    setEmptyState('', false);

    items.forEach(olympiad => {
      const card = document.createElement('div');
      card.className = 'olympiad-card';
      card.dataset.region = olympiad.region || '';
      card.innerHTML = `
        <div class="olympiad-card__header">
          <div>
            <p class="olympiad-card__school">${olympiad.school_name || 'Школа не указана'}</p>
          </div>
          <span class="status-tag status-${olympiad.status}">${mapStatus(olympiad.status)}</span>
        </div>
        <div class="olympiad-card__meta">
          <div>
            <span class="olympiad-card__label">Регион</span>
            <span>${olympiad.region || 'Не указан'}</span>
          </div>
          <div>
            <span class="olympiad-card__label">Предмет</span>
            <span>${olympiad.subject}</span>
          </div>
          <div>
            <span class="olympiad-card__label">Классы</span>
            <span>${olympiad.grades}</span>
          </div>
          <div>
            <span class="olympiad-card__label">Дата проведения</span>
            <span>${formatDate(olympiad.datetime)}</span>
          </div>
        </div>
        <div class="olympiad-card__footer">
          <button class="btn">Подробнее</button>
        </div>
      `;

      const button = card.querySelector('button');
      if (button) {
        button.addEventListener('click', () => {
          window.location.href = `olympiad-detail.html?id=${olympiad.id}&mode=public`;
        });
      }

      olympiadList.appendChild(card);
    });
  }

  async function loadOlympiads() {
    if (isLoading) return;
    if (!currentRegion) {
      olympiadList.innerHTML = '';
      setEmptyState('Выберите регион для просмотра олимпиад.', true);
      return;
    }
    setLoading(true);

    try {
      await fetch('api/update-olympiad-statuses.php');
      const regionParam = encodeURIComponent(currentRegion);
      const response = await fetch(`api/get-public-olympiads.php?region=${regionParam}`);
      const data = await response.json();
      if (!response.ok || data.success === false) {
        throw new Error(data.error || 'Ошибка загрузки данных');
      }
      renderOlympiads(data.olympiads || []);
    } catch (error) {
      console.error('Ошибка загрузки олимпиад:', error);
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

  document.addEventListener('click', (event) => {
    if (!regionSelect.contains(event.target)) {
      regionSelect.classList.remove('open');
    }
  });

  renderRegionOptions();
  loadOlympiads();
});
