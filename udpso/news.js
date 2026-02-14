document.addEventListener('DOMContentLoaded', () => {
  const regionSelect = document.getElementById('news-region-select');
  const regionToggle = document.getElementById('news-region-toggle');
  const regionSearch = document.getElementById('news-region-search');
  const regionOptions = document.getElementById('news-region-options');

  if (!regionSelect || !regionToggle || !regionSearch || !regionOptions) return;

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

  const renderOptions = (filter = '') => {
    const normalizedFilter = filter.trim().toLowerCase();
    const options = regions.filter(region => region.toLowerCase().includes(normalizedFilter));
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
      li.addEventListener('click', () => {
        const selectedRegion = region === 'Все регионы' ? 'all' : region;
        regionToggle.innerHTML = `${region}<i class="fas fa-chevron-down"></i>`;
        regionSelect.classList.remove('open');
        regionSearch.value = '';
        renderOptions();
        document.dispatchEvent(new CustomEvent('news-region-change', {
          detail: { region: selectedRegion, explicit: true }
        }));
      });
      regionOptions.appendChild(li);
    });
  };

  renderOptions();
  document.dispatchEvent(new CustomEvent('news-region-change', {
    detail: { region: 'all' }
  }));

  regionToggle.addEventListener('click', () => {
    regionSelect.classList.toggle('open');
    if (regionSelect.classList.contains('open')) {
      regionSearch.focus();
    }
  });

  regionSearch.addEventListener('input', (event) => {
    renderOptions(event.target.value);
  });

  document.addEventListener('click', (event) => {
    if (!regionSelect.contains(event.target)) {
      regionSelect.classList.remove('open');
    }
  });
});
