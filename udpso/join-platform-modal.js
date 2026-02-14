export class JoinPlatformModal {
    static async open() {
        try {
            const response = await fetch('join-platform-modal.html');
            const modalHTML = await response.text();

            const modalStructure = `
                <div class="modal-overlay">
                    ${modalHTML}
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', modalStructure);
            document.body.classList.add('no-scroll');

            this.#setupModal();
        } catch (error) {
            console.error('Ошибка загрузки окна заявки: ', error);
        }
    }

    static #setupModal() {
        const overlay = document.querySelector('.modal-overlay');
        const modal = overlay.querySelector('#join-platform-modal');
        const closeBtn = overlay.querySelector('.close-modal');

        modal.style.display = 'flex';

        closeBtn?.addEventListener('click', this.close);

        overlay?.addEventListener('click', (e) => {
            if (e.target === overlay) {
                this.close();
            }
        });

        const form = document.getElementById('join-platform-form');
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                const formData = new FormData();
                formData.append('full_name', document.getElementById('platform-full-name').value);
                formData.append('address', document.getElementById('platform-address').value);
                formData.append('ogrn', document.getElementById('platform-ogrn').value);
                formData.append('registration_date', document.getElementById('platform-registration-date').value);
                formData.append('director_fio', document.getElementById('platform-director-fio').value);
                formData.append('director_inn', document.getElementById('platform-director-inn').value);
                formData.append('director_position', document.getElementById('platform-director-position').value);
                formData.append('contact_email', document.getElementById('platform-contact-email').value);
                formData.append('contact_phone', document.getElementById('platform-contact-phone').value);

                const documentsInput = document.getElementById('platform-documents');
                if (documentsInput?.files?.length) {
                    Array.from(documentsInput.files).forEach((file) => {
                        formData.append('verification_documents[]', file);
                    });
                }

                try {
                    const response = await fetch('api/register-organizer.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        alert('Заявка отправлена. Мы свяжемся с вами для уточнения данных.');
                        this.close();
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
