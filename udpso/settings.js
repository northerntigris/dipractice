document.addEventListener('DOMContentLoaded', async () => {
  document.getElementById('back-btn')?.addEventListener('click', () => {
    const role = localStorage.getItem('userRole');

    if (role === 'organizer') {
      window.location.href = 'dashboard-organizer.html';
    } else if (role === 'expert') {
      window.location.href = 'dashboard-jury.html';
    } else if (role === 'admin' || role === 'moderator') {
      window.location.href = 'dashboard-admin.html';
    } else if (role === 'school' || role === 'school_coordinator') {
      window.location.href = 'dashboard-school.html';
    } else if (role === 'student') {
      window.location.href = 'dashboard-student.html';
    } else {
      window.location.href = 'index.html';
    }
  });


  // Ограничение по ролям (пока только school и school_coordinator)
  const role = localStorage.getItem('userRole');
  if (!['school', 'school_coordinator', 'organizer', 'admin', 'moderator', 'expert', 'student'].includes(role)) {
    window.location.href = 'index.html';
    return;
  }

  const form = document.getElementById('change-password-form');
  const emailForm = document.getElementById('email-link-form');
  const emailInput = document.getElementById('email-address');
  const emailDisplay = document.getElementById('current-email');
  const emailHelper = document.getElementById('email-helper');
  const emailCodeSection = document.getElementById('email-code-section');
  const emailCodeInput = document.getElementById('email-code');
  const confirmEmailBtn = document.getElementById('confirm-email-code');

  let pendingEmail = '';

  const loadEmail = async () => {
    try {
      const res = await fetch('api/get-user-email.php');
      const data = await res.json();
      if (!data.success) return;
      const currentEmail = data.email || '';
      if (emailDisplay) {
        emailDisplay.textContent = currentEmail || 'не привязан';
      }
      if (emailInput && !emailInput.value) {
        emailInput.value = currentEmail;
      }
    } catch (err) {
      console.warn('Не удалось загрузить email');
    }
  };

  await loadEmail();

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const currentPassword = document.getElementById('current-password').value;
    const newPassword = document.getElementById('new-password').value;
    const confirmPassword = document.getElementById('confirm-password').value;

    if (newPassword.length < 8) {
      alert('Новый пароль должен быть не короче 8 символов');
      return;
    }

    if (newPassword !== confirmPassword) {
      alert('Новый пароль и подтверждение не совпадают');
      return;
    }

    try {
      const res = await fetch('api/change-password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          current_password: currentPassword,
          new_password: newPassword
        })
      });

      const data = await res.json();

      if (!data.success) {
        alert(data.error || 'Не удалось сменить пароль');
        return;
      }

      alert('Пароль успешно изменён');
      form.reset();
    } catch (err) {
      console.error(err);
      alert('Ошибка сети при смене пароля');
    }
  });

  emailForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!emailInput) return;
    const email = emailInput.value.trim();

    if (!email) {
      alert('Введите email');
      return;
    }

    if (emailDisplay && emailDisplay.textContent === email) {
      alert('Этот email уже привязан');
      return;
    }

    if (emailHelper) {
      emailHelper.textContent = '';
    }

    try {
      const res = await fetch('api/request-email-verification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email })
      });
      const data = await res.json();

      if (!data.success) {
        alert(data.error || 'Не удалось отправить код');
        return;
      }

      pendingEmail = email;
      if (emailCodeSection) {
        emailCodeSection.hidden = false;
      }
      if (emailHelper) {
        emailHelper.textContent = 'Код отправлен. Проверьте почту и введите его ниже.';
      }
      emailCodeInput?.focus();
    } catch (err) {
      console.error(err);
      alert('Ошибка сети при отправке кода');
    }
  });

  confirmEmailBtn?.addEventListener('click', async () => {
    if (!emailCodeInput || !emailInput) return;
    const code = emailCodeInput.value.trim();
    const email = pendingEmail || emailInput.value.trim();

    if (!email) {
      alert('Введите email');
      return;
    }

    if (!code) {
      alert('Введите код подтверждения');
      return;
    }

    try {
      const res = await fetch('api/confirm-email-verification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, code })
      });
      const data = await res.json();

      if (!data.success) {
        alert(data.error || 'Не удалось подтвердить email');
        return;
      }

      if (emailHelper) {
        emailHelper.textContent = 'Email успешно подтверждён и привязан.';
      }
      if (emailDisplay) {
        emailDisplay.textContent = email;
      }
      pendingEmail = '';
      emailCodeInput.value = '';
      if (emailCodeSection) {
        emailCodeSection.hidden = true;
      }
    } catch (err) {
      console.error(err);
      alert('Ошибка сети при подтверждении email');
    }
  });
});
