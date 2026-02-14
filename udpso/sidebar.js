// sidebarLoader.js
import { AuthModal } from './auth-modal.js';

export class SidebarLoader {
    static async loadSidebar(currentPage = '') {
        try {
            // Загружаем HTML сайдбара
            const response = await fetch('sidebar.html');
            const sidebarHtml = await response.text();
            
            // Вставляем сайдбар в контейнер
            const sidebarContainer = document.createElement('div');
            sidebarContainer.innerHTML = sidebarHtml;
            document.querySelector('.container').prepend(sidebarContainer.firstElementChild);

            this.initSidebarEvents();

            // Помечаем активную страницу ПОСЛЕ того, как user-menu заполнился
            if (currentPage) {
                this.setActiveItem(currentPage);
            }

            this.removeDuplicateSupportLinks();
            
        } catch (error) {
            console.error('Ошибка загрузки сайдбара:', error);
        }
    }
    
    static initSidebarEvents() {
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggle-btn');
        const mobileToggleBtn = document.getElementById('mobile-toggle-btn');
        const mainContent = document.getElementById('main-content');
        const loginBtn = document.getElementById('login-btn');
        
        // Проверяем состояние сайдбара в localStorage
        const isSidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        
        if (isSidebarCollapsed) {
            sidebar.classList.add('collapsed');
            if (mainContent) mainContent.classList.add('collapsed');
        }

        const isAuthenticated = localStorage.getItem('isAuthenticated') === 'true';
        AuthModal.updateAuthUI(isAuthenticated);
        
        // Обработчик кнопки входа
        if (loginBtn) {
            loginBtn.addEventListener('click', function() {
                AuthModal.open();
            })
        }

        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', async (e) => {
                e.preventDefault();

                // 1) Попытка завершить сессию на сервере (даже если не получится — всё равно выходим)
                try {
                    await fetch('logout.php', { method: 'POST' });
                } catch (error) {
                    console.error('Ошибка при выходе (server):', error);
                }

                // 2) Чистим локальные данные авторизации
                localStorage.removeItem('isAuthenticated');
                localStorage.removeItem('userRole');
                localStorage.removeItem('userId');
                localStorage.removeItem('username');
                localStorage.removeItem('redirectAfterLogin');
                sessionStorage.clear();

                AuthModal.updateAuthUI(false);
                SidebarLoader.updateUserMenu(); // Обновляем меню

                // 3) Показать кнопку регистрации школы (как было у тебя)
                const registerBtn = document.getElementById('register-school-btn');
                if (registerBtn) {
                    registerBtn.style.display = 'flex';
                    const tooltip = document.querySelector('.register-tooltip');
                    if (tooltip) tooltip.style.display = 'inline-block';
                }

                // 4) ВСЕГДА редирект на главную
                window.location.href = 'index.html';
            });
        }

            
        // Обработчик кнопки сворачивания/разворачивания
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                if (mainContent) mainContent.classList.toggle('collapsed');
                
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }
        
        // Обработчик мобильной кнопки меню
        if (mobileToggleBtn) {
            mobileToggleBtn.addEventListener('click', function() {
                sidebar.classList.toggle('expanded');
            });
        }
        
        // Закрытие сайдбара при клике вне его на мобильных устройствах
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 992) {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnMobileToggle = mobileToggleBtn && mobileToggleBtn.contains(event.target);
                
                if (!isClickInsideSidebar && !isClickOnMobileToggle && sidebar.classList.contains('expanded')) {
                    sidebar.classList.remove('expanded');
                }
            }
        });
        
        // Адаптация к изменению размера окна
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('expanded');
            }
        });

        this.updateUserMenu();
    }

    static updateUserMenu() {
        const userMenu = document.getElementById('user-menu');
        if (!userMenu) return;
        
        const menuItems = userMenu.querySelector('ul');
        menuItems.innerHTML = '';

        // Проверяем, авторизован ли пользователь и его роль
        const userRole = localStorage.getItem('userRole');
        
        if (userRole) {
            const dashboardHref = {
                admin: 'dashboard-admin.html',
                moderator: 'dashboard-admin.html',
                expert: 'dashboard-jury.html',
                organizer: 'dashboard-organizer.html',
                school: 'dashboard-school.html',
                school_coordinator: 'dashboard-school.html',
            }[userRole] || 'index.html';

            if (['admin', 'moderator', 'organizer', 'expert', 'school', 'school_coordinator'].includes(userRole)) {
                const dashboardLabel = userRole === 'expert' ? 'Личный кабинет' : 'Панель управления';
                // Добавляем кнопку "Панель управления" для привилегированных ролей
                menuItems.innerHTML += `
                    <li data-page="dashboard">
                        <a href="${dashboardHref}">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>${dashboardLabel}</span>
                        </a>
                    </li>
                    <li data-page="support">
                        <a href="support.html">
                            <i class="fas fa-headset"></i>
                            <span>Поддержка</span>
                        </a>
                    </li>
                `;
            } else if (userRole === 'student') {
                // Добавляем кнопку "Личный кабинет" для учеников
                menuItems.innerHTML += `
                    <li data-page="profile">
                        <a href="dashboard-student.html">
                            <i class="fas fa-user-circle"></i>
                            <span>Личный кабинет</span>
                        </a>
                    </li>
                    <li data-page="support">
                        <a href="support.html">
                            <i class="fas fa-headset"></i>
                            <span>Поддержка</span>
                        </a>
                    </li>
                `;
            }
        }

        if (['admin', 'moderator'].includes(userRole)) {
            this.updateSupportBadge();
        }

        this.removeDuplicateSupportLinks();
    }

    static setActiveItem(currentPage) {
        // убрать предыдущую подсветку
        document.querySelectorAll('.nav-menu li.active').forEach(li => li.classList.remove('active'));

        // подсветить все подходящие пункты (включая user-menu)
        document.querySelectorAll(`.nav-menu li[data-page="${currentPage}"]`)
            .forEach(li => li.classList.add('active'));
    }

    static removeDuplicateSupportLinks() {
        const userMenuSupport = document.querySelectorAll('#user-menu li[data-page="support"]');
        userMenuSupport.forEach(item => item.remove());

        const supportItems = document.querySelectorAll('.nav-menu li[data-page="support"]');
        if (supportItems.length <= 1) return;
        supportItems.forEach((item, index) => {
            if (index > 0) {
                item.remove();
            }
        });
    }

    static async updateSupportBadge() {
        const badge = document.getElementById('support-unread-badge');
        if (!badge) return;

        try {
            const response = await fetch('api/get-support-unread-count.php');
            const data = await response.json();
            if (!data.success) {
                badge.textContent = '';
                badge.classList.add('hidden');
                return;
            }
            const count = Number(data.count || 0);
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.classList.remove('hidden');
            } else {
                badge.textContent = '';
                badge.classList.add('hidden');
            }
        } catch (error) {
            console.error('Ошибка загрузки непрочитанных сообщений:', error);
        }
    }

}
