import { SidebarLoader } from './sidebar.js';

export class AuthModal {
    static async open() {
        try {
            const response = await fetch('auth-modal.html');
            const authModalHTML = await response.text();

            const modalStructure = `
                <div class="modal-overlay">
                    ${authModalHTML}
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', modalStructure);
            document.body.classList.add('no-scroll');

            this.#setupModal();
        } catch (error) {
            console.error('Ошибка загрузки окна авторизации: ', error);
        }
    }

    static #setupModal() {
        const overlay = document.querySelector('.modal-overlay');
        const authModal = document.querySelector('#auth-modal');
        const closeBtn = document.querySelector('.close-modal');

        if (authModal) {
            authModal.classList.add('modal-active');
        }

        closeBtn?.addEventListener('click', this.close);

        overlay?.addEventListener('click', (e) => {
            if (e.target === overlay) {
                this.close();
            }
        });

        this.#setupAuthForm();
        this.#setupPasswordReset();
    }

    static #setupAuthForm() {
        const authForm = document.getElementById('auth-form');
        const authError = document.getElementById('auth-error');

        if (!authForm) return;

        authForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const loginValue = document.getElementById('login-id')?.value.trim();
            const password = document.getElementById('login-password')?.value;

            if (authError) {
                authError.hidden = true;
                authError.textContent = '';
            }

            if (!loginValue || !password) {
                if (authError) {
                    authError.textContent = 'Заполните логин/email и пароль.';
                    authError.hidden = false;
                }
                return;
            }

            try {
                const response = await fetch('login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        username: loginValue,
                        password,
                    }),
                });

                if (!response.ok) {
                    throw new Error('Ошибка сети');
                }

                const data = await response.json();
                if (data.success) {
                    localStorage.setItem('userRole', data.user_role);

                    if (data.user_role === 'student') {
                        localStorage.setItem('redirectAfterLogin', 'true');
                        localStorage.setItem('redirectTo', 'dashboard-student.html');
                    } else if (data.user_role === 'organizer') {
                        localStorage.setItem('redirectAfterLogin', 'true');
                        localStorage.setItem('redirectTo', 'dashboard-organizer.html');
                    } else if (data.user_role === 'school' || data.user_role === 'school_coordinator') {
                        localStorage.setItem('redirectAfterLogin', 'true');
                        localStorage.setItem('redirectTo', 'dashboard-school.html');
                    }

                    this.close();
                    this.updateAuthUI(true);
                    SidebarLoader.updateUserMenu();
                    location.reload();
                } else if (authError) {
                    authError.textContent = data.error || 'Неверный логин/email или пароль';
                    authError.hidden = false;
                }
            } catch (error) {
                console.error('Ошибка при отправке формы: ', error);
                if (authError) {
                    authError.textContent = 'Ошибка сети при авторизации.';
                    authError.hidden = false;
                }
            }
        });
    }

    static #setupPasswordReset() {
        const authForm = document.getElementById('auth-form');
        const forgotForm = document.getElementById('forgot-password-form');
        const forgotLink = document.getElementById('forgot-password');
        const backToLoginBtn = document.getElementById('back-to-login');

        const sendCodeBtn = document.getElementById('send-reset-code');
        const submitResetBtn = document.getElementById('submit-reset-password');
        const resetCodeRow = document.getElementById('reset-code-row');
        const resetPasswordRow = document.getElementById('reset-password-row');

        const resetEmail = document.getElementById('reset-email');
        const resetCode = document.getElementById('reset-code');
        const resetNewPassword = document.getElementById('reset-new-password');
        const resetStatus = document.getElementById('reset-status');

        const setResetStatus = (text, isError = false) => {
            if (!resetStatus) return;
            resetStatus.textContent = text;
            resetStatus.classList.toggle('is-error', isError);
        };

        forgotLink?.addEventListener('click', (e) => {
            e.preventDefault();
            if (authForm) authForm.hidden = true;
            if (forgotForm) forgotForm.hidden = false;
            setResetStatus('Введите email и нажмите «Отправить код».');
        });

        backToLoginBtn?.addEventListener('click', () => {
            if (forgotForm) forgotForm.hidden = true;
            if (authForm) authForm.hidden = false;
            setResetStatus('');
        });

        sendCodeBtn?.addEventListener('click', async () => {
            const email = resetEmail?.value.trim();
            if (!email) {
                setResetStatus('Введите email.', true);
                return;
            }

            try {
                const response = await fetch('api/request-password-reset.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email }),
                });
                const data = await response.json();

                if (!response.ok || data.success === false) {
                    setResetStatus(data.error || 'Не удалось отправить код.', true);
                    return;
                }

                if (resetCodeRow) resetCodeRow.hidden = false;
                if (resetPasswordRow) resetPasswordRow.hidden = false;
                if (submitResetBtn) submitResetBtn.hidden = false;
                setResetStatus('Код отправлен на email. Введите код и новый пароль.');
            } catch (error) {
                console.error(error);
                setResetStatus('Ошибка сети при отправке кода.', true);
            }
        });

        submitResetBtn?.addEventListener('click', async () => {
            const email = resetEmail?.value.trim();
            const code = resetCode?.value.trim();
            const newPassword = resetNewPassword?.value;

            if (!email || !code || !newPassword) {
                setResetStatus('Заполните email, код и новый пароль.', true);
                return;
            }

            if (newPassword.length < 8) {
                setResetStatus('Пароль должен быть не короче 8 символов.', true);
                return;
            }

            try {
                const response = await fetch('api/reset-password.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, code, new_password: newPassword }),
                });
                const data = await response.json();

                if (!response.ok || data.success === false) {
                    setResetStatus(data.error || 'Не удалось сменить пароль.', true);
                    return;
                }

                setResetStatus('Пароль успешно обновлён. Теперь вы можете войти.');
                if (forgotForm) forgotForm.hidden = true;
                if (authForm) authForm.hidden = false;
            } catch (error) {
                console.error(error);
                setResetStatus('Ошибка сети при смене пароля.', true);
            }
        });
    }

    static close() {
        const overlay = document.querySelector('.modal-overlay');
        if (overlay) {
            overlay.remove();
            document.body.classList.remove('no-scroll');
        }
    }

    static updateAuthUI(isLoggedIn) {
        const loginBtn = document.getElementById('login-btn');
        const logoutBtn = document.getElementById('logout-btn');

        if (loginBtn && logoutBtn) {
            if (isLoggedIn) {
                loginBtn.style.display = 'none';
                logoutBtn.style.display = 'flex';
            } else {
                loginBtn.style.display = 'flex';
                logoutBtn.style.display = 'none';
            }
        }
    }
}
