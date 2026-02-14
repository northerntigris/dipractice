document.addEventListener('DOMContentLoaded', async () => {
  const role = localStorage.getItem('userRole');
  if (role !== 'admin') {
    window.location.href = 'index.html';
    return;
  }

  document.getElementById('back-btn')?.addEventListener('click', () => {
    window.location.href = 'dashboard-admin.html';
  });

  const tbody = document.getElementById('users-body');
  const searchInput = document.getElementById('users-search');
  const table = document.querySelector('.participants-table');
  let users = [];
  let sortKey = 'name';
  let sortDir = 'asc';

  try {
    const res = await fetch('api/get-users.php');
    const data = await res.json();
    if (!data.success) {
      tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:12px;">${data.error || 'Ошибка загрузки'}</td></tr>`;
      return;
    }
    users = Array.isArray(data.users) ? data.users : [];
    renderUsers();
  } catch (err) {
    console.error(err);
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:12px;">Ошибка загрузки</td></tr>';
  }

  searchInput?.addEventListener('input', renderUsers);
  table?.querySelectorAll('thead th[data-sort]')?.forEach((th) => {
    th.addEventListener('click', () => {
      const key = th.getAttribute('data-sort');
      if (!key) return;
      if (sortKey === key) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        sortKey = key;
        sortDir = 'asc';
      }
      updateSortIndicators();
      renderUsers();
    });
  });

  function renderUsers() {
    const query = String(searchInput?.value || '').trim().toLowerCase();
    let filtered = users.slice();

    if (query) {
      filtered = filtered.filter(user => {
        const fullName = String(user.full_name || '').toLowerCase();
        const role = String(user.role || '').toLowerCase();
        const org = String(user.organization_name || '').toLowerCase();
        const region = String(user.region || '').toLowerCase();
        return fullName.includes(query) || role.includes(query) || org.includes(query) || region.includes(query);
      });
    }

    const getOrg = u => String(u.organization_name || '').toLowerCase();
    const getRegion = u => String(u.region || '').toLowerCase();
    const getName = u => String(u.full_name || '').toLowerCase();
    const getRole = u => String(u.role || '').toLowerCase();
    const dir = sortDir === 'asc' ? 1 : -1;

    switch (sortKey) {
      case 'name':
        filtered.sort((a, b) => dir * getName(a).localeCompare(getName(b), 'ru'));
        break;
      case 'role':
        filtered.sort((a, b) => dir * getRole(a).localeCompare(getRole(b), 'ru'));
        break;
      case 'org':
        filtered.sort((a, b) => dir * getOrg(a).localeCompare(getOrg(b), 'ru'));
        break;
      case 'region':
        filtered.sort((a, b) => dir * getRegion(a).localeCompare(getRegion(b), 'ru'));
        break;
      default:
        break;
    }

    if (!filtered.length) {
      tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:12px;">Нет данных</td></tr>';
      return;
    }

    tbody.innerHTML = filtered.map(user => `
      <tr>
        <td>${user.full_name || '—'}</td>
        <td>${user.role || '—'}</td>
        <td>${user.organization_name || '—'}</td>
        <td>${user.region || '—'}</td>
      </tr>
    `).join('');
  }

  function updateSortIndicators() {
    table?.querySelectorAll('thead th[data-sort]')?.forEach((th) => {
      const key = th.getAttribute('data-sort');
      const arrow = th.querySelector('.sort-arrow');
      if (!arrow || !key) return;
      if (key !== sortKey) {
        arrow.textContent = '↕';
        return;
      }
      arrow.textContent = sortDir === 'asc' ? '↑' : '↓';
    });
  }

  updateSortIndicators();
});
