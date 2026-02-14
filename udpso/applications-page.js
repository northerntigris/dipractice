import { ApplicationModal } from "./application-modal.js";

export class ApplicationsPage {
    static init() {
        this.currentPage = 1;
        this.itemsPerPage = 25;
        this.currentStatus = null;
        
        this.setupEventListeners();
        this.loadApplications();
    }
    
    static setupEventListeners() {
        // Фильтр по статусу
        document.getElementById('status-filter').addEventListener('change', (e) => {
            this.currentStatus = e.target.value === 'all' ? null : e.target.value;
            this.currentPage = 1;
            this.loadApplications();
        });
        
        // Кнопка обновления
        document.getElementById('refresh-applications').addEventListener('click', () => {
            this.loadApplications();
        });
        
        // Пагинация
        document.getElementById('prev-page').addEventListener('click', () => {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.loadApplications();
            }
        });
        
        document.getElementById('next-page').addEventListener('click', () => {
            // Проверяем возможность перехода вперед в loadApplications
            this.currentPage++;
            this.loadApplications();
        });
        
        // Количество элементов на странице
        document.getElementById('items-per-page').addEventListener('change', (e) => {
            this.itemsPerPage = parseInt(e.target.value);
            this.currentPage = 1;
            this.loadApplications();
        });
    }
    
    static async loadApplications() {
        const tableBody = document.getElementById('applications-list');
        tableBody.innerHTML = `
            <tr>
                <td colspan="6" class="loading-row">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i> Загрузка данных...
                    </div>
                </td>
            </tr>
        `;
        
        try {
            let url = `api/get-all-applications.php?page=${this.currentPage}&per_page=${this.itemsPerPage}`;
            if (this.currentStatus) {
                url += `&status=${this.currentStatus}`;
            }
            
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                this.renderApplications(data.applications);
                this.updatePagination(data);
            } else {
                this.showError(data.error || 'Не удалось загрузить заявки');
            }
        } catch (error) {
            console.error('Ошибка загрузки заявок:', error);
            this.showError('Ошибка соединения с сервером. Пожалуйста, попробуйте позже.');
        }
    }
    
    static renderApplications(applications) {
        const tableBody = document.getElementById('applications-list');
        
        if (applications.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="6" class="empty-row">
                        <i class="fas fa-info-circle"></i> Нет заявок, соответствующих выбранным критериям
                    </td>
                </tr>
            `;
            return;
        }
        
        tableBody.innerHTML = applications.map(app => `
            <tr data-id="${app.id}">
                <td>#${app.id}</td>
                <td>
                    <div class="school-name">${app.short_name || app.full_name || '-'}</div>
                    <div class="school-full-name">${app.full_name || '-'}</div>
                </td>
                <td>
                    <div class="director-name">${app.director_fio || '-'}</div>
                    <div class="director-position">${app.director_position || '-'}</div>
                </td>
                <td>${new Date(app.created_at).toLocaleDateString()}</td>
                <td>
                    <span class="status-badge status-${app.status}">
                        ${this.getStatusText(app.status)}
                    </span>
                </td>
                <td>
                    <button class="action-btn view" data-action="view" title="Просмотр">
                        <i class="fas fa-eye"></i>
                    </button>
                    ${app.status === 'pending' ? `
                    <button class="action-btn process" data-action="process" title="Обработать">
                        <i class="fas fa-edit"></i>
                    </button>
                    ` : ''}
                </td>
            </tr>
        `).join('');
        
        // Добавляем обработчики событий
        this.addEventHandlers();
    }
    
    static addEventHandlers() {
        // Обработчики для кнопок действий
        document.querySelectorAll('.action-btn[data-action="view"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const applicationId = btn.closest('tr').dataset.id;
                window.location.href = `application-detail.html?id=${applicationId}`;
            });
        });
        
        document.querySelectorAll('.action-btn[data-action="process"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const applicationId = btn.closest('tr').dataset.id;
                ApplicationModal.open(applicationId, () => this.loadApplications());
            });
        });
        
        // Клик по строке - переход к деталям
        document.querySelectorAll('tbody tr').forEach(row => {
            row.addEventListener('click', (e) => {
                if (!e.target.closest('.action-btn')) {
                    window.location.href = `application-detail.html?id=${row.dataset.id}`;
                }
            });
        });
    }
    
    static updatePagination(data) {
        const pageInfo = document.getElementById('page-info');
        const prevBtn = document.getElementById('prev-page');
        const nextBtn = document.getElementById('next-page');
        
        pageInfo.textContent = `Страница ${data.current_page} из ${data.last_page}`;
        prevBtn.disabled = data.current_page <= 1;
        nextBtn.disabled = data.current_page >= data.last_page;
        
        // Корректируем текущую страницу, если она вышла за пределы
        if (data.current_page > data.last_page && data.last_page > 0) {
            this.currentPage = data.last_page;
            this.loadApplications();
        }
    }
    
    static showError(message) {
        const tableBody = document.getElementById('applications-list');
        tableBody.innerHTML = `
            <tr>
                <td colspan="6" class="error-row">
                    <i class="fas fa-exclamation-triangle"></i> ${message}
                </td>
            </tr>
        `;
    }
    
    static getStatusText(status) {
        const statusText = {
            'pending': 'На рассмотрении',
            'approved': 'Одобрена',
            'rejected': 'Отклонена'
        };
        return statusText[status] || status;
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => ApplicationsPage.init());
