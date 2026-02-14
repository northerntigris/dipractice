document.addEventListener('DOMContentLoaded', async () => {
  const params = new URLSearchParams(window.location.search);
  const mode = params.get('mode');
  window.pageMode = mode || '';
  // режим редактирования (для school/school_coordinator)
  window.isEditMode = false;

  function setEditMode(isEditing) {
    window.isEditMode = Boolean(isEditing);
    document.body.classList.toggle('is-editing', window.isEditMode);

    // Скрываем/показываем кнопку "Редактировать" пока мы в режиме редактирования
    const editInfoBtn = document.getElementById('edit-info-btn');
    if (editInfoBtn) {
      editInfoBtn.style.display = window.isEditMode ? 'none' : '';
    }

    // при включении/выключении — перерисуем таблицы, чтобы появились/исчезли ✎/✕
    if (window.currentOlympiadId) {
      // если setup-режим — участники рисуются из черновика, там действия не нужны
      if (window.isSetupMode) {
        if (typeof renderDraftParticipantsTable === 'function') renderDraftParticipantsTable();
      } else {
        if (typeof loadParticipants === 'function') loadParticipants(window.currentOlympiadId, { compact: false, allowRowOpen: true });
        if (typeof loadJuryMembers === 'function') loadJuryMembers(window.currentOlympiadId);
      }
    }
  }

  function setSchoolFinalSaveVisible(isVisible) {
    const saveBox = document.getElementById('school-final-save-box');
    if (!saveBox) return;
    saveBox.style.display = isVisible ? 'block' : 'none';
  }

  window.setJuryPublishVisible = function setJuryPublishVisible(isVisible) {
    const publishBox = document.getElementById('jury-publish-box');
    if (!publishBox) return;
    publishBox.style.display = isVisible ? 'flex' : 'none';
  };

  const isSetupRequested = params.get('setup') === '1';


  const isOrganizerView = mode === 'organizer';
  const isOrganizerSchoolView = mode === 'organizer_school';
  const isSchoolView = mode === 'school';
  const isJuryView = mode === 'jury';
  const isPublicView = mode === 'public' || mode === '';

  const schoolRegIdParam = params.get('school_reg_id');
  const schoolNameParam = params.get('school_name');

  const id = params.get('id');
  if (!id) {
    alert('Не указан ID олимпиады');
    return;
  }

  const title = document.getElementById('olympiad-title');
  const editBtn = document.getElementById('edit-info-btn');
  const actions = document.getElementById('actions');
  const participantsSection = document.getElementById('participants-table')?.closest('.olympiad-section');
  const jurySection = document.getElementById('jury-block');
  let isOrganizer = false;
  let juryRole = null;
  let isJuryChairman = false;
  window.currentOlympiadView = { isOrganizerView, isJuryView, isPublicView };

  if(isJuryView) {
    document.body.classList.add('jury-view');
  }

  if (!id) {
    title.textContent = 'Олимпиада не найдена';
    if (actions) {
      actions.style.display = 'none';
    }
    return;
  }

  async function fetchUserRole() {
    try {
      const res = await fetch('check-auth.php');
      const data = await res.json();
      if (res.ok && data.success) {
        return data.user_role || null;
      }
    } catch (error) {
      console.error('Ошибка проверки авторизации:', error);
    }
    return null;
  }

  async function fetchOlympiadDetails(olympiadId) {
    const res = await fetch(`api/get-olympiad.php?id=${olympiadId}`);
    const data = await res.json();
    if (res.ok && data && !data.error) {
      return data;
    }

    if (data && data.error === 'Unauthorized') {
      const publicRes = await fetch(`api/get-public-olympiad.php?id=${olympiadId}`);
      const publicData = await publicRes.json();
      if (publicRes.ok && publicData && !publicData.error) {
        return publicData;
      }
    }

    throw new Error((data && data.error) || 'Ошибка загрузки олимпиады');
  }

  async function fetchJuryRole(olympiadId) {
    try {
      const res = await fetch(`api/get-jury-role.php?id=${olympiadId}`);
      const data = await res.json();
      if (res.ok && data.success) {
        return data.role;
      }
    } catch (error) {
      console.error('Ошибка загрузки роли жюри:', error);
    }
    return null;
  }

  function parseGradesToSet(gradesStr) {
    // принимает "5-7, 9, 10-11" -> Set{5,6,7,9,10,11}
    const set = new Set();
    const s = String(gradesStr || '').replace(/\s+/g, '');
    if (!s) return set;

    s.split(/[;,]+/).forEach(part => {
      if (!part) return;
      if (part.includes('-')) {
        const [a, b] = part.split('-').map(x => parseInt(x, 10));
        if (Number.isFinite(a) && Number.isFinite(b)) {
          const from = Math.min(a, b), to = Math.max(a, b);
          for (let g = from; g <= to; g++) if (g >= 1 && g <= 11) set.add(g);
        }
      } else {
        const g = parseInt(part, 10);
        if (Number.isFinite(g) && g >= 1 && g <= 11) set.add(g);
      }
    });

    return set;
  }

  function getSchoolSetupState() {
    // берём либо "школьные" поля, либо черновик (если ты уже делал draft-логику)
    const o = window.currentOlympiad || {};
    const draft = window.schoolOlympiadDraft || {};

    const scheduled = draft.scheduled_at
      ? String(draft.scheduled_at).replace('T', ' ')
      : (o.school_scheduled_at || o.scheduled_at || '');

    const grades = (draft.grades || o.school_grades || o.grades || '').trim();

    return { scheduled, grades, allowedGrades: parseGradesToSet(grades) };
  }

  function isSchoolSetupComplete() {
    const st = getSchoolSetupState();
    return Boolean(st.scheduled) && Boolean(st.grades) && st.allowedGrades.size > 0;
  }

  // Сделаем доступным и для add-expert.js
  window.isSchoolOlympiadReady = isSchoolSetupComplete;
  window.getSchoolSetupState = getSchoolSetupState;

  try {
    const role = await fetchUserRole();
    isOrganizer = role === 'organizer';
    const isSchoolUser = role === 'school' || role === 'school_coordinator';
    window.currentUserRole = role;

    const olympiad = await fetchOlympiadDetails(id);

    if (olympiad) {
      window.currentOlympiad = olympiad;
      window.currentOlympiadId = olympiad.id;

      // Режим "назначение проведения" (школа/координатор на upcoming)
      const isSchoolUserNow = role === 'school' || role === 'school_coordinator';

      // setup=1 принудительно включает режим назначения проведения
      const isTemplate = !(Number(olympiad?.school_id) > 0);
      const isSetupMode =
        isSchoolUserNow &&
        isSchoolView &&
        isTemplate &&
        olympiad.status === 'upcoming' &&
        isSetupRequested;


      // черновик участников (локально, до нажатия "Сохранить")
      window.participantsDraft = window.participantsDraft || [];
      window.juryDraft = window.juryDraft || [];
      window.editingDraftJuryId = null;
      window.editingDraftParticipantId = null;

      window.isSetupMode = isSetupMode;

      // Если setup=1 запрошен явно — это создание НОВОЙ олимпиады по шаблону,
      // поэтому не используем старые school_* данные (если по этому шаблону уже создавали ранее).
      if (isSetupRequested) {
        window.schoolOlympiadDraft = { scheduled_at: '', grades: '', description: '' };
        window.participantsDraft = [];
        window.juryDraft = [];
      }

      // Пометка режима на body (для CSS и логики)
      document.body.classList.toggle('is-setup', Boolean(window.isSetupMode));

      // Фикс-контейнер кнопок редактирования доступен только в setup=1
      const fixedActions = document.getElementById('edit-fixed-actions');
      if (fixedActions) {
        if (window.isSetupMode) {
          fixedActions.style.setProperty('display', 'none', 'important');
        } else {
          fixedActions.style.removeProperty('display');
        }
      }

      setSchoolFinalSaveVisible(Boolean(window.isSetupMode));

      const isSchoolUser = role === 'school' || role === 'school_coordinator';

      const addStudentBtn = document.getElementById('add-student-btn');
      const addExpertBtn = document.getElementById('add-expert-btn');

      if (window.isSetupMode) {
        // В setup-режиме кнопки добавления должны быть видимы всегда
        if (addStudentBtn) addStudentBtn.classList.remove('edit-only');
        if (addExpertBtn) addExpertBtn.classList.remove('edit-only');
      }


      if (isOrganizer && isOrganizerView) {
        // Организатор: только редактирование олимпиады
        if (actions) actions.classList.remove('hidden');

        // скрываем кнопки добавления
        setEditMode(false);

        // скрываем секции участников/жюри
        if (participantsSection) participantsSection.style.display = 'none';
        if (jurySection) jurySection.style.display = 'none';

        // показываем список школ, проводящих олимпиаду
        loadOlympiadSchools(id, olympiad.grades);

      } else if (isOrganizer && isOrganizerSchoolView) {
        // Организатор: просмотр конкретной школы (только инфо)
        if (actions) actions.classList.add('hidden');

        if (participantsSection) participantsSection.style.display = '';
        if (jurySection) jurySection.style.display = '';

        const srid = schoolRegIdParam && /^\d+$/.test(schoolRegIdParam) ? schoolRegIdParam : null;

        loadParticipants(id, { schoolRegId: srid, allowScoreEdit: false, allowUpload: false, allowRowOpen: true });
        loadJuryMembers(window.currentOlympiadId, { schoolRegId: srid, allowRowOpen: true });


        // секция школ на этой странице не нужна
        const schoolsSection = document.getElementById('schools-section');
        if (schoolsSection) schoolsSection.style.display = 'none';

        if (schoolNameParam && title) {
          title.textContent = `${olympiad.subject} — ${decodeURIComponent(schoolNameParam)}`;
        }

      } else if (isSchoolUser && isSchoolView) {
        // Школа/координатор: участники + жюри + редактирование
        if (actions) actions.classList.remove('hidden');
        setEditMode(false);

        if (participantsSection) participantsSection.style.display = '';
        if (jurySection) jurySection.style.display = '';

        if (window.isSetupMode) {
          renderDraftParticipantsTable();
          renderDraftJuryTable();
        } else {
          loadParticipants(id, { compact: false, allowRowOpen: true });
          loadJuryMembers(window.currentOlympiadId);
        }

      } else if (isJuryView) {
        juryRole = await fetchJuryRole(id);
        isJuryChairman = juryRole === 'председатель жюри';
        window.isJuryChairman = isJuryChairman;

        if (actions) actions.classList.add('hidden');
        if (participantsSection) participantsSection.style.display = '';
        if (jurySection) jurySection.style.display = '';

        const participantsHint = document.getElementById('participants-status-hint');
        const isCompleted = olympiad.status === 'completed';
        const isResultsPublished = Boolean(olympiad.results_published);
        if (participantsHint) {
          if (isCompleted) {
            participantsHint.style.display = 'none';
            participantsHint.textContent = '';
          } else {
            participantsHint.textContent = 'Выставление баллов и загрузка сканов доступны только после завершения олимпиады. Дождитесь изменения статуса на «Завершена».';
            participantsHint.style.display = 'block';
          }
        }

        const hasReviewRequests = Boolean(window.hasReviewRequests);
        window.setJuryPublishVisible(isJuryChairman && isCompleted && (!isResultsPublished || hasReviewRequests));

        const publishBtn = document.getElementById('jury-publish-btn');
        if (publishBtn) {
          publishBtn.onclick = async (event) => {
            event.stopPropagation();
            try {
              const res = await fetch('api/publish-jury-results.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ olympiad_id: id })
              });
              const data = await res.json();
              if (!res.ok || data.error) {
                throw new Error(data.error || 'Ошибка публикации результатов');
              }
              window.setJuryPublishVisible(false);
              if (window.currentOlympiad) {
                window.currentOlympiad.results_published = true;
              }
              loadParticipants(id, {
                compact: false,
                allowRowOpen: false,
                allowScoreEdit: false,
                allowUpload: false
              });
            } catch (error) {
              console.error('Ошибка публикации результатов:', error);
              alert('Не удалось опубликовать результаты.');
            }
          };
        }

        loadParticipants(id, {
          compact: false,
          allowRowOpen: false,
          allowScoreEdit: isCompleted && !isResultsPublished,
          allowUpload: isCompleted && !isResultsPublished
        });
        loadJuryMembers(window.currentOlympiadId, { allowRowOpen: false });

      } else if (isPublicView) {
        if (actions) actions.classList.add('hidden');
        if (participantsSection) participantsSection.style.display = '';
        if (jurySection) jurySection.style.display = 'none';

        loadParticipants(id, {
          compact: true,
          allowRowOpen: false,
          allowScoreEdit: false,
          allowUpload: false
        });
      } else {
        if (actions) actions.classList.add('hidden');
      }

      title.textContent = `${olympiad.subject}`;
      window.currentOlympiadStatus = olympiad.status;

      const canEditInfo =
        (isOrganizer && isOrganizerView) ||
        (isSchoolUser && isSchoolView);

      if (!canEditInfo || olympiad.status !== 'upcoming') {
        if (editBtn) editBtn.style.display = 'none';
      } else {
        if (editBtn) editBtn.style.display = '';
      }

      if (window.isSetupMode && editBtn) {
        editBtn.style.display = 'none';
      }

      const infoBlock = document.getElementById('info-placeholder');
      const format = date => new Date(date).toLocaleString();
      const formatInputDate = date => {
        const value = new Date(date);
        if (Number.isNaN(value.getTime())) return '';
        const pad = num => String(num).padStart(2, '0');
        return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}T${pad(value.getHours())}:${pad(value.getMinutes())}`;
      };

      const canSeeAvailableSynonym = ['school', 'school_coordinator', 'organizer'].includes(role);
      const isSchoolInstance = data => Number(data?.school_id) > 0;
      const hasSchoolSchedule = data => Boolean(data?.school_scheduled_at || data?.school_grades);

      const statusText = data => {
        const status = data?.status;
        switch (status) {
          case 'upcoming': {
            if (!canSeeAvailableSynonym) return 'Ожидается';
            if (isSchoolInstance(data) || hasSchoolSchedule(data)) return 'Ожидается';
            return 'Доступно для проведения';
          }
          case 'ongoing': return 'В процессе';
          case 'completed': return 'Завершена';
          case 'archived': return 'Архив';
          case 'cancelled': return 'Отменена';
          default: return 'Неизвестно';
        }
      };

      const renderOrganizerInfoView = (data) => {
        const primaryDate = data.datetime || data.school_scheduled_at;
        const dateLabel = primaryDate ? 'Дата проведения' : 'Даты проведения';
        const dateValue = primaryDate
          ? format(primaryDate)
          : (data.window_start && data.window_end
            ? `${format(data.window_start)} — ${format(data.window_end)}`
            : '-');

        return `
          <p><strong>Предмет:</strong> ${data.subject}</p>
          <p><strong>${dateLabel}:</strong> ${dateValue}</p>
          <p><strong>Классы:</strong> ${data.grades}</p>
          <p><strong>Статус:</strong> ${statusText(data)}</p>
          <p><strong>Описание:</strong><br>${data.description || '—'}</p>
        `;
      };

      const showSchoolSpecific = isSchoolUser && isSchoolView && (isSchoolInstance(olympiad) || hasSchoolSchedule(olympiad));
      const schoolScheduled = olympiad.school_scheduled_at || olympiad.datetime;
      const schoolGrades = olympiad.school_grades || olympiad.grades;

      if (showSchoolSpecific && isSchoolUser && isSchoolView) {
        infoBlock.innerHTML = renderSchoolInfoView(olympiad);
      } else {
        infoBlock.innerHTML = `
          <p><strong>Предмет:</strong> ${olympiad.subject}</p>
          ${showSchoolSpecific
            ? `
              <p><strong>Дата проведения:</strong> ${
                schoolScheduled ? format(schoolScheduled) : '—'
              }</p>
              <p><strong>Классы:</strong> ${schoolGrades || '—'}</p>
            `
            : `
              <p><strong>${olympiad.datetime || olympiad.school_scheduled_at ? 'Дата проведения' : 'Даты проведения'}:</strong> ${
                olympiad.datetime || olympiad.school_scheduled_at
                  ? format(olympiad.datetime || olympiad.school_scheduled_at)
                  : (olympiad.window_start && olympiad.window_end
                    ? `${format(olympiad.window_start)} — ${format(olympiad.window_end)}`
                    : '-')
              }</p>
              <p><strong>Классы:</strong> ${olympiad.grades}</p>
            `
          }
          <p><strong>Статус:</strong> ${statusText(olympiad)}</p>
          <p><strong>Описание:</strong><br>${olympiad.description || '—'}</p>
        `;
      }

      if (isSchoolUser && isSchoolView && window.isSetupMode) {
        infoBlock.classList.remove('placeholder');

        const ws = olympiad.window_start;
        const we = olympiad.window_end;

        const min = ws ? String(ws).replace(' ', 'T').slice(0, 16) : '';
        const max = we ? String(we).replace(' ', 'T').slice(0, 16) : '';

        const d = window.schoolOlympiadDraft || {};
        const scheduledVal = d.scheduled_at || '';
        const gradesVal = d.grades || '';
        const descVal = d.description || '';

        infoBlock.classList.remove('placeholder');


        infoBlock.innerHTML = `
          <div class="setup-info-form">
            <div class="form-group">
              <label for="setup-scheduled-at">Дата и время проведения</label>
              <input type="datetime-local" id="setup-scheduled-at" value="${scheduledVal}"
                ${min ? `min="${min}"` : ''} ${max ? `max="${max}"` : ''}>
            </div>

            <div class="form-group">
              <label for="setup-grades">Классы</label>
              <input type="text" id="setup-grades" placeholder="например: 5-7, 9, 10-11" value="${gradesVal}">
            </div>

            <div class="form-group">
              <label for="setup-description">Описание (необязательно)</label>
              <textarea id="setup-description" rows="4" placeholder="Комментарий / примечание">${descVal}</textarea>
            </div>
          </div>
        `;
        // биндим ввод к черновику
        const dtEl = document.getElementById('setup-scheduled-at');
        const gradesEl = document.getElementById('setup-grades');
        const descEl = document.getElementById('setup-description');

        if (dtEl) dtEl.addEventListener('input', () => {
          window.schoolOlympiadDraft = window.schoolOlympiadDraft || {};
          window.schoolOlympiadDraft.scheduled_at = dtEl.value;
        });

        if (gradesEl) gradesEl.addEventListener('input', () => {
          window.schoolOlympiadDraft = window.schoolOlympiadDraft || {};
          window.schoolOlympiadDraft.grades = gradesEl.value;
        });

        if (descEl) descEl.addEventListener('input', () => {
          window.schoolOlympiadDraft = window.schoolOlympiadDraft || {};
          window.schoolOlympiadDraft.description = descEl.value;
        });

        // Блок "Назначить проведение" (school-schedule-box) в setup=1 не нужен — скрываем
        const box = document.getElementById('school-schedule-box');
        if (box) box.style.display = 'none';
      }


      // === НАЧАЛО ДОБАВЛЯЕМОГО КОДА ===
      if (isSchoolUser && isSchoolView) {
        const isSchoolInstance = Number(olympiad?.school_id) > 0;
        const scheduledValue = isSchoolInstance
          ? (olympiad.datetime ? formatInputDate(olympiad.datetime) : '')
          : (olympiad.school_scheduled_at ? formatInputDate(olympiad.school_scheduled_at) : '');
        const gradesValue = isSchoolInstance ? (olympiad.grades || '') : (olympiad.school_grades || '');
        const descriptionValue = isSchoolInstance ? (olympiad.description ?? '') : (olympiad.school_description ?? '');

        window.schoolOlympiadDraft = {
          scheduled_at: scheduledValue,
          grades: gradesValue,
          description: (descriptionValue ?? '') || ''
        };

        setSchoolFinalSaveVisible(Boolean(window.isSetupMode));

        if (!window.isSetupMode) {
          setSchoolFinalSaveVisible(false);
        } else {
          setSchoolFinalSaveVisible(true);
        }

        if (window.isSetupMode) {
          const btn = document.getElementById('school-final-save-btn');
          if (btn && !btn.dataset.bound) {
            btn.dataset.bound = '1';
            btn.addEventListener('click', async () => {
            const draft = window.schoolOlympiadDraft || {};

            if (!draft.scheduled_at || !draft.grades) {
              alert('Сначала заполните дату проведения и классы в блоке "Общая информация".');
              return;
            }

            try {
              const res = await fetch('api/create-school-olympiad-from-template.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  template_id: window.currentOlympiadId, 
                  scheduled_at: draft.scheduled_at,
                  grades: draft.grades,
                  description: (draft.description || '').trim()
                })
              });

              const data = await res.json();
              if (!data.success) {
                alert(data.error || 'Не удалось сохранить');
                return;
              }

              const newOlympiadId = data.olympiad_id;

              // если есть участники в черновике — сохраняем их в БД
              const draftList = Array.isArray(window.participantsDraft) ? window.participantsDraft : [];
              if (draftList.length) {
                for (const p of draftList) {
                  const res2 = await fetch('api/add-student.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                      olympiad_id: newOlympiadId,
                      full_name: p.full_name,
                      age: p.age,
                      grade: p.grade,
                      snils: p.snils,
                      email: p.email,
                      username: p.username,
                      password: p.password
                    })
                  });

                  const r2 = await res2.json();
                  if (!res2.ok || !r2.success) {
                    throw new Error(r2.error || 'Не удалось сохранить участника из черновика');
                  }
                }

                // очистить черновик и обновить таблицу с сервера
                window.participantsDraft = [];
              }

              // если есть жюри в черновике — сохраняем их в БД
              const draftJury = Array.isArray(window.juryDraft) ? window.juryDraft : [];
              if (draftJury.length) {
                for (const m of draftJury) {
                  const res3 = await fetch('api/add-expert.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                      olympiad_id: newOlympiadId,
                      full_name: m.full_name,
                      email: m.email,
                      snils: m.snils,
                      organization: m.organization,
                      passport_series: m.passport_series,
                      passport_number: m.passport_number,
                      passport_issued_by: m.passport_issued_by,
                      passport_issued_date: m.passport_issued_date,
                      birthdate: m.birthdate,
                      jury_role: m.jury_role,
                      username: m.username,
                      password: m.password
                    })
                  });

                  const r3 = await res3.json();
                  if (!res3.ok || !r3.success) {
                    throw new Error(r3.error || 'Не удалось сохранить члена жюри из черновика');
                  }
                }

                window.juryDraft = [];
              }

              sessionStorage.setItem('school_setup_saved', '1');
              window.location.replace('all-olympiads.html');
              return;

            } catch (e) {
              console.error(e);
              alert('Ошибка сети при сохранении');
            }
          });
        }
      }
      }

      async function fetchOlympiadSchoolsCount(olympiadId) {
        try {
          const res = await fetch(`api/get-olympiad-schools.php?id=${encodeURIComponent(olympiadId)}`);
          const data = await res.json();
          if (!res.ok || !data.success) {
            return null;
          }
          return Array.isArray(data.schools) ? data.schools.length : 0;
        } catch (error) {
          console.error('Ошибка проверки школ:', error);
          return null;
        }
      }

      actions?.addEventListener('click', async (event) => {
        const rawTarget = event.target;
        const target = rawTarget instanceof Element
          ? rawTarget
          : rawTarget?.parentElement;
        if (!target) return;

        const editAction = target.closest('#edit-info-btn');
        const cancelAction = target.closest('#cancel-edit-btn');
        const deleteAction = target.closest('#delete-olympiad-btn');

        // Отмена: выходим из режима редактирования и откатываем несохранённое
        if (cancelAction) {
          event.preventDefault();
          if (!window.isEditMode) return;

          if (confirm('Отменить изменения и выйти из режима редактирования? Несохранённые данные будут потеряны.')) {
            setEditMode(false);

            if (infoBlock) {
              infoBlock.innerHTML = renderOrganizerInfoView(window.currentOlympiad);
              infoBlock.classList.add('placeholder');
            }
          }
          return;
        }

        // Удалить: удаляем олимпиаду целиком
        if (deleteAction) {
          event.preventDefault();

          // На всякий случай — ограничим удаление школой/координатором в режиме school
          const role = window.currentUserRole;
          const mode = window.pageMode;

          if (!((role === 'school' || role === 'school_coordinator') && mode === 'school')) {
            alert('Удаление доступно только для роли школы/координатора в режиме школы.');
            return;
          }

          if (!confirm('Удалить олимпиаду из вашей школы? Будут удалены участники вашей школы и их работы по этой олимпиаде.')) return;

          try {
            deleteAction.disabled = true;

            const res = await fetch('api/delete-school-olympiad.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ olympiad_id: window.currentOlympiadId })
            });

            const data = await res.json();
            if (!res.ok || !data.success) {
              alert(data.error || 'Не удалось удалить олимпиаду из вашей школы.');
              deleteAction.disabled = false;
              return;
            }

            alert('Олимпиада удалена из вашей школы.');
            window.location.href = 'all-olympiads.html';
          } catch (e) {
            console.error(e);
            alert('Ошибка при удалении олимпиады из вашей школы.');
            deleteAction.disabled = false;
          }
          return;
        }

        // Редактировать
        if (!editAction) return;
        event.preventDefault();
        const current = window.currentOlympiad;
        if (!current) return;

        if (isOrganizer && isOrganizerView) {
          const isTemplateOlympiad =
            Number(current?.school_id || 0) <= 0 &&
            Number(current?.template_id || 0) <= 0;

          if (isTemplateOlympiad) {
            const knownCount = typeof window.currentOlympiadSchoolsCount === 'number'
              ? window.currentOlympiadSchoolsCount
              : await fetchOlympiadSchoolsCount(current.id);

            if (knownCount === null) {
              alert('Не удалось проверить, присоединились ли школы. Попробуйте ещё раз позже.');
              return;
            }

            if (knownCount > 0) {
              alert('Редактирование недоступно: школы уже присоединились к проведению. Если нужно изменить даты или классы, создайте новую олимпиаду, удалив текущую.');
              return;
            }
          }
        }

        // --- ШКОЛА/КООРДИНАТОР: редактируем только школьные поля (дата проведения + классы) ---
        if (isSchoolUser && isSchoolView) {
          setEditMode(true);
          const isSchoolInstance = Number(current?.school_id) > 0;
          const draftScheduledSource = isSchoolInstance
            ? (current.datetime || current.school_scheduled_at)
            : (current.school_scheduled_at || current.datetime);
          const draftScheduled = draftScheduledSource ? formatInputDate(draftScheduledSource) : '';
          const draftGrades = current.school_grades || current.grades || '';

          infoBlock.innerHTML = `
            <form id="edit-school-olympiad-form" class="modal-body">
              <label>Дата и время проведения
                <input type="datetime-local" id="school-edit-scheduled" name="scheduled_at" value="${draftScheduled}" required>
              </label>

              <label>Классы (для вашей школы)
                <input type="text" id="school-edit-grades" name="grades" value="${draftGrades}" required>
              </label>

              <div id="school-edit-validation-hint" style="display:none; margin-top:10px; color:#c00;"></div>
            </form>
          `;

          window._schoolInfoBlock = infoBlock;

          const scheduledInput = document.getElementById('school-edit-scheduled');
          const gradesInput = document.getElementById('school-edit-grades');
          const hint = document.getElementById('school-edit-validation-hint');
          const saveBtn = document.getElementById('done-edit-btn');

          window.schoolOlympiadDraft = window.schoolOlympiadDraft || {
            scheduled_at: scheduledInput?.value || '',
            grades: gradesInput?.value || ''
          };

          let lastValidationOk = false;
          let validateTimer = null;

          function setHint(msg) {
            if (!hint) return;
            if (!msg) {
              hint.style.display = 'none';
              hint.textContent = '';
            } else {
              hint.style.display = 'block';
              hint.textContent = msg;
            }
          }

          async function validateSchoolEditNow() {
            const scheduled_at = scheduledInput?.value || '';
            const grades = (gradesInput?.value || '').trim();

            window.schoolOlympiadDraft = { scheduled_at, grades };

            // локальная базовая проверка
            if (!scheduled_at || !grades) {
              lastValidationOk = false;
              setHint('Заполните дату и классы.');
              if (saveBtn) saveBtn.disabled = true;
              return;
            }

            try {
              const res = await fetch('api/save-school-olympiad.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  olympiad_id: window.currentOlympiadId,
                  scheduled_at,
                  grades,
                  dry_run: true
                })
              });

              const data = await res.json();
              if (!res.ok || !data.success) {
                lastValidationOk = false;
                setHint(data.error || 'Проверьте дату и классы.');
                if (saveBtn) saveBtn.disabled = true;
                return;
              }

              lastValidationOk = true;
              setHint('');
              if (saveBtn) saveBtn.disabled = false;
            } catch (e) {
              lastValidationOk = false;
              setHint('Ошибка сети при проверке. Повторите ещё раз.');
              if (saveBtn) saveBtn.disabled = true;
            }
          }

          function scheduleValidate() {
            clearTimeout(validateTimer);
            validateTimer = setTimeout(validateSchoolEditNow, 250);
          }

          scheduledInput?.addEventListener('input', scheduleValidate);
          gradesInput?.addEventListener('input', scheduleValidate);

          // стартовая проверка при открытии
          scheduleValidate();


          return;
        }

        if (!isOrganizer || !isOrganizerView) {
          return;
        }

        if (!current) return;

        setEditMode(true);
        infoBlock.classList.remove('placeholder');

        const useWindowDates = Boolean(current.window_start && current.window_end);
        infoBlock.innerHTML = `
          <form id="edit-olympiad-form" class="modal-body">
            ${useWindowDates
              ? `
                <label>Дата начала проведения
                  <input type="datetime-local" name="window_start" value="${formatInputDate(current.window_start)}" required>
                </label>
                <label>Дата окончания проведения
                  <input type="datetime-local" name="window_end" value="${formatInputDate(current.window_end)}" required>
                </label>
              `
              : `
                <label>Дата и время проведения
                  <input type="datetime-local" name="datetime" value="${formatInputDate(current.datetime)}" required>
                </label>
              `
            }
            <label>Классы
              <input type="text" name="grades" value="${current.grades}" required>
            </label>
          </form>
        `;

        // --- Назначение даты проведения школой ---
        if (isSchoolUser && isSchoolView) {
          const box = document.getElementById('school-schedule-box');
          const hint = document.getElementById('school-window-hint');
          const dt = document.getElementById('school-scheduled-at');
          const save = document.getElementById('school-schedule-save');

          const ws = olympiad.window_start;
          const we = olympiad.window_end;

          if (box && hint && dt && save && ws && we) {
            box.style.display = 'block';

            const min = String(ws).replace(' ', 'T').slice(0, 16);
            const max = String(we).replace(' ', 'T').slice(0, 16);

            hint.textContent = `Допустимый диапазон: ${format(ws)} — ${format(we)}`;
            dt.min = min;
            dt.max = max;
            dt.value = min;

            save.addEventListener('click', async () => {
              try {
                const res = await fetch('api/choose-school-olympiad.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    olympiad_id: olympiad.id,
                    scheduled_at: dt.value
                  })
                });
                const data = await res.json();
                if (!data.success) {
                  alert(data.error || 'Не удалось назначить проведение');
                  return;
                }
                alert('Проведение олимпиады назначено');
                save.disabled = true;
              } catch (e) {
                console.error(e);
                alert('Ошибка сети при назначении');
              }
            }, { once: true });
          }
        }

        document.getElementById('edit-olympiad-form')?.addEventListener('submit', async (event) => {
          event.preventDefault();
          const form = event.target;
          if (!form.checkValidity()) {
            form.reportValidity();
            return;
          }
          const formData = new FormData(form);
          const payload = Object.fromEntries(formData.entries());
          payload.id = window.currentOlympiadId;

          try {
            const res = await fetch('api/update-olympiad.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!res.ok || data.error) {
              throw new Error(data.error || 'Ошибка сохранения');
            }

            const refreshed = await fetchOlympiadDetails(window.currentOlympiadId);
            window.currentOlympiad = refreshed;
            title.textContent = `${refreshed.subject}`;
            infoBlock.innerHTML = renderOrganizerInfoView(refreshed);
            infoBlock.classList.add('placeholder');
            setEditMode(false);
          } catch (err) {
            console.error('Ошибка сохранения олимпиады:', err);
            alert('Не удалось сохранить изменения.');
          }
        });
      });

      document.getElementById('done-edit-btn')?.addEventListener('click', async () => {
        // Если мы не в режиме редактирования — нечего делать
        if (!window.isEditMode) return;

        if (window.currentUserRole === 'organizer' && window.pageMode === 'organizer') {
          const form = document.getElementById('edit-olympiad-form');
          if (form) {
            if (typeof form.requestSubmit === 'function') {
              form.requestSubmit();
            } else {
              form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
          }
          return;
        }

        // ШКОЛА/КООРДИНАТОР + режим school: сохраняем школьные поля
        if ((window.currentUserRole === 'school' || window.currentUserRole === 'school_coordinator') && window.pageMode === 'school') {
          const draft = window.schoolOlympiadDraft || {};
          const scheduled_at = draft.scheduled_at || '';
          const grades = (draft.grades || '').trim();

          if (!scheduled_at || !grades) {
            alert('Заполните дату и классы.');
            return;
          }

          try {
            const btn = document.getElementById('done-edit-btn');
            if (btn) btn.disabled = true;

            const res = await fetch('api/save-school-olympiad.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                olympiad_id: window.currentOlympiadId,
                scheduled_at,
                grades
              })
            });

            const data = await res.json();
            if (!res.ok || !data.success) {
              alert(data.error || 'Не удалось сохранить.');
              if (btn) btn.disabled = false;
              return;
            }

            // обновим данные олимпиады и UI
            const refreshed = await fetchOlympiadDetails(window.currentOlympiadId);
            window.currentOlympiad = refreshed;

            // вернуть отображение общей информации (убрать форму)
            const block = window._schoolInfoBlock || document.getElementById('olympiad-info') || document.getElementById('info-block');
            if (block) block.innerHTML = renderSchoolInfoView(refreshed);

            // закрыть режим редактирования
            setEditMode(false);


            setEditMode(false);

            const infoBlock = document.getElementById('info-placeholder')?.parentElement?.querySelector('#info-placeholder')?.nextElementSibling
              ? document.getElementById('info-placeholder').nextElementSibling
              : document.getElementById('info-placeholder');

            // На всякий случай: найдём infoBlock нормально (у тебя он уже есть в области видимости выше,
            // но если вдруг, оставь как было у тебя: переменная infoBlock)
            // Если у тебя переменная infoBlock доступна здесь — просто используй её и удали этот кусок.

            // правильный рендер (как у тебя уже в коде после финального сохранения)
            const ib = document.getElementById('info-placeholder')?.parentElement?.querySelector('#info-placeholder') ? document.getElementById('info-placeholder').parentElement : null;

            if (typeof loadParticipants === 'function') loadParticipants(window.currentOlympiadId, { compact: false, allowRowOpen: true });
            if (typeof loadJuryMembers === 'function') loadJuryMembers(window.currentOlympiadId);

            alert('Сохранено');
          } catch (e) {
            console.error(e);
            alert('Ошибка сети при сохранении');
            const btn = document.getElementById('done-edit-btn');
            if (btn) btn.disabled = false;
          }

          return;
        }

        // Для остальных ролей — просто выйти (как было)
        setEditMode(false);
      });

    } else {
      title.textContent = 'Олимпиада не найдена';
      if (actions) actions.style.display = 'none';
    }

  } catch (error) {
    console.error('Ошибка загрузки олимпиады:', error);
    title.textContent = 'Олимпиада не найдена';
    if (actions) {
      actions.style.display = 'none';
    }
  }

  document.getElementById('close-jury-modal').addEventListener('click', () => {
    document.getElementById('jury-modal').style.display = 'none';
    document.body.classList.remove('no-scroll');

    const bodyView = document.querySelector('#jury-modal .modal-body .mode-view');
    if (bodyView) bodyView.style.display = 'block';

    const bodyEdit = document.querySelector('#jury-modal .modal-body .mode-edit');
    if (bodyEdit) bodyEdit.style.display = 'none';

    const footerView = document.querySelector('#jury-modal .modal-footer .mode-view');
    if (footerView) footerView.style.display = 'block';

    window.editingDraftJuryId = null;
  });

  // Открытие модального окна
  document.getElementById('add-student-btn')?.addEventListener('click', () => {
    // запрет, пока не заполнены дата и классы
    if (window.currentUserRole === 'school' || window.currentUserRole === 'school_coordinator') {
      if (!isSchoolSetupComplete()) {
        alert('Сначала заполните дату проведения и классы в блоке "Общая информация".');
        return;
      }
    }

    window.editingDraftParticipantId = null;

    document.getElementById('add-student-form')?.reset();
    document.getElementById('add-student-modal')?.classList.add('open');
    document.body.classList.add('no-scroll');
  });

  // Закрытие модального окна
  document.getElementById('close-add-student')?.addEventListener('click', () => {
    document.getElementById('add-student-modal')?.classList.remove('open');
    document.body.classList.remove('no-scroll');
  });

  // Отправка формы
  document.getElementById('add-student-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());
      // Проверка класса по допустимым классам олимпиады (для school/coordinator)
    if (window.currentUserRole === 'school' || window.currentUserRole === 'school_coordinator') {
      if (!isSchoolSetupComplete()) {
        alert('Сначала заполните дату проведения и классы олимпиады.');
        return;
      }

      const st = getSchoolSetupState();
      const gradeNum = parseInt(payload.grade, 10);

      if (!Number.isFinite(gradeNum) || !st.allowedGrades.has(gradeNum)) {
        const list = [...st.allowedGrades].sort((a,b) => a-b).join(', ');
        alert(`Нельзя добавить участника: класс ${payload.grade} не входит в заданные классы олимпиады (${list}).`);
        return;
      }
    }

    payload.olympiad_id = new URLSearchParams(window.location.search).get('id');

    // В режиме назначения (school/school_coordinator + upcoming) — добавляем только в черновик
    if ((window.currentUserRole === 'school' || window.currentUserRole === 'school_coordinator') && window.isSetupMode) {
      const isEditing = Boolean(window.editingDraftParticipantId);

      if (isEditing) {
        const id = String(window.editingDraftParticipantId);
        const idx = (window.participantsDraft || []).findIndex(x => String(x._draft_id) === id);
        if (idx === -1) {
          alert('Черновик участника не найден. Обновите страницу.');
          window.editingDraftParticipantId = null;
          return;
        }

        // обновляем существующий черновик
        window.participantsDraft[idx] = {
          ...window.participantsDraft[idx],
          full_name: payload.full_name,
          grade: payload.grade,
          age: payload.age,
          snils: payload.snils,
          email: payload.email,
          username: payload.username,
          // пароль можно обновлять, но если пустой — оставим старый
          password: payload.password ? payload.password : window.participantsDraft[idx].password
        };

        window.editingDraftParticipantId = null;
        alert('Изменения сохранены в черновике. Нажмите «Сохранить», чтобы записать в систему.');
      } else {
        // добавляем новый черновик
        window.participantsDraft.push({
          _draft_id: String(Date.now()) + '_' + Math.random().toString(16).slice(2),
          full_name: payload.full_name,
          grade: payload.grade,
          age: payload.age,
          snils: payload.snils,
          email: payload.email,
          username: payload.username,
          password: payload.password,
          __draft: true
        });

        alert('Участник добавлен в черновик. Нажмите «Сохранить», чтобы записать в систему.');
      }

      form.reset();
      document.getElementById('add-student-modal')?.classList.remove('open');
      document.body.classList.remove('no-scroll');

      renderDraftParticipantsTable();
      return;
    }


    try {
      const res = await fetch('api/add-student.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const result = await res.json();

      if (result.success) {
        alert('Участник успешно добавлен');
        form.reset();
        document.getElementById('add-student-modal').classList.remove('open');
        document.body.classList.remove('no-scroll');
        if (window.currentOlympiadId) {
          loadParticipants(window.currentOlympiadId);
        }
      } else {
        alert('Ошибка: ' + (result.error || 'Не удалось добавить участника'));
      }
    } catch (err) {
      console.error('Ошибка при добавлении участника:', err);
      alert('Ошибка сервера');
    }
  });

  document.getElementById('close-participant-modal')?.addEventListener('click', () => {
    document.getElementById('participant-modal').classList.remove('open');
    document.body.classList.remove('no-scroll');
    window.editingDraftParticipantId = null;
  });

  document.getElementById('close-school-detail-modal')?.addEventListener('click', () => {
    closeSchoolDetailModal();
  });

  document.getElementById('school-detail-modal')?.addEventListener('click', (event) => {
    if (event.target?.id === 'school-detail-modal') {
      closeSchoolDetailModal();
    }
  });
  document.getElementById('print-jury-btn')?.addEventListener('click', async () => {
  const currentJury = window.currentJuryMember || {};
  const full_name = currentJury.full_name || '';
  const organization = currentJury.organization || '';
  const role = currentJury.jury_role || '';
  const birthdate = currentJury.birthdate || '';
  const passport_series = currentJury.passport_series || '';
  const passport_number = currentJury.passport_number || '';
  const issued_by = currentJury.passport_issued_by || '';
  const issued_date = currentJury.passport_issued_date || '';
  const juryId = currentJury.jury_member_id || document.getElementById('jury-id-span')?.textContent || '';

  const olympiad = window.currentOlympiad || {};
  let olympiad_name = olympiad.subject || document.getElementById('olympiad-title')?.textContent || '—';
  let olympiad_date = olympiad.datetime || olympiad.school_scheduled_at || '—';
  let edu_org = '—';
  let login = '—';
  let password = '—';
  const forceNewPassword = Boolean(document.getElementById('jury-reset-password')?.checked);

  try {
    const formData = new FormData();
    formData.append('jury_id', juryId);
    formData.append('olympiad_id', window.currentOlympiadId || '');
    formData.append('force_new_password', forceNewPassword ? '1' : '0');

    const res = await fetch('api/generate-jury-print-credentials.php', {
      method: 'POST',
      body: formData
    });

    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Не удалось подготовить данные для печати');
    }

    olympiad_name = data.olympiad_name || olympiad_name;
    olympiad_date = data.olympiad_date || olympiad_date;
    edu_org = data.organizer_name || '—';
    login = data.login || '—';
    password = data.password || '—';
  } catch (error) {
    console.error('Ошибка генерации учетных данных для печати:', error);
    alert(error.message || 'Не удалось подготовить данные для печати карточки.');
    return;
  }

  const passwordDisplay = password && password !== '—' ? password : '********';
  const credentialNote = (password && password !== '—')
    ? 'Для входа используйте указанный пароль. После первого входа необходимо сменить пароль.'
    : 'Пользователь уже зарегистрирован в системе и может войти по ранее выданному паролю. Если пароль утерян, запросите новый через организатора олимпиады.';

  const html = `
    <html>
    <head>
      <title>Карточка члена жюри</title>
      <style>
        body { font-family: 'Segoe UI', sans-serif; padding: 20px; max-width: 800px; margin: auto; border: 1px solid #004080; background-color: #fdfdfd; font-size: 13px; line-height: 1.3; }
        .doc-title { text-align: center; font-size: 20px; font-weight: bold; margin-bottom: 10px; margin-top: 5px; color: #222; }
        .section { margin-bottom: 6px; }
        .label { font-weight: bold; display: inline-block; width: 230px; color: #003366; }
        .section-title { font-size: 15px; font-weight: bold; color: #004080; margin-top: 12px; margin-bottom: 5px; border-bottom: 1px solid #ccc; padding-bottom: 2px; }
        .credentials { border-top: 1px dashed #004080; margin-top: 15px; padding-top: 10px; background-color: #eef4fb; padding: 10px; }
        .important { background: #fff4e5; border: 1px solid #f0c36d; padding: 6px; margin-top: 10px; color: #7c5200; font-size: 12px; }
        .footer { margin-top: 30px; border-top: 1px solid #004080; padding-top: 10px; font-size: 11px; color: #555; }
        .note { font-style: italic; color: #555; }
      </style>
    </head>
    <body>
      <div class="doc-title">КАРТОЧКА ЧЛЕНА ЖЮРИ</div>

      <div class="section-title">Персональные данные</div>
      <div class="section"><span class="label">ФИО:</span> ${full_name}</div>
      <div class="section"><span class="label">Организация:</span> ${organization}</div>
      <div class="section"><span class="label">Роль:</span> ${role}</div>
      <div class="section"><span class="label">Дата рождения:</span> ${birthdate}</div>
      <div class="section"><span class="label">Паспортные данные:</span> серия ${passport_series}, номер ${passport_number}</div>
      <div class="section"><span class="label">Кем выдан:</span> ${issued_by}</div>
      <div class="section"><span class="label">Дата выдачи:</span> ${issued_date}</div>

      <div class="section-title">Данные олимпиады</div>
      <div class="section"><span class="label">Олимпиада:</span> ${olympiad_name}</div>
      <div class="section"><span class="label">Дата проведения:</span> ${olympiad_date}</div>
      <div class="section"><span class="label">Организатор:</span> ${edu_org}</div>

      <div class="section-title">Учетные данные</div>
      <div class="credentials">
        <div class="section"><span class="label">Логин:</span> ${login}</div>
        <div class="section"><span class="label">Пароль:</span> ${passwordDisplay}</div>
        <div class="important">${credentialNote}</div>
      </div>

      <div class="footer">
        <div class="note">Если вы забыли пароль, обратитесь к организатору олимпиады для восстановления доступа.</div>
      </div>
    </body>
    </html>`;

  const printWindow = window.open('', '_blank');
  printWindow.document.write(html);
  printWindow.document.close();
  printWindow.onload = () => {
    printWindow.focus();
    printWindow.print();
  };
});

function switchJuryModalToEdit(member = null) {
  const bodyView = document.querySelector('#jury-modal .modal-body .mode-view');
  if (bodyView) bodyView.style.display = 'none';

  const bodyEdit = document.querySelector('#jury-modal .modal-body .mode-edit');
  if (bodyEdit) bodyEdit.style.display = 'block';

  const footerView = document.querySelector('#jury-modal .modal-footer .mode-view');
  if (footerView) footerView.style.display = 'none';

  const src = member || window.currentJuryMember || {};

  document.getElementById('jury-id-input').value = src.jury_member_id || '';
  document.getElementById('jury-full-name-input').value = src.full_name || '';
  document.getElementById('jury-organization-input').value = src.organization || '';
  const roleInput = document.getElementById('jury-role-input');
  const juryRoleValue = src.jury_role || '';
  roleInput.value = [...roleInput.options].some(o => o.value === juryRoleValue) ? juryRoleValue : 'член жюри';
  document.getElementById('jury-snils-input').value = src.snils || '';
  document.getElementById('jury-passport-series-input').value = src.passport_series || '';
  document.getElementById('jury-passport-number-input').value = src.passport_number || '';
  document.getElementById('jury-issued-by-input').value = src.passport_issued_by || '';
  document.getElementById('jury-issued-date-input').value = src.passport_issued_date || '';
  document.getElementById('jury-birthdate-input').value = src.birthdate || '';
}

// Кнопка может отсутствовать по дизайну — вешаем обработчик только если она есть
document.getElementById('edit-jury-btn')?.addEventListener('click', () => {
  switchJuryModalToEdit();
});

function isSchoolJuryEditMode() {
  return (window.currentUserRole === 'school' || window.currentUserRole === 'school_coordinator') &&
    Boolean(window.isEditMode) &&
    !Boolean(window.isSetupMode);
}

const juryListBody = document.getElementById('jury-list-body');
if (juryListBody) {
  juryListBody.addEventListener('click', (event) => {
    if (!isSchoolJuryEditMode()) return;

    const actionBtn = event.target.closest('.edit-jury-mini, .delete-jury-mini');
    if (!actionBtn) {
      // В edit-режиме строки жюри не должны открывать модалку по клику
      event.preventDefault();
      event.stopPropagation();
    }
  }, true);
}


  // Обработчик сохранения формы редактирования жюри
  document.getElementById('edit-jury-form').addEventListener('submit', async (e) => {
    e.preventDefault();

        // setup=1: сохраняем изменения в черновик, без API
    if (window.isSetupMode) {
      const id = window.editingDraftJuryId || document.getElementById('jury-id-input').value;

      const updated = {
        _draft_id: id,
        full_name: document.getElementById('jury-full-name-input').value.trim(),
        organization: document.getElementById('jury-organization-input').value.trim(),
        jury_role: document.getElementById('jury-role-input').value,
        snils: document.getElementById('jury-snils-input').value.trim(),
        passport_series: document.getElementById('jury-passport-series-input').value.trim(),
        passport_number: document.getElementById('jury-passport-number-input').value.trim(),
        passport_issued_by: document.getElementById('jury-issued-by-input').value.trim(),
        passport_issued_date: document.getElementById('jury-issued-date-input').value,
        birthdate: document.getElementById('jury-birthdate-input').value
      };

      const idx = (window.juryDraft || []).findIndex(x => String(x._draft_id) === String(id));
      if (idx >= 0) {
        window.juryDraft[idx] = updated;
      }

      renderJuryView({
        jury_member_id: updated._draft_id,
        ...updated
      });

        // В setup=1 после сохранения просто закрываем модалку,
        // форму просмотра (mod e-view) НЕ показываем
      const modal = document.getElementById('jury-modal');
      if (modal) modal.style.display = 'none';
      document.body.classList.remove('no-scroll');

      window.editingDraftJuryId = null;

      if (typeof window.renderDraftJuryTable === 'function') {
        window.renderDraftJuryTable();
      }

      alert('Член жюри обновлён в черновике (setup=1).');
      return;

      window.editingDraftJuryId = null;

      if (typeof window.renderDraftJuryTable === 'function') window.renderDraftJuryTable();

      alert('Член жюри обновлён в черновике (setup=1).');
      return;
    }


    const formData = new FormData();
    formData.append('jury_id', document.getElementById('jury-id-input').value);
    formData.append('full_name', document.getElementById('jury-full-name-input').value);
    formData.append('organization', document.getElementById('jury-organization-input').value);
    formData.append('jury_role', document.getElementById('jury-role-input').value);
    formData.append('snils', document.getElementById('jury-snils-input').value);
    formData.append('passport_series', document.getElementById('jury-passport-series-input').value);
    formData.append('passport_number', document.getElementById('jury-passport-number-input').value);
    formData.append('passport_issued_by', document.getElementById('jury-issued-by-input').value);
    formData.append('passport_issued_date', document.getElementById('jury-issued-date-input').value);
    formData.append('birthdate', document.getElementById('jury-birthdate-input').value);
    formData.append('olympiad_id', window.currentOlympiadId); // глобально доступен

    try {
      const res = await fetch('api/update-jury-members.php', {
        method: 'POST',
        body: formData
      });

      const result = await res.json();
      if (result.success) {
        renderJuryView({
          jury_member_id: document.getElementById('jury-id-input').value,
          full_name: document.getElementById('jury-full-name-input').value,
          organization: document.getElementById('jury-organization-input').value,
          jury_role: document.getElementById('jury-role-input').value,
          snils: document.getElementById('jury-snils-input').value,
          passport_series: document.getElementById('jury-passport-series-input').value,
          passport_number: document.getElementById('jury-passport-number-input').value,
          passport_issued_by: document.getElementById('jury-issued-by-input').value,
          passport_issued_date: document.getElementById('jury-issued-date-input').value,
          birthdate: document.getElementById('jury-birthdate-input').value
        });
        
        const bodyView = document.querySelector('#jury-modal .modal-body .mode-view');
        if (bodyView) bodyView.style.display = 'block';

        const bodyEdit = document.querySelector('#jury-modal .modal-body .mode-edit');
        if (bodyEdit) bodyEdit.style.display = 'none';

        const footerView = document.querySelector('#jury-modal .modal-footer .mode-view');
        if (footerView) footerView.style.display = 'block';

        alert('Данные успешно обновлены!');

        if (window.currentOlympiadId && typeof window.loadJuryMembers === 'function') {
          await window.loadJuryMembers(window.currentOlympiadId);
        }
      } else {
        alert('Ошибка: ' + (result.error || 'Не удалось обновить.'));
      }
    } catch (err) {
      console.error('Ошибка запроса:', err);
      alert('Ошибка при отправке данных.');
    }
  });

    document.addEventListener('click', async (e) => {
      // setup=1: действия с черновыми участниками
      if (window.isSetupMode) {
        const editBtn = e.target.closest('[data-draft-participant-edit]');
        if (editBtn) {
          e.preventDefault();
          e.stopPropagation();

          const id = editBtn.getAttribute('data-draft-participant-edit');
          const item = (window.participantsDraft || []).find(x => String(x._draft_id) === String(id));
          if (!item) return;

          window.editingDraftParticipantId = id;

          // Открываем модалку добавления участника и заполняем поля
          const modal = document.getElementById('add-student-modal');
          const form = document.getElementById('add-student-form');
          if (!modal || !form) return;

          modal.classList.add('open');
          document.body.classList.add('no-scroll');

          // Заполняем по name=""
          const setVal = (name, val) => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el) el.value = val ?? '';
          };

          setVal('full_name', item.full_name);
          setVal('grade', item.grade);
          setVal('age', item.age);
          setVal('snils', item.snils);
          setVal('email', item.email);
          setVal('username', item.username);

          // пароль при редактировании черновика можно оставить пустым (или показать старый, как решишь)
          setVal('password', item.password || '');

          return;
        }

        const delBtn = e.target.closest('[data-draft-participant-del]');
        if (delBtn) {
          e.preventDefault();
          e.stopPropagation();

          const id = delBtn.getAttribute('data-draft-participant-del');
          window.participantsDraft = (window.participantsDraft || []).filter(x => String(x._draft_id) !== String(id));
          renderDraftParticipantsTable();
          return;
        }
      }

      // setup=1: действия с черновым жюри
      if (window.isSetupMode) {
        const editBtn = e.target.closest('[data-draft-jury-edit]');
        if (editBtn) {
          e.preventDefault();
          e.stopPropagation();

          const id = editBtn.getAttribute('data-draft-jury-edit');
          const item = (window.juryDraft || []).find(x => String(x._draft_id) === String(id));
          if (!item) return;

          window.editingDraftJuryId = id;

          // Открываем модалку просмотра данными из черновика
          openJuryModal({
            jury_member_id: item._draft_id,
            full_name: item.full_name || '',
            jury_role: item.jury_role || '',
            snils: item.snils || '',
            organization: item.organization || '',
            passport_series: item.passport_series || '',
            passport_number: item.passport_number || '',
            passport_issued_by: item.passport_issued_by || '',
            passport_issued_date: item.passport_issued_date || '',
            birthdate: item.birthdate || ''
          });

          // Переводим модалку в режим редактирования
          document.getElementById('edit-jury-btn')?.click();
          return;
        }

        const delBtn = e.target.closest('[data-draft-jury-del]');
        if (delBtn) {
          e.preventDefault();
          e.stopPropagation();

          const id = delBtn.getAttribute('data-draft-jury-del');
          window.juryDraft = (window.juryDraft || []).filter(x => String(x._draft_id) !== String(id));

          if (typeof window.renderDraftJuryTable === 'function') window.renderDraftJuryTable();
          return;
        }
      }

    // участники
    const editP = e.target.closest('.edit-participant-mini');
    if (editP) {
      const participantId = Number(editP.dataset.id);
      if (!participantId) return;

      const p = (window.lastParticipants || []).find(x => Number(x.id) === participantId);
      if (!p) {
        alert('Участник не найден. Обнови страницу.');
        return;
      }

      // открыть модалку и сразу включить редактирование
      openParticipantModal(p);
      openEditParticipantForm(p);
      return;
    }

    const delP = e.target.closest('.delete-participant-mini');
    if (delP) {
      const participantId = Number(delP.dataset.id);
      if (!participantId) return;

      if (!confirm('Удалить участника из олимпиады?')) return;

      try {
        // если у тебя есть apiFetch — используй его, иначе обычный fetch ниже
        if (typeof apiFetch === 'function') {
          await apiFetch('api/remove-olympiad-participant.php', {
            method: 'POST',
            body: JSON.stringify({
              olympiad_id: window.currentOlympiadId,
              student_id: participantId
            })
          });
        } else {
          const res = await fetch('api/remove-olympiad-participant.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
              olympiad_id: window.currentOlympiadId,
              student_id: participantId
            })
          });
          const data = await res.json();
          if (!data.success) throw new Error(data.error || 'Ошибка удаления');
        }

        // перезагрузим список участников
        await loadParticipants(window.currentOlympiadId);

      } catch (err) {
        console.error(err);
        alert(err.message || 'Ошибка удаления участника');
      }

      return;
    }


    // жюри
    const editJ = e.target.closest('.edit-jury-mini');
    if (editJ) {
      let member = null;

      if (editJ.dataset.member) {
        try {
          member = JSON.parse(decodeURIComponent(editJ.dataset.member));
        } catch (error) {
          console.warn('Не удалось прочитать данные члена жюри из data-member:', error);
        }
      }

      const juryMemberId = Number(editJ.dataset.id);
      if (!member && juryMemberId) {
        member = (window.currentJuryMembers || []).find(m => Number(m.jury_member_id) === juryMemberId);
      }

      if (!member && juryMemberId && window.currentOlympiadId) {
        try {
          const res = await fetch(`api/get-jury-members.php?id=${encodeURIComponent(window.currentOlympiadId)}`);
          const data = await res.json();
          const list = Array.isArray(data) ? data : (Array.isArray(data.jury) ? data.jury : []);
          window.currentJuryMembers = list;
          member = list.find(m => Number(m.jury_member_id) === juryMemberId) || null;
        } catch (error) {
          console.warn('Не удалось догрузить данные члена жюри для редактирования:', error);
        }
      }

      if (!member) return;
      openJuryModal(member, { startInEdit: true });
      return;
    }

    const delJ = e.target.closest('.delete-jury-mini');
    if (delJ) {
      const juryMemberId = Number(delJ.dataset.id);
      if (!juryMemberId) return;

    if (!confirm('Удалить члена жюри из олимпиады?')) return;

      try {
        if (typeof apiFetch === 'function') {
          await apiFetch('api/remove-olympiad-jury.php', {
            method: 'POST',
            body: JSON.stringify({
              olympiad_id: window.currentOlympiadId,
              jury_member_id: juryMemberId
            })
          });
        } else {
          const res = await fetch('api/remove-olympiad-jury.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
              olympiad_id: window.currentOlympiadId,
              jury_member_id: juryMemberId
            })
          });
          const data = await res.json();
          if (!data.success) throw new Error(data.error || 'Ошибка удаления');
        }

        // перезагрузим жюри
        await loadJuryMembers(window.currentOlympiadId);

      } catch (err) {
        console.error(err);
        alert(err.message || 'Ошибка удаления члена жюри');
      }

      return;
    }

  }, true);


});

async function loadOlympiadSchools(olympiadId, grades) {
  const section = document.getElementById('schools-section');
  const box = document.getElementById('schools-placeholder');
  if (!section || !box) return;

  section.style.display = '';
  box.textContent = 'Загрузка…';
  box.classList.add('placeholder');

  try {
    const res = await fetch(`api/get-olympiad-schools.php?id=${encodeURIComponent(olympiadId)}`);
    const data = await res.json();

    if (!data.success) {
      box.classList.add('placeholder');
      box.textContent = data.error || 'Ошибка загрузки';
      window.currentOlympiadSchoolsCount = null;
      return;
    }

    const schools = data.schools || [];
    window.currentOlympiadSchoolsCount = schools.length;
    const g = grades || data.grades || '—';

    if (schools.length === 0) {
      box.classList.add('placeholder');
      box.textContent = 'Пока ни одна школа не назначила проведение этой олимпиады.';
      return;
    }

    const mapSchoolStatus = (status) => {
      switch (status) {
        case 'planned':
        case 'upcoming':
          return 'Ожидается';
        case 'ongoing':
          return 'В процессе';
        case 'completed':
          return 'Завершена';
        case 'cancelled':
          return 'Отменена';
        default:
          return 'Ожидается';
      }
    };

    const mapSchoolStatusClass = (status) => {
      switch (status) {
        case 'ongoing':
          return 'status-ongoing';
        case 'completed':
          return 'status-completed';
        case 'cancelled':
          return 'status-cancelled';
        case 'archived':
          return 'status-archived';
        case 'planned':
        case 'upcoming':
        default:
          return 'status-upcoming';
      }
    };

    box.classList.remove('placeholder');
    const list = document.createElement('ul');
    list.className = 'olympiads-list';

    schools.forEach(s => {
      const item = document.createElement('li');
      item.className = 'olympiad-row';

      const dt = s.scheduled_at ? new Date(s.scheduled_at).toLocaleString() : '—';
      const name = s.school_name || 'Школа';
      const statusLabel = mapSchoolStatus(s.status);
      const statusClass = mapSchoolStatusClass(s.status);
      const schoolGrades = (s.school_grades || g || '—');

      item.innerHTML = `
        <div class="olympiad-row-content">
          <div>
            <div class="olympiad-meta">
              <span><i class="fas fa-school"></i> ${name}</span>
              <span><i class="fas fa-calendar-alt"></i> ${dt}</span>
              <span><i class="fas fa-user-graduate"></i> ${schoolGrades}</span>
            </div>
            <span class="status-tag ${statusClass}">${statusLabel}</span>
          </div>
          <div class="olympiad-arrow"><i class="fas fa-arrow-right"></i></div>
        </div>
      `;

      item.addEventListener('click', () => {
        openSchoolDetailModal({
          olympiadId,
          schoolRegId: s.school_reg_id,
          schoolName: name,
          scheduledAt: dt,
          grades: schoolGrades,
          statusLabel,
          subject: window.currentOlympiad?.subject || ''
        });
      });

      list.appendChild(item);
    });

    box.innerHTML = '';
    box.appendChild(list);
  } catch (e) {
    console.error(e);
    box.classList.add('placeholder');
    box.textContent = 'Ошибка сети при загрузке списка школ.';
    window.currentOlympiadSchoolsCount = null;
  }
}

function openSchoolDetailModal({ olympiadId, schoolRegId, schoolName, scheduledAt, grades, statusLabel, subject }) {
  const modal = document.getElementById('school-detail-modal');
  const title = document.getElementById('school-detail-title');
  const info = document.getElementById('school-detail-info');
  if (!modal || !title || !info) return;

  const detailTitle = subject ? `${subject} — ${schoolName}` : schoolName;
  title.textContent = detailTitle;

  const current = window.currentOlympiad || {};

  info.innerHTML = `
    <p><strong>Школа:</strong> ${schoolName}</p>
    <p><strong>Дата проведения:</strong> ${scheduledAt}</p>
    <p><strong>Классы:</strong> ${grades}</p>
    <p><strong>Статус:</strong> ${statusLabel}</p>
    <p><strong>Описание:</strong><br>${current.description || '—'}</p>
  `;

  modal.style.display = 'flex';
  document.body.classList.add('no-scroll');

  loadSchoolDetailParticipants(olympiadId, schoolRegId);
  loadSchoolDetailJury(olympiadId, schoolRegId);
}

function closeSchoolDetailModal() {
  const modal = document.getElementById('school-detail-modal');
  if (!modal) return;
  modal.style.display = 'none';
  document.body.classList.remove('no-scroll');
}

async function loadSchoolDetailParticipants(olympiadId, schoolRegId) {
  const table = document.getElementById('school-participants-table');
  const placeholder = document.getElementById('school-participants-placeholder');
  if (!table || !placeholder) return;

  const tbody = table.querySelector('tbody');
  tbody.innerHTML = '';

  try {
    const res = await fetch(`api/get-olympiad-participants.php?id=${encodeURIComponent(olympiadId)}&school_reg_id=${encodeURIComponent(schoolRegId)}`);
    const data = await res.json();
    const participants = Array.isArray(data)
      ? data
      : Array.isArray(data.participants)
        ? data.participants
        : [];

    if (!participants.length) {
      placeholder.style.display = 'block';
      table.style.display = 'none';
      return;
    }

    placeholder.style.display = 'none';
    table.style.display = 'table';

    participants.forEach(p => {
      const row = document.createElement('tr');
      row.innerHTML = `
        <td>${p.full_name}</td>
        <td>${p.grade}</td>
        <td>${p.score ?? '—'}</td>
        <td>${p.work_file_id ? `<a href="api/get-file.php?type=work&id=${p.work_file_id}" target="_blank">Открыть</a>` : '—'}</td>
      `;
      tbody.appendChild(row);
    });
  } catch (err) {
    console.error('Ошибка загрузки участников школы:', err);
    placeholder.style.display = 'block';
    table.style.display = 'none';
  }
}

async function loadSchoolDetailJury(olympiadId, schoolRegId) {
  const table = document.getElementById('school-jury-table');
  const placeholder = document.getElementById('school-jury-placeholder');
  if (!table || !placeholder) return;

  const tbody = table.querySelector('tbody');
  tbody.innerHTML = '';

  try {
    const res = await fetch(`api/get-jury-members.php?id=${encodeURIComponent(olympiadId)}&school_reg_id=${encodeURIComponent(schoolRegId)}`);
    const data = await res.json();
    const jury = Array.isArray(data)
      ? data
      : Array.isArray(data.jury)
        ? data.jury
        : [];

    window.currentJuryMembers = jury;

    if (!jury.length) {
      placeholder.style.display = 'block';
      table.style.display = 'none';
      return;
    }

    placeholder.style.display = 'none';
    table.style.display = 'table';

    jury.forEach(member => {
      const row = document.createElement('tr');
      row.innerHTML = `
        <td>${member.full_name}</td>
        <td>${member.jury_role}</td>
      `;
      tbody.appendChild(row);
    });
  } catch (err) {
    console.error('Ошибка загрузки жюри школы:', err);
    placeholder.style.display = 'block';
    table.style.display = 'none';
  }
}


async function loadParticipants(olympiadId, optionsOrSchoolRegId = {}) {
  const options = (typeof optionsOrSchoolRegId === 'string' || typeof optionsOrSchoolRegId === 'number')
    ? { schoolRegId: optionsOrSchoolRegId }
    : (optionsOrSchoolRegId || {});

  const {
    allowScoreEdit = false,
    allowUpload = false,
    allowRowOpen = true,
    schoolRegId = null,
    compact = false
  } = options;

  try {
    const table = document.getElementById('participants-table');
    const placeholder = document.getElementById('participants-placeholder');
    const tbody = table?.querySelector('tbody');
    const canEditList =
      (window.currentUserRole === 'school' || window.currentUserRole === 'school_coordinator') &&
      (window.pageMode === 'school') &&
      Boolean(window.isEditMode) &&
      !Boolean(window.isSetupMode);

    // добавляем/убираем заголовок "действия"
    const headRow = table?.querySelector('thead tr');
    if (headRow) {
      const existing = headRow.querySelector('th.actions-col');
      if (canEditList && !existing) {
        const th = document.createElement('th');
        th.className = 'actions-col';
        th.textContent = '';
        headRow.appendChild(th);
      } else if (!canEditList && existing) {
        existing.remove();
      }
    }
    const thScore = table?.querySelector('thead th:nth-child(3)');
    const thScan = table?.querySelector('thead th:nth-child(4)');
    if (thScore) thScore.style.display = '';
    if (thScan) thScan.style.display = '';

    const participantsEndpoint = window.pageMode === 'public'
      ? 'api/get-public-olympiad-participants.php'
      : 'api/get-olympiad-participants.php';

    const url = schoolRegId
      ? `${participantsEndpoint}?id=${encodeURIComponent(olympiadId)}&school_reg_id=${encodeURIComponent(schoolRegId)}`
      : `${participantsEndpoint}?id=${encodeURIComponent(olympiadId)}`;

    const res = await fetch(url);
    const data = await res.json();

    if (!res.ok) {
      console.error('Ошибка загрузки участников:', data);
      throw new Error(data.exception || data.error || 'Ошибка загрузки участников');
    }

    const participants = Array.isArray(data)
      ? data
      : Array.isArray(data.participants)
        ? data.participants
        : [];

    window.lastParticipants = participants;
    window.hasReviewRequests = participants.some(item => Boolean(item.review_requested));
    if (window.pageMode === 'jury' && window.isJuryChairman) {
      const isCompleted = window.currentOlympiad?.status === 'completed';
      const isResultsPublished = Boolean(window.currentOlympiad?.results_published);
      if (typeof window.setJuryPublishVisible === 'function') {
        window.setJuryPublishVisible(Boolean(isCompleted) && (!isResultsPublished || window.hasReviewRequests));
      }
    }
    if (window.pageMode === 'school') {
      updateSchoolPublishBox(participants);
    }

    const queryParams = new URLSearchParams(window.location.search);
    const requestedStudentId = Number(queryParams.get('student_id') || 0);
    const shouldAutoOpenAppealStudent = (
      window.pageMode === 'jury'
      && requestedStudentId > 0
      && !window.__appealStudentAutoOpened
    );

    const renderWorkLinks = (files, allowDelete = false, appealResponseFiles = []) => {
      const normalizeFiles = (value) => {
        if (Array.isArray(value)) return value;
        if (typeof value === 'string') {
          try {
            const parsed = JSON.parse(value);
            return Array.isArray(parsed) ? parsed : [];
          } catch (error) {
            return [];
          }
        }
        return [];
      };

      const workFiles = normalizeFiles(files).map(file => ({ ...file, _type: 'work' }));
      const responseFiles = normalizeFiles(appealResponseFiles).map(file => ({ ...file, _type: 'appeal_response' }));
      const safeFiles = [...workFiles, ...responseFiles];

      if (!safeFiles.length) {
        return '—';
      }

      return safeFiles
        .map((file) => {
          const label = file?.name ? file.name : 'Открыть';
          const isWorkFile = file._type === 'work';
          const deleteBtn = allowDelete && isWorkFile
            ? `<button type="button" class="icon-btn delete-work-btn" data-file-id="${file.id}" title="Удалить">✕</button>`
            : '';
          const suffix = file._type === 'appeal_response' ? ' (ответ жюри)' : '';
          return `
            <div class="work-file-item">
              <a href="api/get-file.php?type=${file._type}&id=${file.id}" target="_blank">${label}${suffix}</a>
              ${deleteBtn}
            </div>
          `;
        })
        .join('');
    };
        
    // Заголовки столбцов (Баллы/Скан) — скрываем в режиме настройки проведения (school/coordinator)
    if (thScore) thScore.style.display = compact ? 'none' : '';
    if (thScan) thScan.style.display = compact ? 'none' : '';


    if (!participants.length) {
      placeholder.style.display = 'block';
      table.style.display = 'none';
      return;
    }

    placeholder.style.display = 'none';
    table.style.display = 'table';
    tbody.innerHTML = '';

    participants.forEach(p => {
      const row = document.createElement('tr');
      const scoreValue = p.score ?? '';
      const canReviewEdit = allowScoreEdit || (window.pageMode === 'jury' && p.review_requested);
      const scoreCell = canReviewEdit
        ? `
          <td>
            <div class="score-display">
              <span class="score-text">${p.score ?? '—'}</span>
              <button type="button" class="icon-btn edit-score-btn" title="Редактировать">✎</button>
            </div>
            <div class="score-editor">
              <input type="number" step="0.01" min="0" max="100" class="score-input" value="${scoreValue}">
              <button type="button" class="btn-action btn-save-score">Сохранить</button>
            </div>
          </td>
        `
        : `<td>${p.score ?? '—'}</td>`;

      const canReviewUpload = allowUpload || (window.pageMode === 'jury' && p.review_requested);
      const workLinks = renderWorkLinks(p.work_files, canReviewUpload, p.appeal_response_files);
      const fileCell = canReviewUpload
        ? `
          <td>
            <div class="work-display">
              <div class="work-files-list">
                ${workLinks}
              </div>
              <button type="button" class="icon-btn add-work-btn" title="Добавить файл">＋</button>
            </div>
            <div class="upload-editor">
              <input type="file" class="work-file-input" accept="application/pdf,image/*" multiple>
              <button type="button" class="btn-action btn-upload-work">Загрузить</button>
              <button type="button" class="btn-action btn-cancel-upload">Отмена</button>
            </div>
          </td>
        `
        : `
          <td>
            ${workLinks}
          </td>
        `;

        const isSchoolViewer = window.pageMode === 'school' && (window.currentUserRole === 'school' || window.currentUserRole === 'school_coordinator');
        const appealBadge = isSchoolViewer && p.appeal_status
          ? `<span class="participant-appeal-badge ${p.appeal_status === 'pending' ? 'pending' : 'resolved'}">${p.appeal_status === 'pending' ? 'Апелляция на рассмотрении' : 'Апелляция рассмотрена'}</span>`
          : '';

        row.innerHTML = `
          <td>${p.full_name}${appealBadge}</td>
          <td>${p.grade}</td>
          ${compact ? '' : scoreCell}
          ${compact ? '' : fileCell}
          ${canEditList ? `
            <td class="actions-col">
              <button type="button" class="icon-btn edit-participant-mini" data-id="${p.id}" title="Редактировать">✎</button>
              <button type="button" class="icon-btn delete-participant-mini" data-id="${p.id}" title="Удалить">✕</button>
            </td>
          ` : ''}
        `;

        row.querySelectorAll('.edit-participant-mini, .delete-participant-mini').forEach(btn => {
          btn.addEventListener('click', (ev) => ev.stopPropagation());
        });

      const disableOpenInEdit =
        (window.currentUserRole === 'school' || window.currentUserRole === 'school_coordinator') &&
        (window.pageMode === 'school') &&
        Boolean(window.isEditMode);

      if (allowRowOpen && !disableOpenInEdit) {
        row.addEventListener('click', () => openParticipantModal(p));
      }

      if (canReviewEdit) {
        const editScoreBtn = row.querySelector('.edit-score-btn');
        const scoreDisplay = row.querySelector('.score-display');
        const saveBtn = row.querySelector('.btn-save-score');
        const scoreInput = row.querySelector('.score-input');
        const scoreEditor = row.querySelector('.score-editor');
        if (scoreEditor) scoreEditor.style.display = 'none';
        scoreInput?.addEventListener('click', event => event.stopPropagation());
        editScoreBtn?.addEventListener('click', (event) => {
          event.stopPropagation();
          if (scoreEditor) scoreEditor.style.display = '';
          if (scoreDisplay) scoreDisplay.style.display = 'none';
          scoreInput?.focus();
        });
        saveBtn?.addEventListener('click', async (event) => {
          event.stopPropagation();
          const value = scoreInput?.value;
          try {
            const res = await fetch('api/update-participant-score.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                olympiad_id: olympiadId,
                student_id: p.id,
                score: value
              })
            });
            const data = await res.json();
            if (!res.ok || data.error) {
              throw new Error(data.error || 'Ошибка сохранения баллов');
            }
            const scoreText = row.querySelector('.score-text');
            if (scoreText) {
              scoreText.textContent = value === '' ? '—' : value;
            }
            if (scoreEditor) scoreEditor.style.display = 'none';
            if (scoreDisplay) scoreDisplay.style.display = '';
          } catch (error) {
            console.error('Ошибка сохранения баллов:', error);
            alert('Не удалось сохранить баллы.');
          }
        });
      }

      if (canReviewUpload) {
        const addWorkBtn = row.querySelector('.add-work-btn');
        const uploadBtn = row.querySelector('.btn-upload-work');
        const cancelUploadBtn = row.querySelector('.btn-cancel-upload');
        const fileInput = row.querySelector('.work-file-input');
        const uploadEditor = row.querySelector('.upload-editor');
        const workDisplay = row.querySelector('.work-display');
        const deleteButtons = row.querySelectorAll('.delete-work-btn');
        if (uploadEditor) uploadEditor.style.display = 'none';
        fileInput?.addEventListener('click', event => event.stopPropagation());
        addWorkBtn?.addEventListener('click', (event) => {
          event.stopPropagation();
          if (uploadEditor) uploadEditor.style.display = '';
          if (workDisplay) workDisplay.style.display = 'none';
          fileInput?.click();
        });
        uploadBtn?.addEventListener('click', async (event) => {
          event.stopPropagation();
          if (!fileInput?.files?.length) {
            alert('Выберите файлы для загрузки.');
            return;
          }
          const formData = new FormData();
          formData.append('olympiad_id', olympiadId);
          formData.append('student_id', p.id);
          Array.from(fileInput.files).forEach((file) => {
            formData.append('work_files[]', file);
          });
          try {
            const res = await fetch('api/upload-participant-work.php', {
              method: 'POST',
              body: formData
            });
            const data = await res.json();
            if (!res.ok || data.error) {
              throw new Error(data.error || 'Ошибка загрузки файла');
            }
            if (uploadEditor) uploadEditor.style.display = 'none';
            if (workDisplay) workDisplay.style.display = '';
            loadParticipants(olympiadId, options);
          } catch (error) {
            console.error('Ошибка загрузки файла:', error);
            alert('Не удалось загрузить файл.');
          }
        });
        cancelUploadBtn?.addEventListener('click', (event) => {
          event.stopPropagation();
          if (fileInput) fileInput.value = '';
          if (uploadEditor) uploadEditor.style.display = 'none';
          if (workDisplay) workDisplay.style.display = '';
        });
        deleteButtons.forEach((btn) => {
          btn.addEventListener('click', async (event) => {
            event.stopPropagation();
            const fileId = btn.getAttribute('data-file-id');
            if (!fileId) return;
            try {
              const res = await fetch('api/delete-participant-work-file.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  olympiad_id: olympiadId,
                  file_id: fileId
                })
              });
              const data = await res.json();
              if (!res.ok || data.error) {
                throw new Error(data.error || 'Ошибка удаления файла');
              }
              loadParticipants(olympiadId, options);
            } catch (error) {
              console.error('Ошибка удаления файла:', error);
              alert('Не удалось удалить файл.');
            }
          });
        });
      }

      tbody.appendChild(row);
    });

    if (shouldAutoOpenAppealStudent) {
      const targetParticipant = participants.find((item) => Number(item.id) === requestedStudentId);
      if (targetParticipant) {
        window.__appealStudentAutoOpened = true;
        openParticipantModal(targetParticipant);
      }
    }
  } catch (err) {
    console.error('Ошибка загрузки участников:', err);
  }
}

function updateSchoolPublishBox(participants = []) {
  const box = document.getElementById('school-publish-box');
  if (!box) return;
  const btn = document.getElementById('school-publish-results-btn');
  const hint = document.getElementById('school-publish-hint');

  const role = window.currentUserRole;
  const isSchoolUser = role === 'school' || role === 'school_coordinator';
  const statusValue = (window.currentOlympiad?.school_status || window.currentOlympiad?.status || '').toString();
  const canShow = isSchoolUser
    && window.pageMode === 'school'
    && statusValue === 'completed'
    && statusValue !== 'archived'
    && Boolean(window.currentOlympiad?.results_published);

  if (!canShow) {
    box.style.display = 'none';
    return;
  }

  const allScored = participants.length > 0 && participants.every(p => p.score !== null && p.score !== undefined);
  const hasPendingAppeals = participants.some(p => p.appeal_status === 'pending');

  if (hint) {
    if (!allScored) {
      hint.textContent = 'Для публикации заполните баллы всем участникам.';
    } else if (hasPendingAppeals) {
      hint.textContent = 'Публикация недоступна: есть апелляции на рассмотрении жюри.';
    } else {
      hint.textContent = 'После публикации олимпиада будет перенесена в архив.';
    }
  }

  if (btn) {
    btn.disabled = !allScored || hasPendingAppeals;
    if (!btn.dataset.bound) {
      btn.dataset.bound = '1';
      btn.addEventListener('click', async () => {
        if (!allScored || hasPendingAppeals) return;
        const confirmPublish = confirm('Сформировать PDF и перенести олимпиаду в архив?');
        if (!confirmPublish) return;
        const url = `api/publish-school-results.php?id=${encodeURIComponent(window.currentOlympiadId)}`;
        const win = window.open(url, '_blank');
        if (!win) {
          alert('Разрешите всплывающие окна для формирования PDF.');
        }
        if (window.currentOlympiad) {
          window.currentOlympiad.status = 'archived';
          window.currentOlympiad.school_status = 'archived';
        }
        const infoBlock = document.getElementById('info-placeholder');
        if (infoBlock) {
          infoBlock.innerHTML = renderSchoolInfoView(window.currentOlympiad || {});
        }
        box.style.display = 'none';
      });
    }
  }

  box.style.display = 'flex';
}

function formatDateTime(value) {
  if (!value) return '—';
  // Приведём ISO / timestamp к читаемому виду ("YYYY-MM-DD HH:MM:SS")
  return String(value).split('.')[0].replace('T', ' ');
}

// Единая расшифровка статуса олимпиады (для блоков, где нет локальной statusText).
function getStatusText(olympiad) {
  const role = window.currentUserRole;
  const canSeeAvailableSynonym = ['school', 'school_coordinator', 'organizer'].includes(role);
  const isSchoolInstance = Number(olympiad?.school_id) > 0;
  const hasSchoolSchedule = Boolean(olympiad?.school_scheduled_at || olympiad?.school_grades);
  const status = olympiad?.status;

  switch (status) {
    case 'upcoming':
      if (!canSeeAvailableSynonym) return 'Ожидается';
      if (isSchoolInstance || hasSchoolSchedule) return 'Ожидается';
      return 'Доступно для проведения';
    case 'ongoing':
      return 'В процессе';
    case 'completed':
      return 'Завершена';
    case 'archived':
      return 'Архив';
    case 'cancelled':
      return 'Отменена';
    default:
      return 'Неизвестно';
  }
}

function renderSchoolInfoView(ol) {
  const isSchoolInstance = Number(ol?.school_id) > 0;
  const scheduledValue = isSchoolInstance ? ol.datetime : ol.school_scheduled_at;
  const gradesValue = isSchoolInstance ? ol.grades : ol.school_grades;
  const scheduled = scheduledValue ? formatDateTime(scheduledValue) : '—';
  const grades = gradesValue ? gradesValue : '—';
  const statusValue = (ol.school_status || ol.status || '').toString();
  const canShowReport = Boolean(ol.results_published)
    && ['archived', 'completed'].includes(statusValue);
  const reportUrl = `api/publish-school-results.php?id=${encodeURIComponent(ol.id)}&view=1`;
  const reportCard = canShowReport
    ? `
      <div class="report-card">
        <div class="report-card__info">
          <div class="report-card__title">PDF-отчёт по результатам</div>
          <div class="report-card__subtitle">
            Отчёт сформирован при публикации результатов. Откройте его для просмотра или сохраните файл.
          </div>
        </div>
        <a class="btn btn-primary report-card__btn" href="${reportUrl}" target="_blank" rel="noopener">
          <i class="fas fa-file-pdf"></i>
          <span>Открыть PDF</span>
        </a>
      </div>
    `
    : '';

  return `
    <p><strong>Предмет:</strong> ${ol.subject || '—'}</p>
    <p><strong>Дата проведения (ваша школа):</strong> ${scheduled}</p>
    <p><strong>Классы (ваша школа):</strong> ${grades}</p>
    <p><strong>Статус:</strong> ${getStatusText(ol)}</p>
    <p><strong>Описание:</strong><br>${(ol.school_description ?? ol.description) || '—'}</p>
    ${reportCard}
  `;
}

function renderDraftJuryTable() {
  const list = Array.isArray(window.juryDraft) ? window.juryDraft : [];

  const tableBody = document.getElementById('jury-list-body');
  const placeholder = document.getElementById('jury-placeholder');
  const table = document.getElementById('jury-table');

  if (!tableBody || !placeholder || !table) return;

  const headRow = table.querySelector('thead tr');
  if (headRow && !headRow.querySelector('th.actions-col')) {
    const th = document.createElement('th');
    th.className = 'actions-col';
    th.textContent = '';
    headRow.appendChild(th);
  }

  tableBody.innerHTML = '';

  if (list.length === 0) {
    placeholder.style.display = 'block';
    table.style.display = 'none';
    return;
  }

  placeholder.style.display = 'none';
  table.style.display = 'table';

  list.forEach(member => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${member.full_name || '—'}</td>
      <td>${member.jury_role || '—'}</td>
      <td class="actions-col">
        <button type="button" class="icon-btn" data-draft-jury-edit="${member._draft_id}" title="Редактировать">✎</button>
        <button type="button" class="icon-btn" data-draft-jury-del="${member._draft_id}" title="Удалить">✕</button>
      </td>
    `;


    tableBody.appendChild(tr);
  });
}

// чтобы add-expert.js мог перерисовать таблицу
window.renderDraftJuryTable = renderDraftJuryTable;

function renderDraftParticipantsTable() {
    window.participantsDraft = (window.participantsDraft || []).map(p => {
    if (!p._draft_id) {
      return { ...p, _draft_id: String(Date.now()) + '_' + Math.random().toString(16).slice(2) };
    }
    return p;
  });
  const table = document.getElementById('participants-table');
  const placeholder = document.getElementById('participants-placeholder');
  if (!table || !placeholder) return;

  const tbody = table.querySelector('tbody');

  // скрываем заголовки "Баллы/Скан" (режим назначения)
  const thScore = table.querySelector('thead th:nth-child(3)');
  const thScan  = table.querySelector('thead th:nth-child(4)');
  if (thScore) thScore.style.display = 'none';
  if (thScan)  thScan.style.display = 'none';

  // колонка "действия" (✎/✕) для черновиков
  const headRow = table.querySelector('thead tr');
  if (headRow && !headRow.querySelector('th.actions-col')) {
    const th = document.createElement('th');
    th.className = 'actions-col';
    th.textContent = '';
    headRow.appendChild(th);
  }

  const draft = Array.isArray(window.participantsDraft) ? window.participantsDraft : [];

  if (!draft.length) {
    // если в БД ещё никого нет — покажем плейсхолдер
    placeholder.style.display = 'block';
    table.style.display = 'none';
    tbody.innerHTML = '';
    return;
  }

  placeholder.style.display = 'none';
  table.style.display = 'table';
  tbody.innerHTML = '';

  draft.forEach(p => {
    const row = document.createElement('tr');
    row.innerHTML = `
      <td>${p.full_name}</td>
      <td>${p.grade}</td>
      <td class="actions-col">
        <button type="button" class="icon-btn" data-draft-participant-edit="${p._draft_id}" title="Редактировать">✎</button>
        <button type="button" class="icon-btn delete-participant-mini" data-draft-participant-del="${p._draft_id}" title="Удалить">✕</button>
      </td>
    `;
    tbody.appendChild(row);
  });
}

function openParticipantModal(p) {
  window.currentParticipant = p;
  const resetCheckbox = document.getElementById('participant-reset-password');
  if (resetCheckbox) resetCheckbox.checked = false;

  const reviewBox = document.getElementById('participant-review-box');
  const reviewReasonInput = document.getElementById('participant-review-reason');
  const reviewBtn = document.getElementById('send-review-btn');

  const modal = document.getElementById('participant-modal');
  const view = document.getElementById('participant-view');
  const form = document.getElementById('edit-participant-form');

  // Переключаемся в режим просмотра
  document.querySelectorAll('.mode-view').forEach(el => el.style.display = '');
  document.querySelectorAll('.mode-edit').forEach(el => el.style.display = 'none');

  const reviewInfo = p.review_requested
    ? `
      <p><strong>Пересмотр:</strong> Запрошен</p>
      <p><strong>Причина:</strong> ${p.review_reason || '—'}</p>
    `
    : '';

  const normalizeFiles = (files) => {
    if (Array.isArray(files)) return files;
    if (typeof files === 'string') {
      try {
        const parsed = JSON.parse(files);
        return Array.isArray(parsed) ? parsed : [];
      } catch (error) {
        return [];
      }
    }
    return [];
  };

  const appealFiles = normalizeFiles(p.appeal_files);
  const responseFiles = normalizeFiles(p.appeal_response_files);
  const appealFilesHtml = appealFiles.length
    ? `<div class="appeal-files">${appealFiles.map(file => `<a href="api/get-file.php?type=appeal&id=${file.id}" target="_blank">${file.name || 'Файл апелляции'}</a>`).join('')}</div>`
    : '<span>Файлы не приложены</span>';
  const responseFilesHtml = responseFiles.length
    ? `<div class="appeal-files">${responseFiles.map(file => `<a href="api/get-file.php?type=appeal_response&id=${file.id}" target="_blank">${file.name || 'Файл ответа'}</a>`).join('')}</div>`
    : '<span>Файлы не приложены</span>';

  const hasAppeal = Boolean(p.appeal_id);
  const isPendingAppeal = hasAppeal && p.appeal_status === 'pending';
  const isResolvedAppeal = hasAppeal && p.appeal_status === 'resolved';
  const isJuryUser = window.pageMode === 'jury';

  const appealInfo = hasAppeal
    ? `
      <div class="participant-appeal-panel ${isPendingAppeal ? 'pending' : 'resolved'}">
        <p><strong>Апелляция:</strong> ${isPendingAppeal ? 'на рассмотрении' : 'рассмотрена'}</p>
        <p><strong>Дата подачи:</strong> ${p.appeal_created_at ? new Date(p.appeal_created_at).toLocaleString('ru-RU') : '—'}</p>
        <p><strong>Комментарий участника:</strong> ${p.appeal_description || '—'}</p>
        <p><strong>Файлы участника:</strong> ${appealFilesHtml}</p>
        ${isResolvedAppeal ? `<p><strong>Ответ жюри:</strong> ${p.appeal_response_comment || '—'}</p>
        <p><strong>Новые баллы:</strong> ${p.appeal_response_score ?? '—'}</p>
        <p><strong>Файлы ответа:</strong> ${responseFilesHtml}</p>` : ''}
        ${isJuryUser && isPendingAppeal ? `
          <div class="appeal-response-box">
            <label>Комментарий жюри
              <textarea id="appeal-response-comment" rows="3" placeholder="Введите комментарий по апелляции"></textarea>
            </label>
            <label>Новые баллы (необязательно)
              <input type="number" id="appeal-response-score" step="0.01" min="0" max="100" value="${p.score ?? ''}">
            </label>
            <label>Файлы ответа
              <input type="file" id="appeal-response-files" multiple>
            </label>
            <button type="button" id="submit-appeal-response" class="btn-action btn-primary">Отправить ответ по апелляции</button>
          </div>
        ` : ''}
      </div>
    `
    : '';

  view.innerHTML = `
    <p><strong>ФИО:</strong> ${p.full_name}</p>
    <p><strong>Класс:</strong> ${p.grade}</p>
    <p><strong>Возраст:</strong> ${p.age ?? '—'}</p>
    <p><strong>СНИЛС:</strong> ${p.snils ?? '—'}</p>
    <p><strong>Email:</strong> ${p.email ?? '—'}</p>
    ${reviewInfo}
    ${appealInfo}
  `;

    // Кнопку "Редактировать" в карточке участника больше не показываем
  const editBtn = document.getElementById('edit-participant-btn');
  if (editBtn) {
    editBtn.style.display = 'none';
    editBtn.onclick = null;
  }

  const printBtn = document.getElementById('print-participant-btn');
  const statusValue = (window.currentOlympiad?.school_status || window.currentOlympiad?.status || '').toString();
  const isSchoolUser = window.currentUserRole === 'school' || window.currentUserRole === 'school_coordinator';
  const isRestrictedStatus = statusValue === 'completed' || statusValue === 'archived';
  const canUseCredentials = isSchoolUser && window.pageMode === 'school' && !isRestrictedStatus;
  if (printBtn) {
    printBtn.style.display = canUseCredentials ? '' : 'none';
  }
  if (resetCheckbox) {
    const resetLabel = resetCheckbox.closest('label');
    if (resetLabel) {
      resetLabel.style.display = canUseCredentials ? '' : 'none';
    }
  }

  const appealResponseBtn = document.getElementById('submit-appeal-response');
  if (appealResponseBtn && p.appeal_id) {
    appealResponseBtn.onclick = async () => {
      const commentInput = document.getElementById('appeal-response-comment');
      const scoreInput = document.getElementById('appeal-response-score');
      const filesInput = document.getElementById('appeal-response-files');
      const formData = new FormData();
      formData.append('appeal_id', String(p.appeal_id));
      formData.append('comment', commentInput?.value?.trim() || '');
      if (scoreInput && scoreInput.value !== '') {
        formData.append('score', scoreInput.value);
      }
      const files = filesInput?.files ? Array.from(filesInput.files) : [];
      files.forEach(file => formData.append('files[]', file));

      appealResponseBtn.disabled = true;
      try {
        const res = await fetch('api/respond-appeal.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data.error || 'Не удалось отправить ответ по апелляции');
        }
        alert('Ответ по апелляции отправлен.');
        document.getElementById('participant-modal').style.display = 'none';
        document.body.classList.remove('no-scroll');
        await loadParticipants(window.currentOlympiadId, {
          compact: false,
          allowRowOpen: window.pageMode !== 'jury' ? true : false,
          allowScoreEdit: window.pageMode === 'jury',
          allowUpload: window.pageMode === 'jury'
        });
      } catch (error) {
        console.error('Ошибка отправки ответа по апелляции:', error);
        alert(error.message || 'Ошибка отправки ответа по апелляции');
      } finally {
        appealResponseBtn.disabled = false;
      }
    };
  }

  if (reviewBox) {
    const canRequestReview = isSchoolUser
      && window.pageMode === 'school'
      && statusValue === 'completed'
      && statusValue !== 'archived'
      && Boolean(window.currentOlympiad?.results_published)
      && p.score !== null
      && p.score !== undefined
      && !p.review_requested;

    reviewBox.style.display = canRequestReview ? 'flex' : 'none';
    if (reviewReasonInput) {
      reviewReasonInput.value = '';
    }
    if (reviewBtn) {
      reviewBtn.onclick = async () => {
        const reason = reviewReasonInput?.value?.trim() || '';
        if (!reason) {
          alert('Укажите причину пересмотра.');
          return;
        }
        try {
          const res = await fetch('api/request-participant-review.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              olympiad_id: window.currentOlympiadId,
              student_id: p.id,
              reason
            })
          });
          const data = await res.json();
          if (!res.ok || data.error) {
            throw new Error(data.error || 'Не удалось отправить на пересмотр');
          }
          alert('Запрос на пересмотр отправлен.');
          modal.classList.remove('open');
          document.body.classList.remove('no-scroll');
          loadParticipants(window.currentOlympiadId, { compact: false, allowRowOpen: true });
        } catch (error) {
          console.error('Ошибка пересмотра:', error);
          alert(error.message || 'Не удалось отправить запрос на пересмотр.');
        }
      };
    }
  }

  modal.classList.add('open');
  document.body.classList.add('no-scroll');
}


document.getElementById('print-participant-btn')?.addEventListener('click', async () => {
  const p = window.currentParticipant;
  if (!p) return alert('Нет данных участника');
  const forceNewPassword = Boolean(document.getElementById('participant-reset-password')?.checked);

  let login = '—';
  let password = '—';

  try {
    const formData = new FormData();
    formData.append('participant_id', p.id);
    formData.append('olympiad_id', window.currentOlympiadId || '');
    formData.append('force_new_password', forceNewPassword ? '1' : '0');

    const res = await fetch('api/generate-participant-print-credentials.php', {
      method: 'POST',
      body: formData
    });

    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Не удалось подготовить данные для печати');
    }

    login = data.login || '—';
    password = data.password || '—';
  } catch (error) {
    console.error('Ошибка генерации учетных данных для печати участника:', error);
    alert(error.message || 'Не удалось подготовить данные для печати карточки.');
    return;
  }

  const passwordDisplay = password && password !== '—' ? password : '********';
  const credentialNote = (password && password !== '—')
    ? 'Для входа используйте указанный пароль. После первого входа необходимо сменить пароль.'
    : 'Пользователь уже зарегистрирован в системе и может войти по ранее выданному паролю. Если пароль утерян, запросите новый через организатора олимпиады.';

  const esc = (value) => String(value ?? '—')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const today = new Date().toLocaleDateString('ru-RU');

  const printWindow = window.open('', '_blank');
  printWindow.document.write(`
    <html>
    <head>
      <title>Карточка участника</title>
      <style>
        :root {
          --accent: #1f6feb;
          --line: #d8e2f1;
          --muted: #60708a;
        }
        * { box-sizing: border-box; }
        body {
          font-family: "Segoe UI", Arial, sans-serif;
          margin: 0;
          padding: 20px;
          color: #0f172a;
          background: #fff;
        }
        .card {
          max-width: 760px;
          margin: 0 auto;
          border: 1px solid var(--line);
          border-radius: 14px;
          overflow: hidden;
        }
        .card-header {
          background: linear-gradient(115deg, #eaf2ff 0%, #f7fbff 100%);
          border-bottom: 1px solid var(--line);
          padding: 18px 24px;
        }
        h2 {
          margin: 0;
          font-size: 24px;
          line-height: 1.2;
        }
        .subtitle {
          margin-top: 6px;
          color: var(--muted);
          font-size: 14px;
        }
        .section {
          padding: 18px 24px;
          border-bottom: 1px solid var(--line);
        }
        .section:last-of-type {
          border-bottom: none;
        }
        .section-title {
          font-size: 12px;
          letter-spacing: 0.08em;
          color: var(--muted);
          text-transform: uppercase;
          margin-bottom: 12px;
          font-weight: 600;
        }
        .row {
          display: grid;
          grid-template-columns: 210px 1fr;
          gap: 12px;
          padding: 7px 0;
          border-top: 1px dashed #e8eef8;
        }
        .row:first-of-type {
          border-top: 0;
          padding-top: 0;
        }
        .label {
          color: var(--muted);
          font-size: 14px;
        }
        .value {
          font-size: 15px;
          font-weight: 600;
        }
        .credentials {
          background: #f7faff;
        }
        .note {
          margin-top: 14px;
          padding: 10px 12px;
          background: #ffffff;
          border: 1px solid #dbe8ff;
          border-radius: 10px;
          color: #334155;
          font-size: 13px;
          line-height: 1.4;
        }
        @media print {
          body { padding: 0; }
          .card { border-radius: 0; }
        }
      </style>
    </head>
    <body>
      <div class="card">
        <div class="card-header">
          <h2>Карточка участника олимпиады</h2>
          <div class="subtitle">Дата печати: ${esc(today)}</div>
        </div>

        <div class="section">
          <div class="section-title">Данные участника</div>
          <div class="row"><div class="label">ФИО</div><div class="value">${esc(p.full_name)}</div></div>
          <div class="row"><div class="label">Возраст</div><div class="value">${esc(p.age)}</div></div>
          <div class="row"><div class="label">Класс</div><div class="value">${esc(p.grade)}</div></div>
          <div class="row"><div class="label">Образовательное учреждение</div><div class="value">${esc(p.school)}</div></div>
          <div class="row"><div class="label">СНИЛС</div><div class="value">${esc(p.snils)}</div></div>
          <div class="row"><div class="label">Email</div><div class="value">${esc(p.email)}</div></div>
        </div>

        <div class="section credentials">
          <div class="section-title">Данные для входа</div>
          <div class="row"><div class="label">Логин</div><div class="value">${esc(login)}</div></div>
          <div class="row"><div class="label">Пароль</div><div class="value">${esc(passwordDisplay)}</div></div>
          <div class="note">${esc(credentialNote)}</div>
        </div>
      </div>
      <script>window.print();</script>
    </body>
    </html>
  `);
  printWindow.document.close();
});

function openEditParticipantForm(p) {
  // Переключаемся в режим редактирования
  document.querySelectorAll('.mode-view').forEach(el => el.style.display = 'none');
  document.querySelectorAll('.mode-edit').forEach(el => el.style.display = '');

  const form = document.getElementById('edit-participant-form');
  form.innerHTML = `
    <label>ФИО
      <input type="text" name="full_name" value="${p.full_name}" required />
    </label>
    <label>Возраст
      <input type="number" name="age" value="${p.age ?? ''}" min="6" max="25" />
    </label>
    <label>Класс
      <input type="number" name="grade" value="${p.grade}" min="1" max="11" />
    </label>
    <label>СНИЛС
      <input type="text" name="snils" value="${p.snils ?? ''}" pattern="\\d{11}" />
    </label>
    <label>Email
      <input type="email" name="email" value="${p.email ?? ''}" />
    </label>
    <button type="submit" class="btn-submit">Сохранить</button>
  `;

  // В setup=1 редактируем черновик локально, без API
  if (window.isSetupMode && p._draft_id) {
    setupDraftParticipantEditHandler(p._draft_id);
  } else {
    setupParticipantEditHandler(p.id);
  }
}


function setupParticipantEditHandler(participantId) {
  const form = document.getElementById('edit-participant-form');
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    console.log('Форма отправлена');
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());
    payload.id = participantId;

    try {
      const res = await fetch('api/update-participant.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const result = await res.json();
      if (result.success) {
        alert('Данные успешно обновлены');
        document.getElementById('participant-modal').classList.remove('open');
        document.body.classList.remove('no-scroll');
        loadParticipants(new URLSearchParams(location.search).get('id'));
      } else {
        alert('Ошибка: ' + result.error);
      }
    } catch (err) {
      console.error('Ошибка при обновлении участника:', err);
      alert('Ошибка сервера');
    }
  });
}

function setupDraftParticipantEditHandler(draftId) {
  const form = document.getElementById('edit-participant-form');
  if (!form) return;

  // чтобы не накапливались обработчики
  form.onsubmit = null;

  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());

    // обновляем в draft
    const idx = (window.participantsDraft || []).findIndex(x => String(x._draft_id) === String(draftId));
    if (idx < 0) return;

    window.participantsDraft[idx] = {
      ...window.participantsDraft[idx],
      full_name: payload.full_name?.trim() || window.participantsDraft[idx].full_name,
      age: payload.age ? Number(payload.age) : window.participantsDraft[idx].age,
      grade: payload.grade?.trim() || window.participantsDraft[idx].grade,
      snils: payload.snils?.trim() || window.participantsDraft[idx].snils,
      email: payload.email?.trim() || window.participantsDraft[idx].email
    };

    // закрываем модалку и перерисовываем таблицу
    const modal = document.getElementById('participant-modal');
    if (modal) modal.classList.remove('open');
    document.body.classList.remove('no-scroll');

    window.editingDraftParticipantId = null;

    renderDraftParticipantsTable();
    alert('Участник обновлён в черновике (setup=1).');
  }, { once: true });
}


