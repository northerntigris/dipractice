// application-modal.js
export class ApplicationModal {
    static async open(applicationId, callback) {
        try {
            const response = await fetch(`api/get-application-details.php?id=${applicationId}`);
            const data = await response.json();
            
            if (data.success) {
                this.showModal(data.application, callback);
            } else {
                console.error('Ошибка загрузки данных заявки:', data.error);
                alert('Не удалось загрузить данные заявки');
            }
        } catch (error) {
            console.error('Ошибка:', error);
            alert('Ошибка загрузки данных заявки');
        }
    }

    static showModal(application, callback) {
        const modal = document.getElementById('application-modal');
        const modalBody = document.getElementById('application-detail');
        
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
        document.getElementById('approve-application').onclick = () => this.processApplication('approved', callback);
        document.getElementById('reject-application').onclick = () => this.processApplication('rejected', callback);
        
        // Показываем модальное окно
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden'; // Блокируем скролл страницы
        
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

    static async processApplication(status, callback) {
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
                
                // Закрываем модальное окно
                document.getElementById('application-modal').style.display = 'none';
                
                // Вызываем callback для обновления данных
                if (typeof callback === 'function') {
                    callback();
                }
            } else {
                alert(data.error || 'Ошибка обработки заявки');
            }

        document.body.style.overflow = 'auto'; // Возвращаем скролл
        } catch (error) {
            console.error('Ошибка:', error);
            alert('Ошибка при обработке заявки');
        }
    }
}
