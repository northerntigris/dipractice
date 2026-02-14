function formatDateTime(value) {
  if (!value) return '-';
  return String(value).split('.')[0].replace('T', ' ');
}

function escapeHtml(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

document.addEventListener('DOMContentLoaded', function () {
  var tableBody = document.getElementById('school-users-body');
  var editModal = document.getElementById('edit-user-modal');
  var editModalClose = document.getElementById('edit-user-modal-close');
  var editForm = document.getElementById('edit-user-form');
  var editUserIdInput = document.getElementById('edit-user-id');
  var editUserFullName = document.getElementById('edit-user-full-name');
  var editUserEmail = document.getElementById('edit-user-email');

  if (!tableBody) return;

  var users = [];

  function openEditModal(user) {
    if (!editModal || !editForm) return;
    editUserIdInput.value = String(user.id || '');
    editUserFullName.value = user.full_name || '';
    editUserEmail.value = user.email || '';
    editModal.classList.add('open');
    document.body.classList.add('no-scroll');
  }

  function closeEditModal() {
    if (!editModal) return;
    editModal.classList.remove('open');
    document.body.classList.remove('no-scroll');
    if (editForm) editForm.reset();
    if (editUserIdInput) editUserIdInput.value = '';
  }

  async function loadUsers() {
    try {
      const response = await fetch('api/get-school-users.php');
      const data = await response.json();

      if (!data.success) {
        tableBody.innerHTML =
          '<tr><td colspan="5" style="text-align:center; padding:12px;">Ошибка: ' +
          escapeHtml(data.error || 'Не удалось загрузить данные') +
          '</td></tr>';
        return;
      }

      users = data.users || [];

      if (users.length === 0) {
        tableBody.innerHTML =
          '<tr><td colspan="5" style="text-align:center; padding:12px;">Нет назначенных координаторов</td></tr>';
        return;
      }

      tableBody.innerHTML = '';
      users.forEach(function (user) {
        var row = document.createElement('tr');
        row.innerHTML =
          '<td>' + escapeHtml(user.full_name || '-') + '</td>' +
          '<td>' + escapeHtml(user.email || '-') + '</td>' +
          '<td>' + escapeHtml(user.role === 'school_coordinator' ? 'школьный координатор' : (user.role || '-')) + '</td>' +
          '<td>' + escapeHtml(formatDateTime(user.created_at)) + '</td>' +
          '<td>' +
            '<div class="table-actions">' +
              '<button class="table-action-btn action-edit" type="button" title="Редактировать" aria-label="Редактировать" data-action="edit" data-id="' + Number(user.id) + '">' +
                '<i class="fas fa-pen"></i>' +
              '</button>' +
              '<button class="table-action-btn action-delete" type="button" title="Удалить" aria-label="Удалить" data-action="delete" data-id="' + Number(user.id) + '">' +
                '<i class="fas fa-trash"></i>' +
              '</button>' +
            '</div>' +
          '</td>';
        tableBody.appendChild(row);
      });
    } catch (error) {
      console.error('Ошибка загрузки пользователей:', error);
      tableBody.innerHTML =
        '<tr><td colspan="5" style="text-align:center; padding:12px;">Ошибка загрузки</td></tr>';
    }
  }

  async function submitEditUser() {
    var userId = Number(editUserIdInput.value || 0);
    var fullName = (editUserFullName.value || '').trim();
    var email = (editUserEmail.value || '').trim();

    if (!userId || !fullName || !email) {
      alert('Заполните обязательные поля');
      return;
    }

    try {
      const response = await fetch('api/update-school-user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          user_id: userId,
          full_name: fullName,
          email: email
        })
      });

      const data = await response.json();

      if (!data.success) {
        alert(data.error || 'Не удалось обновить пользователя');
        return;
      }

      closeEditModal();
      await loadUsers();
      alert('Данные пользователя обновлены');
    } catch (error) {
      console.error('Ошибка редактирования пользователя:', error);
      alert('Произошла ошибка при редактировании');
    }
  }

  async function deleteUser(userId) {
    if (!confirm('Удалить координатора?')) return;

    try {
      const response = await fetch('api/delete-school-user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: Number(userId) })
      });

      const data = await response.json();

      if (!data.success) {
        alert(data.error || 'Не удалось удалить пользователя');
        return;
      }

      await loadUsers();
      alert('Координатор удалён');
    } catch (error) {
      console.error('Ошибка удаления пользователя:', error);
      alert('Произошла ошибка при удалении');
    }
  }

  tableBody.addEventListener('click', async function (event) {
    var btn = event.target.closest('button[data-action][data-id]');
    if (!btn) return;

    var userId = Number(btn.dataset.id || 0);
    if (userId <= 0) return;

    if (btn.dataset.action === 'edit') {
      var user = users.find(function (item) { return Number(item.id) === userId; });
      if (user) openEditModal(user);
      return;
    }

    if (btn.dataset.action === 'delete') {
      await deleteUser(userId);
    }
  });

  if (editForm) {
    editForm.addEventListener('submit', function (event) {
      event.preventDefault();
      submitEditUser();
    });
  }

  if (editModalClose) {
    editModalClose.addEventListener('click', closeEditModal);
  }

  if (editModal) {
    editModal.addEventListener('click', function (event) {
      if (event.target === editModal) closeEditModal();
    });
  }

  loadUsers();
});
