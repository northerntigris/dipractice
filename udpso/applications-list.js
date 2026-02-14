// applications-list.js
export class ApplicationsList {
    static init() {
        this.badgeElement = document.getElementById('applications-badge');
        this.dropdownElement = document.getElementById('applications-dropdown');
        this.listElement = document.getElementById('applications-list');
        this.refreshBtn = document.getElementById('refresh-applications');
        
        if (this.badgeElement && this.dropdownElement && this.listElement) {
            this.loadApplications();
            this.setupEventListeners();
        }
    }
    
    static setupEventListeners() {
        // Закрытие dropdown при клике вне его
        document.addEventListener('click', (e) => {
            if (!this.badgeElement.contains(e.target)) {
                this.hideDropdown();
            }
        });

        // Кнопка обновления
        this.refreshBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            this.loadApplications();
        });

        // Обработка клика по бейджу
        this.badgeElement?.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleDropdown();
        });
    }

    static toggleDropdown() {
        if (this.dropdownElement.style.opacity === '1') {
            this.hideDropdown();
        } else {
            this.showDropdown();
        }
    }

    static showDropdown() {
        this.dropdownElement.style.opacity = '1';
        this.dropdownElement.style.visibility = 'visible';
        this.dropdownElement.style.transform = 'translateY(0)';
    }

    static hideDropdown() {
        this.dropdownElement.style.opacity = '0';
        this.dropdownElement.style.visibility = 'hidden';
        this.dropdownElement.style.transform = 'translateY(10px)';
    }
    
    static async loadApplications() {
        this.listElement.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></div>';
        
        try {
            const response = await fetch('api/get-applications.php?status=pending&limit=5');
            const data = await response.json();
            
            if (data.success) {
                this.renderApplications(data.applications);
                this.updateBadge(data.total_pending);
            } else {
                this.showError(data.error || 'Не удалось загрузить заявки');
            }
        } catch (error) {
            console.error('Ошибка:', error);
            this.showError('Ошибка загрузки данных');
        }
    }
    
    static renderApplications(applications) {
        if (applications.length === 0) {
            this.listElement.innerHTML = '<div class="empty-message">Нет заявок</div>';
            return;
        }

        this.listElement.innerHTML = applications.map(app => `
            <div class="application-item" data-id="${app.id}">
                <i class="fas fa-school application-icon"></i>
                <div class="application-info">
                    <div class="application-school">${app.short_name || app.full_name || '-'}</div>
                    <div class="application-date">
                        ${new Date(app.created_at).toLocaleDateString()}
                    </div>
                </div>
                <div class="application-status status-${app.status}">
                    ${this.getStatusText(app.status)}
                </div>
                ${app.status === 'pending' ? `
                <div class="application-actions">
                    <button class="btn-process" data-action="process">
                        <i class="fas fa-edit"></i>
                    </button>
                </div>
                ` : ''}
            </div>
        `).join('');

        // Добавляем обработчики для кнопок обработки
        document.querySelectorAll('.btn-process[data-action="process"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const applicationId = btn.closest('.application-item').dataset.id;
                this.openApplicationModal(applicationId);
            });
        });

        // Обработчик клика по заявке
        document.querySelectorAll('.application-item').forEach(item => {
            item.addEventListener('click', () => {
                window.location.href = `application-detail.html?id=${item.dataset.id}`;
            });
        });
    }
    
    static async openApplicationModal(applicationId) {
        try {
            const response = await fetch(`api/get-application-details.php?id=${applicationId}`);
            const data = await response.json();
            
            if (data.success) {
                this.showApplicationModal(data.application);
            } else {
                alert(data.error || 'Не удалось загрузить данные заявки');
            }
        } catch (error) {
            console.error('Ошибка:', error);
            alert('Ошибка загрузки данных заявки');
        }
    }

    static showApplicationModal(application) {
        const modal = document.getElementById('application-modal');
        const modalBody = document.getElementById('application-details');
        
        modalBody.innerHTML = `
            <div class="application-detail">
                <label>Название организации:</label>
                <div>${application.full_name}</div>
            </div>
            <div class="application-detail">
                <label>Руководитель:</label>
                <div>${application.director_fio} (${application.director_position})</div>
            </div>
            <div class="application-detail">
                <label>Контактные данные:</label>
                <div>${application.contact_phone}, ${application.contact_email}</div>
            </div>
            <div class="application-detail">
                <label>Дата подачи:</label>
                <div>${new Date(application.created_at).toLocaleString()}</div>
            </div>
            <input type="hidden" id="current-application-id" value="${application.id}">
        `;
        
        // Обработчики для кнопок
        document.getElementById('approve-application').onclick = () => this.processApplication('approved');
        document.getElementById('reject-application').onclick = () => this.processApplication('rejected');
        
        // Показываем модальное окно
        modal.style.display = 'flex';
        
        // Закрытие по крестику
        modal.querySelector('.close-modal').onclick = () => {
            modal.style.display = 'none';
        };
        
        // Закрытие по клику вне окна
        modal.onclick = (e) => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        };
    }

    static async processApplication(status) {
        const applicationId = document.getElementById('current-application-id').value;
        const confirmMessage = status === 'approved' 
            ? 'Вы уверены, что хотите одобрить эту заявку?' 
            : 'Вы уверены, что хотите отклонить эту заявку?';
        
        if (!confirm(confirmMessage)) return;
        
        try {
            const response = await fetch('api/process-application.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: applicationId,
                    status: status
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert(status === 'approved' 
                    ? 'Заявка успешно одобрена!' 
                    : 'Заявка отклонена.');
                
                // Закрываем модальное окно и обновляем список
                document.getElementById('application-modal').style.display = 'none';
                this.loadApplications();
                
                // Обновляем статистику на дашборде
                document.getElementById('pending-applications').textContent = data.total_pending;
            } else {
                alert(data.error || 'Ошибка обработки заявки');
            }
        } catch (error) {
            console.error('Ошибка:', error);
            alert('Ошибка при обработке заявки');
        }
    }
    
    static updateBadge(count) {
        if (!this.badgeElement) {
            this.badgeElement = document.getElementById('applications-badge');
        }
        if (!this.badgeElement) return;

        const badgeCount = this.badgeElement.querySelector('.badge-count');
        if (badgeCount) {
            badgeCount.textContent = count;
            badgeCount.style.display = count > 0 ? 'flex' : 'none';
        }
    }
    
    static getStatusText(status) {
        const statusText = {
            'pending': 'На рассмотрении',
            'approved': 'Одобрена',
            'rejected': 'Отклонена'
        };
        return statusText[status] || status;
    }

    static showError(message) {
        this.listElement.innerHTML = `<div class="error-message">${message}</div>`;
    }
}