async function loadJuryMembers(olympiadId, optionsOrSchoolRegId = {}) {
  // поддержка старого вызова loadJuryMembers(id, srid)
  const options = (typeof optionsOrSchoolRegId === 'string' || typeof optionsOrSchoolRegId === 'number')
    ? { schoolRegId: optionsOrSchoolRegId }
    : (optionsOrSchoolRegId || {});

  const { allowRowOpen = true, schoolRegId = null } = options;

  try {
    const url = schoolRegId
      ? `api/get-jury-members.php?id=${encodeURIComponent(olympiadId)}&school_reg_id=${encodeURIComponent(schoolRegId)}`
      : `api/get-jury-members.php?id=${encodeURIComponent(olympiadId)}`;

    const res = await fetch(url);
    const data = await res.json();

    if (!res.ok) {
      console.error('Ошибка загрузки жюри:', data);
      return;
    }

    if (data && data.error) {
      console.error('Ошибка get-jury-members.php:', data.error);

      // чтобы пользователь видел, что это не "0 членов жюри", а ошибка
      const placeholder = document.getElementById('jury-placeholder');
      const table = document.getElementById('jury-table');
      if (placeholder) {
        placeholder.style.display = 'block';
        placeholder.textContent = `Ошибка загрузки жюри: ${data.error}`;
      }
      if (table) table.style.display = 'none';
      return;
    }

    const jury = Array.isArray(data)
      ? data
      : Array.isArray(data.jury)
        ? data.jury
        : [];

    window.currentJuryMembers = jury;

    const tableBody = document.getElementById('jury-list-body');
    const placeholder = document.getElementById('jury-placeholder');
    const table = document.getElementById('jury-table');
    const canEditJury =
      (window.currentUserRole === 'school' || window.currentUserRole === 'school_coordinator') &&
      Boolean(window.isEditMode) &&
      !Boolean(window.isSetupMode);

    // добавляем/убираем заголовок "действия" в жюри
    const headRow = table?.querySelector('thead tr');
    if (headRow) {
      const existing = headRow.querySelector('th.actions-col');
      if (canEditJury && !existing) {
        const th = document.createElement('th');
        th.className = 'actions-col';
        th.textContent = '';
        headRow.appendChild(th);
      } else if (!canEditJury && existing) {
        existing.remove();
      }
    }

    tableBody.innerHTML = '';

    if (jury.length === 0) {
      placeholder.style.display = 'block';
      table.style.display = 'none';
      return;
    }

    placeholder.style.display = 'none';
    table.style.display = 'table';

    jury.forEach(member => {
      const tr = document.createElement('tr');
      const encodedMember = encodeURIComponent(JSON.stringify(member));
      tr.innerHTML = `
        <td>${member.full_name}</td>
        <td>${member.jury_role}</td>
        ${canEditJury ? `
          <td class="actions-col">
            <button type="button" class="icon-btn edit-jury-mini" data-id="${member.jury_member_id}" data-member="${encodedMember}" title="Редактировать">✎</button>
            <button type="button" class="icon-btn delete-jury-mini" data-id="${member.jury_member_id}" title="Удалить">✕</button>
          </td>
        ` : ''}
      `;

      tr.querySelectorAll('.edit-jury-mini, .delete-jury-mini').forEach(btn => {
        btn.addEventListener('click', (ev) => ev.stopPropagation());
      });
      if (allowRowOpen && !canEditJury) {
        tr.addEventListener('click', () => openJuryModal(member));
      }
      tableBody.appendChild(tr);
    });
  } catch (err) {
    console.error('Ошибка загрузки жюри:', err);
  }
}

