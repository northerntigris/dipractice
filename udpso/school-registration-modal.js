export class SchoolRegistrationModal {
    static async open(options = {}) {
        const mode = options.mode === 'edit' ? 'edit' : 'create';
        const school = options.school || null;
        const onSuccess = typeof options.onSuccess === 'function' ? options.onSuccess : null;

        try {
            const response = await fetch('register-school-modal.html');
            const modalHTML = await response.text();

            const modalStructure = `
                <div class="modal-overlay">
                    ${modalHTML}
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', modalStructure);
            document.body.classList.add('no-scroll');

            this.#setupModal({ mode, school, onSuccess });
        } catch (error) {
            console.error('Ошибка загрузки окна регистрации: ', error);
        }
    }

    static #setupModal({ mode, school, onSuccess }) {
        const overlay = document.querySelector('.modal-overlay');
        const modal = overlay.querySelector('#register-school-modal');
        const closeBtn = overlay.querySelector('.close-modal');
        const formTitle = overlay.querySelector('h2');
        const formDescription = overlay.querySelector('.form-description');
        const submitBtnText = overlay.querySelector('.btn.btn-primary span');
        const submitBtnIcon = overlay.querySelector('.btn.btn-primary i');
        const documentsHint = overlay.querySelector('.form-hint');

        modal.style.display = 'flex';

        const form = document.getElementById('register-school-form');

        if (mode === 'edit') {
            if (formTitle) formTitle.textContent = 'Редактирование образовательного учреждения';
            if (formDescription) formDescription.textContent = 'Измените данные школы и сохраните обновления.';
            if (submitBtnText) submitBtnText.textContent = 'Сохранить изменения';
            if (submitBtnIcon) submitBtnIcon.className = 'fas fa-save';
            if (documentsHint) {
                documentsHint.textContent = 'Прикрепите документы, если нужно добавить новые файлы к карточке школы.';
            }

            if (form && !form.querySelector('#generate-new-school-password')) {
                const submitBtn = form.querySelector('button[type="submit"]');
                const passwordBlock = `
                    <div class="form-section">
                        <h3>Доступ в систему</h3>
                        <div class="form-group password-option-group">
                            <label class="password-option-label" for="generate-new-school-password">
                                <input type="checkbox" id="generate-new-school-password">
                                <span>Сформировать новый пароль для школы и отправить его на email</span>
                            </label>
                        </div>
                    </div>
                `;
                submitBtn?.insertAdjacentHTML('beforebegin', passwordBlock);
            }
        }

        closeBtn?.addEventListener('click', this.close);

        overlay?.addEventListener('click', (e) => {
            if (e.target === overlay) {
                this.close();
            }
        });

        if (form) {
            if (mode === 'edit' && school) {
                document.getElementById('school-full-name').value = school.full_name || '';
                document.getElementById('school-short-name').value = school.short_name || '';
                document.getElementById('school-address').value = school.address || '';
                document.getElementById('school-region').value = school.region || '';
                document.getElementById('school-inn').value = school.inn || '';
                document.getElementById('school-ogrn').value = school.ogrn || '';
                document.getElementById('school-ogrn-date').value = school.ogrn_date || '';
                document.getElementById('director-fio').value = school.director_fio || '';
                document.getElementById('director-inn').value = school.director_inn || '';
                document.getElementById('director-position').value = school.director_position || '';
                document.getElementById('school-contact-email').value = school.contact_email || '';
                document.getElementById('school-contact-phone').value = school.contact_phone || '';
            }

            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                const formData = new FormData();
                if (mode === 'edit' && school?.id) {
                    formData.append('school_id', school.id);
                }
                formData.append('full_name', document.getElementById('school-full-name').value);
                formData.append('short_name', document.getElementById('school-short-name').value);
                formData.append('address', document.getElementById('school-address').value);
                formData.append('region', document.getElementById('school-region').value);
                formData.append('inn', document.getElementById('school-inn').value);
                formData.append('ogrn', document.getElementById('school-ogrn').value);
                formData.append('ogrn_date', document.getElementById('school-ogrn-date').value);
                formData.append('director_fio', document.getElementById('director-fio').value);
                formData.append('director_inn', document.getElementById('director-inn').value);
                formData.append('director_position', document.getElementById('director-position').value);
                formData.append('contact_email', document.getElementById('school-contact-email').value);
                formData.append('contact_phone', document.getElementById('school-contact-phone').value);

                if (mode === 'edit') {
                    const needPassword = document.getElementById('generate-new-school-password')?.checked;
                    formData.append('generate_new_password', needPassword ? '1' : '0');
                }

                const filesInput = document.getElementById('school-documents');
                if (filesInput?.files?.length) {
                    Array.from(filesInput.files).forEach((file) => {
                        formData.append('verification_documents[]', file);
                    });
                }

                try {
                    const endpoint = mode === 'edit'
                        ? 'api/update-organizer-school.php'
                        : 'api/register-school-by-organizer.php';
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        if (mode === 'edit') {
                            alert(data.password_sent
                                ? 'Данные школы обновлены. Новый пароль отправлен на email.'
                                : 'Данные школы успешно обновлены.');
                        } else {
                            alert('Школа уведомлена. Письмо с данными доступа отправлено на указанный email.');
                        }
                        this.close();
                        if (onSuccess) {
                            onSuccess();
                        }
                    } else {
                        alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                    }
                } catch (error) {
                    console.error('Ошибка при отправке формы: ', error);
                    alert('Произошла ошибка при отправке формы. Пожалуйста, попробуйте позже.');
                }
            });
        }
    }

    static close() {
        const overlay = document.querySelector('.modal-overlay');
        if (overlay) {
            overlay.remove();
            document.body.classList.remove('no-scroll');
        }
    }
}
