// dashboard-admin.js
import { ApplicationsList } from './applications-list.js';

export class DashboardAdmin {
    static init() {
        this.checkAuth();
        ApplicationsList.init();
        this.loadStats();
        this.loadRecentActivity();
        this.setupEventListeners();
    }

    static checkAuth() {
        const userRole = localStorage.getItem('userRole');
        if (userRole === 'expert') {
            window.location.href = 'dashboard-jury.html';
            return;
        }

        if (userRole !== 'admin') {
            window.location.href = 'index.html';
        }
    }

    static async loadStats() {
        try {
            const response = await fetch('api/get-stats.php');
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('pending-applications').textContent = data.pending_applications;
                document.getElementById('total-users').textContent = data.total_users;
                document.getElementById('admin-total-schools').textContent = data.total_schools;
                ApplicationsList.updateBadge(Number(data.pending_applications) || 0);

            }
        } catch (error) {
            console.error('Ошибка загрузки статистики:', error);
        }
    }

    static async loadRecentActivity() {
        try {
            const response = await fetch('api/get-activity.php?limit=5');
            const data = await response.json();
            
            const activityList = document.getElementById('activity-list');
            if (data.success) {
                activityList.innerHTML = data.activities.map(activity => `
                    <div class="activity-item">
                        <div class="activity-icon ${this.getActivityIconClass(activity.type)}">
                            <i class="${this.getActivityIcon(activity.type)}"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title">${activity.title}</div>
                            <div class="activity-date">
                                ${new Date(activity.created_at).toLocaleString()}
                                ${activity.user_name ? ` • ${activity.user_name}` : ''}
                            </div>
                        </div>
                    </div>
                `).join('');
            } else {
                activityList.innerHTML = '<div class="error-message">Не удалось загрузить последние действия</div>';
            }
        } catch (error) {
            console.error('Ошибка загрузки активности:', error);
        }
    }

    static getActivityIcon(type) {
        const icons = {
            'school_registered': 'fas fa-school',
            'user_created': 'fas fa-user-plus',
            'olympiad_created': 'fas fa-trophy',
            'application_processed': 'fas fa-file-signature'
        };
        return icons[type] || 'fas fa-info-circle';
    }

    static getActivityIconClass(type) {
        const classes = {
            'school_registered': 'icon-school',
            'user_created': 'icon-user',
            'olympiad_created': 'icon-olympiad',
            'application_processed': 'icon-school'
        };
        return classes[type] || '';
    }

    static setupEventListeners() {
        // Быстрые действия
        document.getElementById('add-moderator')?.addEventListener('click', () => {
            this.showAddModeratorModal();
        });

        document.getElementById('manage-users')?.addEventListener('click', () => {
            window.location.href = 'users.html';
        });

        document.getElementById('view-all-applications')?.addEventListener('click', () => {
            window.location.href = 'applications.html';
        });

        document.getElementById('system-settings')?.addEventListener('click', () => {
            window.location.href = 'settings.html';
        });
    }

    static showAddModeratorModal() {
        const modalHTML = `
            <div class="modal-overlay">
                <div id="add-moderator-modal" class="modal">
                    <div class="modal-content">
                        <span class="close-modal">&times;</span>
                        <h2>Добавление модератора</h2>
                        <form id="add-moderator-form">
                            <div class="form-group">
                                <label for="moderator-fio">ФИО</label>
                                <input type="text" id="moderator-fio" required>
                            </div>
                            <div class="form-group">
                                <label for="moderator-email">Email</label>
                                <input type="email" id="moderator-email" required>
                            </div>
                            <div class="form-group">
                                <label class="checkbox-container">
                                    <input type="checkbox" id="moderator-is-admin">
                                    <span class="checkmark"></span>
                                    Предоставить права администратора
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Сохранить
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        const modal = document.getElementById('add-moderator-modal');
        modal.style.display = 'flex';
        
        // Обработчик закрытия
        modal.querySelector('.close-modal').onclick = () => {
            document.querySelector('.modal-overlay').remove();
        };
        
        // Обработчик формы
        document.getElementById('add-moderator-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const fio = document.getElementById('moderator-fio').value;
            const email = document.getElementById('moderator-email').value;
            const isAdmin = document.getElementById('moderator-is-admin').checked;
            
            try {
                const response = await fetch('api/add-moderator.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        fio: fio,
                        email: email,
                        is_admin: isAdmin
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Модератор успешно добавлен!');
                    document.querySelector('.modal-overlay').remove();
                    this.loadStats(); // Обновляем статистику
                } else {
                    alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Ошибка:', error);
                alert('Произошла ошибка при добавлении модератора');
            }
        });
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => DashboardAdmin.init());
