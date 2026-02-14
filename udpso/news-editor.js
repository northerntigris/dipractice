export class NewsEditor {
    static init() {
        document.addEventListener('click', (e) => {
            if (e.target.closest('.btn-edit')) {
                this.openEditModal(e.target.closest('.news-item'));
            }
            
            if (e.target.closest('.btn-delete')) {
                this.deleteNews(e.target.closest('.news-item'));
            }
        });

        const toolbar = document.querySelector('.news-editor-toolbar');
        if (toolbar) {
            toolbar.addEventListener('click', (event) => {
                const button = event.target.closest('[data-command]');
                if (!button) return;
                const command = button.dataset.command;
                if (!command) return;

                const editor = document.getElementById('news-content-editor');
                if (editor) {
                    editor.focus();
                }

                if (command === 'createLink') {
                    const url = window.prompt('Введите ссылку:', 'https://');
                    if (url) {
                        document.execCommand(command, false, url);
                    }
                    return;
                }

                const value = button.dataset.value || null;
                document.execCommand(command, false, value);
            });
        }
        
        const editForm = document.getElementById('edit-news-form');
        if (editForm) {
            editForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveNews();
            });
        }

        const filesInput = document.getElementById('news-files');
        if (filesInput) {
            filesInput.addEventListener('change', () => {
                this.renderFilePreview(filesInput.files);
            });
        }
    }
    
    static openEditModal(newsItem) {
        const modal = document.getElementById('edit-news-modal');
        if (!modal) {
            console.error('Modal not found!');
            return;
        }
        const modalTitle = modal.querySelector('#edit-news-title');
        if (modalTitle) {
            modalTitle.textContent = 'Редактирование новости';
        }

        const title = newsItem.querySelector('.news-title').textContent;
        const content = newsItem.querySelector('.news-content')?.innerHTML || '';
        const dateElement = newsItem.querySelector('.news-date');
        
        document.getElementById('news-id').value = newsItem.dataset.id || '';
        document.getElementById('news-title').value = title;
        const editor = document.getElementById('news-content-editor');
        if (editor) {
            editor.innerHTML = content;
        }
        const fileInput = document.getElementById('news-files');
        if (fileInput) {
            fileInput.value = '';
        }
        this.renderFilePreview([]);

        const dateInput = document.getElementById('news-date');
        if (dateInput && dateElement) {
            try {
                const dateText = dateElement.textContent.trim();
                // Парсим дату из текста (формат: "15.04.2023")
                const dateParts = dateText.split(' ').pop().split('.');
                const formattedDate = `${dateParts[2]}-${dateParts[1].padStart(2, '0')}-${dateParts[0].padStart(2, '0')}`;
                dateInput.value = formattedDate;
            } catch (e) {
                console.error('Error parsing date:', e);
                dateInput.value = new Date().toISOString().split('T')[0];
            }
        }
        
        modal.style.display = 'flex'; // Показываем модалку

        // Обработчик закрытия по крестику
        modal.querySelector('.close-modal-edit-news').onclick = () => {
            modal.style.display = 'none';
        };

        // Закрытие по клику вне модалки
        modal.onclick = (e) => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        };
    }

    static openCreateModal() {
        const modal = document.getElementById('edit-news-modal');
        if (!modal) {
            console.error('Modal not found!');
            return;
        }

        const modalTitle = modal.querySelector('#edit-news-title');
        if (modalTitle) {
            modalTitle.textContent = 'Добавление новости';
        }

        document.getElementById('news-id').value = '';
        document.getElementById('news-title').value = '';
        const editor = document.getElementById('news-content-editor');
        if (editor) {
            editor.innerHTML = '';
        }
        const dateInput = document.getElementById('news-date');
        if (dateInput) {
            dateInput.value = new Date().toISOString().split('T')[0];
        }
        const fileInput = document.getElementById('news-files');
        if (fileInput) {
            fileInput.value = '';
        }
        this.renderFilePreview([]);

        modal.style.display = 'flex';
        modal.querySelector('.close-modal-edit-news').onclick = () => {
            modal.style.display = 'none';
        };
        modal.onclick = (e) => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        };
    }
    
    static saveNews() {
        const id = document.getElementById('news-id').value;
        const title = document.getElementById('news-title').value;
        const editor = document.getElementById('news-content-editor');
        const content = editor ? editor.innerHTML.trim() : '';
        const date = document.getElementById('news-date')?.value || new Date().toISOString();

        const formData = new FormData();
        formData.append('id', id);
        formData.append('title', title);
        formData.append('content', content);
        formData.append('date', date);
        const filesInput = document.getElementById('news-files');
        if (filesInput && filesInput.files.length > 0) {
            Array.from(filesInput.files).forEach(file => {
                formData.append('files[]', file);
            });
        }

        fetch('news.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Ошибка сохранения: ' + (data.error || 'Неизвестная ошибка'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка сохранения: ' + error.message);
        });
    }
    
    static deleteNews(newsItem) {
        if (!confirm('Вы уверены, что хотите удалить эту новость?')) return;

        const id = newsItem.dataset.id;
        if (!id) {
            console.error('No ID for news item');
            return;
        }
 
        fetch(`news.php?id=${id}`, {
            method: 'DELETE'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                newsItem.remove();
            } else {
                alert('Ошибка удаления: ' + (data.error || 'Неизвестная ошибка'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка удаления: ' + error.message);
        });
    }

    static renderFilePreview(files) {
        const preview = document.getElementById('news-file-preview');
        if (!preview) return;

        const list = Array.from(files || []);
        if (!list.length) {
            preview.innerHTML = '';
            return;
        }

        preview.innerHTML = list.map(file => `
            <div class="news-file-preview__item">
                <div class="news-file-preview__info">
                    <i class="fas fa-paperclip"></i>
                    <span>${file.name}</span>
                </div>
                <span class="news-file-preview__meta">${this.formatFileSize(file.size)}</span>
            </div>
        `).join('');
    }

    static formatFileSize(bytes) {
        if (!bytes) return '0 Б';
        const units = ['Б', 'КБ', 'МБ', 'ГБ'];
        let size = bytes;
        let unitIndex = 0;
        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024;
            unitIndex += 1;
        }
        return `${size.toFixed(size >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
    }
}
