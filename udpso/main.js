//main.js
import { SidebarLoader } from './sidebar.js';
import { NewsEditor } from './news-editor.js';
import { AuthModal } from './auth-modal.js';
import { JoinPlatformModal } from './join-platform-modal.js';

async function checkAuth() {
    try {
        const response = await fetch('check-auth.php');
        const data = await response.json();
        
        if (data.success) {
            localStorage.setItem('userRole', data.user_role);
            localStorage.setItem('isAuthenticated', 'true');
            AuthModal.updateAuthUI(true);
            SidebarLoader.updateUserMenu();

            SidebarLoader.setActiveItem(document.body.getAttribute('data-page'));

            const redirectAfterLogin = localStorage.getItem('redirectAfterLogin') === 'true';
            const redirectTo = localStorage.getItem('redirectTo');

            if (redirectAfterLogin && redirectTo) {
            localStorage.removeItem('redirectAfterLogin');
            localStorage.removeItem('redirectTo');

                if (!window.location.pathname.endsWith(redirectTo)) {
                    window.location.href = redirectTo;
                    return;
                }
            }
        } else {
            localStorage.removeItem('userRole');
            localStorage.removeItem('isAuthenticated');
            AuthModal.updateAuthUI(false);
            SidebarLoader.updateUserMenu();

            if (document.body.getAttribute('data-page') === 'profile') {
                window.location.href = 'index.html';
                return;
            }

            const registerBtn = document.getElementById('register-school-btn');
            if (registerBtn) {
                registerBtn.style.display = 'flex';
                // Также показываем тултип
                const tooltip = document.querySelector('.register-tooltip');
                if (tooltip) tooltip.style.display = 'inline-block';
            }
        }
    } catch (error) {
        console.error('Ошибка проверки авторизации:', error);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    async function updateOlympiadStatuses() {
        const lastRun = Number(localStorage.getItem('lastStatusUpdate') || 0);
        const now = Date.now();
        if (now - lastRun < 5 * 60 * 1000) {
            return;
        }
        localStorage.setItem('lastStatusUpdate', String(now));
        try {
            await fetch('api/update-olympiad-statuses.php');
        } catch (error) {
            console.warn('Не удалось обновить статусы олимпиад:', error);
        }
    }

    updateOlympiadStatuses();
    checkAuth();
    // Определяем текущую страницу
    const currentPage = document.body.getAttribute('data-page') || '';
    const userRole = localStorage.getItem('userRole');
    
    // Загружаем сайдбар
    SidebarLoader.loadSidebar(currentPage);
    
    // Фиксируем высоту сайдбара
    function fixSidebarHeight() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            sidebar.style.height = `${window.innerHeight}px`;
        }
    }
    
    fixSidebarHeight();
    window.addEventListener('resize', fixSidebarHeight);

    const registerBtn = document.getElementById('register-school-btn');
    if (registerBtn) {
        registerBtn.addEventListener('click', function() {
            JoinPlatformModal.open();
        });
    }

    const registerSecondaryBtn = document.getElementById('register-school-btn-secondary');
    if (registerSecondaryBtn) {
        registerSecondaryBtn.addEventListener('click', function() {
            JoinPlatformModal.open();
        });
    }

    const canManageNews = ['admin', 'moderator', 'organizer', 'school', 'school_coordinator'].includes(userRole);

    if (canManageNews) {
        NewsEditor.init();
        document.querySelectorAll('.news-item').forEach(item => {
            const actions = item.querySelector('.news-actions');
            if (actions) actions.style.display = 'flex';
        });
    }

    const addNewsBtn = document.getElementById('news-add-btn');
    if (addNewsBtn) {
        if (canManageNews) {
            addNewsBtn.addEventListener('click', () => NewsEditor.openCreateModal());
        } else {
            addNewsBtn.style.display = 'none';
        }
    }

    let currentNewsRegion = '';
    let hasExplicitNewsRegionSelection = false;

    async function loadNews(region = currentNewsRegion) {
        currentNewsRegion = typeof region === 'string' ? region : '';
        const container = document.getElementById('news-container');
        if (!container) return; 
        
        if (!document.getElementById('edit-news-modal')) {
            const modalResponse = await fetch('edit-news-modal.html');
            const modalHTML = await modalResponse.text();
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            // Сразу скрываем модальное окно
            const modal = document.getElementById('edit-news-modal');
            if (modal) modal.style.display = 'none';
        }

        if (!hasExplicitNewsRegionSelection || !currentNewsRegion) {
            container.innerHTML = '<div class="empty-message">Выберите регион для отображения новостей и объявлений</div>';
            return;
        }

        fetch(`news.php?region=${encodeURIComponent(currentNewsRegion)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const container = document.getElementById('news-container');
                    const userRole = localStorage.getItem('userRole');
                    const showActions = ['admin', 'moderator', 'organizer', 'school', 'school_coordinator'].includes(userRole);

                    container.innerHTML = data.news.map(news => {
                        let files = [];
                        if (Array.isArray(news.files)) {
                            files = news.files;
                        } else if (typeof news.files === 'string') {
                            try {
                                const parsed = JSON.parse(news.files);
                                if (Array.isArray(parsed)) {
                                    files = parsed;
                                }
                            } catch (error) {
                                console.warn('Не удалось распарсить файлы новости', error);
                            }
                        }
                        return `
                        <div class="news-item" data-id="${news.id}">
                            <div class="news-header">
                                <h3 class="news-title">${news.title}</h3>
                                ${showActions ? `
                                <div class="news-actions">
                                    <button class="btn-edit"><i class="fas fa-edit"></i></button>
                                    <button class="btn-delete"><i class="fas fa-trash"></i></button>
                                </div>
                                ` : ''}
                            </div>
                            <div class="news-content">${news.content || ''}</div>
                            <p class="news-meta">
                                <i class="fas fa-user"></i>
                                ${news.author_display || 'Администратор системы'}
                            </p>
                            <p class="news-date">
                                <i class="far fa-calendar-alt"></i>
                                ${new Date(news.created_at).toLocaleDateString()}
                            </p>
                            ${files.length ? `
                            <div class="news-files">
                                <div class="news-files__title">Файлы</div>
                                ${files.map(file => `
                                    <a class="news-file-link" href="api/get-news-file.php?id=${file.id}" target="_blank" rel="noopener">
                                        <i class="fas fa-paperclip"></i>
                                        <span>${file.name}</span>
                                    </a>
                                `).join('')}
                            </div>
                            ` : ''}
                        </div>
                    `}).join('');

                    // Инициализируем редактор новостей, если пользователь имеет права
                    if (showActions) {
                        NewsEditor.init();
                    }
                }
            })
            .catch(error => console.error('Ошибка загрузки новостей:', error));
    }

    document.addEventListener('news-region-change', (event) => {
        const selectedRegion = typeof event?.detail?.region === 'string' ? event.detail.region : '';
        const isExplicitSelection = Boolean(event?.detail?.explicit);

        if (!isExplicitSelection) {
            return;
        }

        hasExplicitNewsRegionSelection = true;
        loadNews(selectedRegion);
    });

    loadNews(currentNewsRegion);
});