window.loadJuryMembers = loadJuryMembers;

function renderJuryView(member) {
  window.currentJuryMember = member;
  const view = document.getElementById('jury-view');
  const idSpan = document.getElementById('jury-id-span');
  if (idSpan) idSpan.textContent = member.jury_member_id ?? '';

  if (view) {
    view.innerHTML = `
      <p><strong>ФИО:</strong> ${member.full_name || '—'}</p>
      <p><strong>Организация:</strong> ${member.organization || '—'}</p>
      <p><strong>Роль:</strong> ${member.jury_role || '—'}</p>
      <p><strong>СНИЛС:</strong> ${member.snils || '—'}</p>
      <p><strong>Серия паспорта:</strong> ${member.passport_series || '—'}</p>
      <p><strong>Номер паспорта:</strong> ${member.passport_number || '—'}</p>
      <p><strong>Кем выдан:</strong> ${member.passport_issued_by || '—'}</p>
      <p><strong>Дата выдачи:</strong> ${member.passport_issued_date || '—'}</p>
      <p><strong>Дата рождения:</strong> ${member.birthdate || '—'}</p>
    `;
  }
}

function openJuryModal(member, { startInEdit = false } = {}) {
  document.getElementById('jury-modal').style.display = 'flex';
  document.body.classList.add('no-scroll');
  const resetCheckbox = document.getElementById('jury-reset-password');
  if (resetCheckbox) resetCheckbox.checked = false;

  renderJuryView(member);

  const printBtn = document.getElementById('print-jury-btn');
  const statusValue = (window.currentOlympiad?.school_status || window.currentOlympiad?.status || '').toString();
  const isSchoolUser = window.currentUserRole === 'school' || window.currentUserRole === 'school_coordinator';
  const isRestrictedStatus = statusValue === 'completed' || statusValue === 'archived';
  const shouldRestrictCredentials = isSchoolUser && isRestrictedStatus && window.pageMode === 'school';
  if (printBtn) {
    printBtn.style.display = shouldRestrictCredentials ? 'none' : '';
  }
  if (resetCheckbox) {
    const resetLabel = resetCheckbox.closest('label');
    if (resetLabel) {
      resetLabel.style.display = shouldRestrictCredentials ? 'none' : '';
    }
  }

  const bodyEdit = document.querySelector('#jury-modal .modal-body .mode-edit');
  const bodyView = document.querySelector('#jury-modal .modal-body .mode-view');
  const footerModeView = document.querySelector('#jury-modal .modal-footer .mode-view');

  if (startInEdit) {
    if (bodyView) bodyView.style.display = 'none';
    if (bodyEdit) bodyEdit.style.display = 'block';
    if (footerModeView) footerModeView.style.display = 'none';
    switchJuryModalToEdit(member);
    return;
  }

  if (bodyEdit) bodyEdit.style.display = 'none';
  if (bodyView) bodyView.style.display = 'block';
  if (footerModeView) footerModeView.style.display = 'block';
}
