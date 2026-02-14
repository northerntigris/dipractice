document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('add-expert-modal');
  const openBtn = document.getElementById('add-expert-btn');
  const closeBtn = document.getElementById('close-add-expert-modal');
  const form = document.getElementById('add-expert-form');
  if (!modal || !form) {
    return;
  }

  // Открыть модалку
  openBtn?.addEventListener('click', () => {
    const role = localStorage.getItem('userRole');

    if (role === 'school' || role === 'school_coordinator') {
      if (typeof window.isSchoolOlympiadReady === 'function' && !window.isSchoolOlympiadReady()) {
        alert('Сначала заполните дату проведения и классы в блоке "Общая информация".');
        return;
      }
    }

    modal.style.display = 'flex';
    document.body.classList.add('no-scroll');
  });


  // Закрыть модалку
  closeBtn?.addEventListener('click', () => {
    modal.style.display = 'none';
    document.body.classList.remove('no-scroll');
    form.reset();
  });

  // Отправка формы
  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    const formData = new FormData(form);
    formData.append('olympiad_id', window.currentOlympiadId); // предполагается, что ID олимпиады передаётся глобально

    // setup=1: сохраняем ТОЛЬКО в черновик, без API
    if (window.isSetupMode) {
      const draftMember = {
        _draft_id: String(Date.now()) + '_' + Math.random().toString(16).slice(2),
        full_name: (formData.get('full_name') || '').toString().trim(),
        organization: (formData.get('organization') || '').toString().trim(),
        jury_role: (formData.get('jury_role') || '').toString().trim(),
        passport_series: (formData.get('passport_series') || '').toString().trim(),
        passport_number: (formData.get('passport_number') || '').toString().trim(),
        passport_issued_by: (formData.get('passport_issued_by') || '').toString().trim(),
        passport_issued_date: (formData.get('passport_issued_date') || '').toString().trim(),
        birthdate: (formData.get('birthdate') || '').toString().trim(),
        snils: (formData.get('snils') || '').toString().trim(),
        email: (formData.get('email') || '').toString().trim()
      };

      window.juryDraft = window.juryDraft || [];
      window.juryDraft.push(draftMember);

      if (typeof window.renderDraftJuryTable === 'function') {
        window.renderDraftJuryTable();
      }

      alert('Член жюри добавлен в черновик (setup-режим).');

      modal.style.display = 'none';
      document.body.classList.remove('no-scroll');
      form.reset();
      return;
    }


    try {
      const res = await fetch('api/add-expert.php', {
        method: 'POST',
        body: formData
      });

      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (parseError) {
        console.error('Некорректный JSON от сервера:', text);
        alert('Ошибка сервера. Попробуйте позже.');
        return;
      }

      if (data.success) {
        if (data.existing_user) {
          alert(`Член жюри добавлен. Логин: ${data.login}. Пароль уже задан.`);
        } else {
          alert(`Член жюри добавлен. Логин: ${data.login}, Пароль: ${data.password}`);
        }
        modal.style.display = 'none';
        document.body.classList.remove('no-scroll');
        form.reset();
    
        if (window.currentOlympiadId && typeof window.loadJuryMembers === 'function') {
          await window.loadJuryMembers(window.currentOlympiadId);
        }
      } else {
        alert('Ошибка: ' + (data.error || 'Не удалось добавить члена жюри.'));
      }
    } catch (err) {
      console.error(err);
      alert('Ошибка при отправке запроса.');
    }
  });
});
