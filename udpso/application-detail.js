// application-detail.js
export class ApplicationDetail {
    static init() {
        const urlParams = new URLSearchParams(window.location.search);
        this.applicationId = urlParams.get('id');
        
        if (this.applicationId) {
            this.loadApplicationDetails();
        } else {
            this.showError('Не указан ID заявки');
        }
    }
    
    static async loadApplicationDetails() {
        try {
            const response = await fetch(`api/get-application-details.php?id=${this.applicationId}`);
            const data = await response.json();
            
            if (data.success) {
                this.renderApplicationDetails(data.application);
                this.setupActionButtons(data.application);
            } else {
                this.showError(data.error || 'Не удалось загрузить данные заявки');
            }
        } catch (error) {
            console.error('Ошибка:', error);
            this.showError('Ошибка загрузки данных заявки');
        }
    }
    
    static renderApplicationDetails(application) {
        // Заголовок и статус
        const displayName = application.full_name || '-';
        document.getElementById('application-title').textContent = displayName;
        const statusBadge = document.getElementById('application-status').querySelector('.status-badge');
        statusBadge.className = `status-badge status-${application.status}`;
        statusBadge.textContent = this.getStatusText(application.status);
        
        // Информация об организации
        document.getElementById('school-full-name').textContent = application.full_name || '-';
        document.getElementById('school-ogrn').textContent = application.ogrn || '-';
        document.getElementById('organization-registration-date').textContent = application.registration_date
            ? new Date(application.registration_date).toLocaleDateString()
            : '-';
        document.getElementById('school-address').textContent = application.address || '-';
        
        // Руководитель
        document.getElementById('director-fio').textContent = application.director_fio || '-';
        document.getElementById('director-position').textContent = application.director_position || '-';
        document.getElementById('director-inn').textContent = application.director_inn || '-';
        
        // Контакты
        document.getElementById('contact-phone').textContent = application.contact_phone || '-';
        document.getElementById('contact-email').textContent = application.contact_email || '-';
        
        // История
        document.getElementById('created-at').textContent = new Date(application.created_at).toLocaleString();
        
        if (application.processed_at) {
            document.getElementById('processed-at').textContent = new Date(application.processed_at).toLocaleString();
            document.getElementById('processed-row').style.display = 'flex';
        }
        
        if (application.processed_by_name) {
            document.getElementById('processed-by').textContent = application.processed_by_name;
            document.getElementById('processed-by-row').style.display = 'flex';
        }
    }
    
    static setupActionButtons(application) {
        const actionButtons = document.getElementById('action-buttons');
        
        if (application.status === 'pending') {
            actionButtons.innerHTML = `
                <button class="btn-action btn-reject" id="reject-application">
                    <i class="fas fa-times"></i> Отклонить
                </button>
                <button class="btn-action btn-approve" id="approve-application">
                    <i class="fas fa-check"></i> Одобрить
                </button>
            `;
            
            document.getElementById('reject-application').addEventListener('click', () => {
                this.processApplication('rejected');
            });
            
            document.getElementById('approve-application').addEventListener('click', () => {
                this.processApplication('approved');
            });
        } else if (application.status === 'rejected') {
            actionButtons.innerHTML = `
                <button class="btn-action btn-return" id="return-to-pending">
                    <i class="fas fa-undo"></i> Вернуть на рассмотрение
                </button>
            `;

            document.getElementById('return-to-pending').addEventListener('click', () => {
                const reason = prompt('Укажите причину возврата на рассмотрение:');
                if (reason === null || !reason.trim()) {
                    alert('Причина возврата обязательна');
                    return;
                }
                this.processApplication('pending', reason.trim());
            });
        }
    }
    
    static async processApplication(status, extraReason = '') {
        const confirmMessage = {
            approved: 'Вы уверены, что хотите одобрить эту заявку?',
            rejected: 'Вы уверены, что хотите отклонить эту заявку?',
            pending: 'Вы уверены, что хотите вернуть заявку на рассмотрение?'
        }[status];

        if (!confirm(confirmMessage)) return;

        let rejectionReason = '';
        let reconsiderReason = '';

        if (status === 'rejected') {
            rejectionReason = prompt('Укажите причину отказа:', '');
            if (rejectionReason === null || !rejectionReason.trim()) {
                alert('Причина отказа обязательна');
                return;
            }
        }

        if (status === 'pending') {
            reconsiderReason = extraReason || '';
            if (!reconsiderReason.trim()) {
                alert('Причина возврата обязательна');
                return;
            }
        }

        try {
            const response = await fetch('api/process-application.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: this.applicationId,
                    status: status,
                    rejection_reason: rejectionReason,
                    reconsider_reason: reconsiderReason
                })
            });

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                throw new Error(`Ожидался JSON, получено: ${text.substring(0, 100)}...`);
            }

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || `HTTP error! status: ${response.status}`);
            }

            if (data.success) {
                alert({
                    approved: 'Заявка успешно одобрена! Письмо с данными отправлено на email организации.',
                    rejected: 'Заявка отклонена. Письмо отправлено на email организации.',
                    pending: 'Заявка возвращена на рассмотрение. Письмо отправлено на email организации.'
                }[status]);

                // Обновляем интерфейс
                const badge = document.querySelector('#application-status .status-badge');
                badge.textContent = this.getStatusText(status);
                badge.className = `status-badge status-${status}`;

                document.getElementById('action-buttons').innerHTML = '';

                await this.loadApplicationDetails();
            } else {
                throw new Error(data.error || 'Ошибка обработки заявки');
            }
        } catch (error) {
            console.error('Ошибка:', error);
            alert(`Ошибка при обработке заявки: ${error.message}`);
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
        const container = document.querySelector('.application-detail-container');
        container.innerHTML = `
            <div class="card">
                <div class="card-body">
                    <div class="error-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        ${message}
                    </div>
                </div>
            </div>
        `;
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => ApplicationDetail.init());
