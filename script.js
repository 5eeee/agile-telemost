// script.js — клиентское SPA для Agile.Telemost
// Использует LiveKit Client SDK (предполагается подключение через CDN в index.html)
// Для удобства добавим в начале файла импорт стилей не требуется, они уже в style.css

// Глобальные переменные состояния
let currentUser = null;               // объект пользователя после авторизации
let accessToken = localStorage.getItem('accessToken') || null;
let departments = [];                 // список всех отделов (для админа)
let userDepartments = [];             // отделы текущего пользователя
let wsConnection = null;              // WebSocket соединение
let liveKitRoom = null;               // объект комнаты LiveKit (при входе в конфу)

// Константы
const API_BASE = '/api.php';          // базовый путь API (относительный)
const WS_URL = 'ws://' + window.location.host + '/ws.php'; // WebSocket URL (можно wss://)

// Элементы приложения
const app = document.getElementById('app');

// ================== Утилиты ==================
function apiRequest(action, method = 'GET', data = null, auth = true) {
    const url = `${API_BASE}?action=${action}`;
    const headers = {
        'Content-Type': 'application/json',
    };
    if (auth && accessToken) {
        headers['Authorization'] = 'Bearer ' + accessToken;
    }
    const options = {
        method,
        headers,
    };
    if (data && (method === 'POST' || method === 'PUT')) {
        options.body = JSON.stringify(data);
    }
    return fetch(url, options).then(res => res.json());
}

function showLoading() {
    // Показать полноэкранный логотип на 1-2 секунды (эмулируем)
    // В реальности можно показывать спиннер, но по ТЗ — логотип компании.
    // При первой загрузке (не авторизован) показываем стандартный лого.
    // Если пользователь загрузил аватар, он заменит лого в следующих загрузках.
    // Здесь упростим: показываем div с логотипом на 1.5 сек.
    return new Promise(resolve => {
        const loader = document.createElement('div');
        loader.style.position = 'fixed';
        loader.style.top = 0;
        loader.style.left = 0;
        loader.style.width = '100vw';
        loader.style.height = '100vh';
        loader.style.backgroundColor = '#FFFFFF';
        loader.style.display = 'flex';
        loader.style.alignItems = 'center';
        loader.style.justifyContent = 'center';
        loader.style.zIndex = 9999;
        // Вместо img используем текст или можно вставить картинку
        const logo = document.createElement('img');
        // Если у пользователя есть аватар, используем его (но после авторизации)
        if (currentUser && currentUser.avatar) {
            logo.src = currentUser.avatar;
        } else {
            logo.src = '/uploads/default-logo.png'; // заглушка
        }
        logo.style.maxHeight = '120px';
        loader.appendChild(logo);
        document.body.appendChild(loader);
        setTimeout(() => {
            loader.remove();
            resolve();
        }, 1500);
    });
}

// ================== Роутинг ==================
const routes = {
    '/': 'home',
    '/recordings': 'recordings',
    '/scheduled': 'scheduled',
    '/profile': 'profile',
    '/admin': 'admin',
    '/room/:id': 'room',          // страница конференции
};

function navigate(path) {
    history.pushState({}, '', path);
    renderPage();
}

async function renderPage() {
    const path = window.location.pathname;
    // Проверка аутентификации: если нет токена и путь не /login (но у нас отдельной страницы логина нет, предположим что мы всегда показываем либо главную с формой входа, либо переходим к логину)
    // Упростим: если нет пользователя, показываем страницу входа (форма email/пароль или SSO)
    if (!currentUser && path !== '/login') {
        // Пытаемся восстановить сессию по токену
        if (accessToken) {
            try {
                const userData = await apiRequest('me', 'GET', null, true);
                if (userData && userData.id) {
                    currentUser = userData;
                    userDepartments = userData.departments || [];
                } else {
                    // токен не валидный
                    accessToken = null;
                    localStorage.removeItem('accessToken');
                }
            } catch (e) {
                accessToken = null;
                localStorage.removeItem('accessToken');
            }
        }
        if (!currentUser) {
            renderLoginPage();
            return;
        }
    }

    // После успешной аутентификации загружаем отделы (если нужно)
    if (!departments.length) {
        // Запросить список отделов (для администратора может быть полезно)
        // Но можем и не загружать сразу.
    }

    // Определяем страницу по маршруту
    if (path === '/') {
        renderHomePage();
    } else if (path === '/recordings') {
        renderRecordingsPage();
    } else if (path === '/scheduled') {
        renderScheduledPage();
    } else if (path === '/profile') {
        renderProfilePage();
    } else if (path === '/admin') {
        if (currentUser.role === 'admin') {
            renderAdminPage();
        } else {
            navigate('/');
        }
    } else if (path.startsWith('/room/')) {
        const roomId = path.split('/')[2];
        renderRoomPage(roomId);
    } else {
        // 404
        renderNotFound();
    }
}

// ================== Отрисовка страниц ==================
function renderLayout(contentHTML) {
    // Базовый макет с сайдбаром и хедером
    const sidebar = `
        <div class="sidebar">
            <div class="sidebar-top">
                <div class="sidebar-icon" data-route="/">
                    <svg viewBox="0 0 24 24"><path d="M12 3L2 12h3v8h6v-6h4v6h6v-8h3L12 3z"/></svg>
                    <span class="tooltip">Главная</span>
                </div>
                <div class="sidebar-icon" data-route="/recordings">
                    <svg viewBox="0 0 24 24"><path d="M4 6h16v12H4V6zm2 2v8h12V8H6z"/></svg>
                    <span class="tooltip">Записи и история</span>
                </div>
                <div class="sidebar-icon" data-route="/scheduled">
                    <svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10z"/></svg>
                    <span class="tooltip">Планируемые</span>
                </div>
            </div>
            <div class="sidebar-bottom">
                <div class="sidebar-icon" data-route="/profile">
                    <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                    <span class="tooltip">Личный кабинет</span>
                </div>
                <div class="sidebar-icon" id="logout-btn">
                    <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.59L17 17l5-5-5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                    <span class="tooltip">Выход</span>
                </div>
            </div>
        </div>
    `;

    const header = `
        <header class="header">
            <img src="${currentUser?.avatar || '/uploads/default-logo.png'}" alt="Agile.Telemost" class="header-logo">
        </header>
    `;

    app.innerHTML = sidebar + `
        <div class="main-content">
            ${header}
            <div class="page-container" id="page-content">
                ${contentHTML}
            </div>
        </div>
    `;

    // Привязка событий к иконкам сайдбара
    document.querySelectorAll('.sidebar-icon[data-route]').forEach(icon => {
        icon.addEventListener('click', (e) => {
            const route = icon.dataset.route;
            navigate(route);
        });
    });
    document.getElementById('logout-btn').addEventListener('click', logout);
}

// ----- Страница входа -----
function renderLoginPage() {
    const html = `
        <div style="max-width: 400px; margin: 100px auto; text-align: center;">
            <h1>Agile.Telemost</h1>
            <p>Вход в сервис видеоконференций</p>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" id="login-email" class="input-field" placeholder="email@company.ru">
            </div>
            <div class="form-group">
                <label class="form-label">Пароль</label>
                <input type="password" id="login-password" class="input-field" placeholder="••••••••">
            </div>
            <button class="btn btn-primary" id="login-btn" style="width:100%;">Войти</button>
            <p style="margin-top:20px;">Или используйте корпоративную SSO</p>
            <button class="btn btn-outline" id="sso-btn">Войти через SSO</button>
        </div>
    `;
    app.innerHTML = html;
    document.getElementById('login-btn').addEventListener('click', loginWithPassword);
    document.getElementById('sso-btn').addEventListener('click', loginWithSSO);
}

async function loginWithPassword() {
    const email = document.getElementById('login-email').value;
    const password = document.getElementById('login-password').value;
    const result = await apiRequest('login', 'POST', { email, password }, false);
    if (result.token) {
        accessToken = result.token;
        localStorage.setItem('accessToken', accessToken);
        currentUser = result.user;
        userDepartments = result.user.departments || [];
        await showLoading();
        navigate('/');
    } else {
        alert('Ошибка входа: ' + (result.error || 'неверные данные'));
    }
}

function loginWithSSO() {
    alert('SSO заглушка — редирект на корпоративный IdP');
}

function logout() {
    accessToken = null;
    localStorage.removeItem('accessToken');
    currentUser = null;
    if (wsConnection) wsConnection.close();
    navigate('/login');
}

// ----- Главная страница -----
function renderHomePage() {
    // Загружаем список текущих конференций (активные комнаты доступные пользователю)
    apiRequest('rooms/active', 'GET').then(data => {
        const rooms = data.rooms || [];
        let roomsHtml = rooms.map(room => `
            <div class="card">
                <div class="card-title">${room.name}</div>
                <div class="card-meta">${room.department_name} · ${room.participants_count} участников</div>
                <button class="btn btn-outline join-room-btn" data-room-id="${room.id}">Присоединиться</button>
            </div>
        `).join('') || '<p>Нет активных конференций</p>';

        const html = `
            <h1>Главная</h1>
            <div class="button-group">
                <button class="btn btn-primary" id="create-room-btn">Создать конференцию</button>
                <div class="join-row">
                    <input type="text" class="input-field" id="room-id-input" placeholder="ID или ссылка конференции">
                    <button class="btn btn-outline" id="join-room-by-id-btn">Войти</button>
                </div>
                <button class="btn btn-outline" id="schedule-room-btn">Запланировать конференцию</button>
            </div>
            <h2>Текущие конференции</h2>
            <div class="card-grid" id="rooms-grid">
                ${roomsHtml}
            </div>
        `;
        renderLayout(html);
        document.getElementById('create-room-btn').addEventListener('click', () => openRoomModal('create'));
        document.getElementById('schedule-room-btn').addEventListener('click', () => openRoomModal('schedule'));
        document.getElementById('join-room-by-id-btn').addEventListener('click', joinRoomById);
        document.querySelectorAll('.join-room-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const roomId = e.target.dataset.roomId;
                navigate('/room/' + roomId);
            });
        });
    });
}

function joinRoomById() {
    const input = document.getElementById('room-id-input').value.trim();
    if (!input) return;
    // Извлечь ID из ссылки, если это полная ссылка
    let roomId = input;
    if (input.includes('/room/')) {
        const parts = input.split('/room/');
        roomId = parts[1].split('/')[0];
    }
    navigate('/room/' + roomId);
}

// Модальное окно создания/планирования (упрощенно без модалки, просто форма на странице или отдельный рендер)
function openRoomModal(type) {
    // В реальном проекте лучше сделать модальное окно, но для краткости отрендерим форму вместо текущей страницы
    const departmentsOptions = userDepartments.map(d => `<option value="${d.id}">${d.name}</option>`).join('');
    const html = `
        <div style="max-width: 500px; margin: 0 auto;">
            <h2>${type === 'create' ? 'Создать конференцию' : 'Запланировать конференцию'}</h2>
            <div class="form-group">
                <label class="form-label">Название конференции *</label>
                <input type="text" id="room-name" class="input-field">
            </div>
            <div class="form-group">
                <label class="form-label">Направление</label>
                <select id="room-department" class="input-field">
                    ${departmentsOptions}
                </select>
            </div>
            ${type === 'schedule' ? `
            <div class="form-group">
                <label class="form-label">Дата и время начала</label>
                <input type="datetime-local" id="room-start" class="input-field">
            </div>
            ` : ''}
            <div class="form-group">
                <label class="form-label">Описание (необязательно)</label>
                <textarea id="room-description" class="input-field" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Пароль (необязательно)</label>
                <input type="text" id="room-password" class="input-field">
            </div>
            <div class="button-group">
                <button class="btn btn-primary" id="submit-room-btn">${type === 'create' ? 'Создать и перейти' : 'Запланировать'}</button>
                <button class="btn btn-outline" id="cancel-room-btn">Отмена</button>
            </div>
        </div>
    `;
    // Временно заменяем содержимое page-content
    const pageContent = document.getElementById('page-content');
    pageContent.innerHTML = html;
    document.getElementById('submit-room-btn').addEventListener('click', () => submitRoom(type));
    document.getElementById('cancel-room-btn').addEventListener('click', () => renderHomePage());
}

async function submitRoom(type) {
    const name = document.getElementById('room-name').value;
    const departmentId = document.getElementById('room-department').value;
    const description = document.getElementById('room-description').value;
    const password = document.getElementById('room-password').value;
    const startTime = type === 'schedule' ? document.getElementById('room-start').value : null;

    if (!name) return alert('Введите название');

    const data = {
        name,
        department_id: departmentId,
        description,
        password,
        scheduled: type === 'schedule' ? startTime : null
    };
    const result = await apiRequest('rooms/create', 'POST', data);
    if (result.room_id) {
        if (type === 'create') {
            navigate('/room/' + result.room_id);
        } else {
            alert('Конференция запланирована');
            navigate('/scheduled');
        }
    } else {
        alert('Ошибка: ' + (result.error || 'неизвестная ошибка'));
    }
}

// ----- Записи и история -----
function renderRecordingsPage() {
    apiRequest('recordings', 'GET').then(data => {
        const recordings = data.recordings || [];
        let rows = recordings.map(rec => `
            <tr>
                <td>${rec.date}</td>
                <td>${rec.time}</td>
                <td>${rec.name}</td>
                <td>${rec.department_name}</td>
                <td>
                    <button class="btn btn-outline watch-recording" data-url="${rec.url}">Смотреть запись</button>
                    <button class="btn btn-outline info-recording" data-id="${rec.id}">Информация</button>
                </td>
            </tr>
        `).join('');
        const html = `
            <h1>Записи и история</h1>
            <table class="table">
                <thead>
                    <tr><th>Дата</th><th>Время</th><th>Название</th><th>Направление</th><th>Действия</th></tr>
                </thead>
                <tbody>
                    ${rows || '<tr><td colspan="5">Нет записей</td></tr>'}
                </tbody>
            </table>
        `;
        renderLayout(html);
    });
}

// ----- Планируемые -----
function renderScheduledPage() {
    apiRequest('rooms/scheduled', 'GET').then(data => {
        const rooms = data.rooms || [];
        let html = '<h1>Планируемые конференции</h1>';
        if (rooms.length) {
            html += '<div class="card-grid">';
            rooms.forEach(room => {
                html += `
                    <div class="card">
                        <div class="card-title">${room.name}</div>
                        <div class="card-meta">${room.department_name} · ${room.start_time}</div>
                        <p>${room.description || ''}</p>
                        <div class="button-group">
                            <button class="btn btn-outline edit-room" data-id="${room.id}">Редактировать</button>
                            <button class="btn btn-outline cancel-room" data-id="${room.id}">Отменить</button>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
        } else {
            html += '<p>Нет запланированных конференций</p>';
        }
        renderLayout(html);
    });
}

// ----- Личный кабинет -----
function renderProfilePage() {
    const html = `
        <h1>Личный кабинет</h1>
        <div style="max-width: 500px;">
            <div class="form-group">
                <label class="form-label">Имя</label>
                <input type="text" id="profile-name" class="input-field" value="${currentUser.name || ''}">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" id="profile-email" class="input-field" value="${currentUser.email || ''}" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Пароль (оставьте пустым, если не меняете)</label>
                <input type="password" id="profile-password" class="input-field" placeholder="новый пароль">
            </div>
            <div class="form-group">
                <label class="form-label">Аватар</label>
                <input type="file" id="profile-avatar" accept="image/*">
                <img src="${currentUser.avatar || '/uploads/default-logo.png'}" style="max-width: 100px; margin-top:10px;" id="avatar-preview">
            </div>
            <div class="form-group">
                <label class="form-label">Мои отделы</label>
                <div>${userDepartments.map(d => `<span class="badge">${d.name}</span>`).join(' ')}</div>
            </div>
            <div class="form-group">
                <label class="form-label">Уведомления</label>
                <input type="checkbox" id="notify-email" ${currentUser.notify_email ? 'checked' : ''}> Email
            </div>
            <button class="btn btn-primary" id="save-profile">Сохранить</button>
        </div>
    `;
    renderLayout(html);
    document.getElementById('save-profile').addEventListener('click', saveProfile);
}

async function saveProfile() {
    const name = document.getElementById('profile-name').value;
    const password = document.getElementById('profile-password').value;
    const notifyEmail = document.getElementById('notify-email').checked;
    // Загрузка аватара (form data)
    const formData = new FormData();
    formData.append('name', name);
    if (password) formData.append('password', password);
    formData.append('notify_email', notifyEmail);
    const fileInput = document.getElementById('profile-avatar');
    if (fileInput.files[0]) {
        formData.append('avatar', fileInput.files[0]);
    }
    const res = await fetch(API_BASE + '?action=updateProfile', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + accessToken },
        body: formData
    });
    const result = await res.json();
    if (result.success) {
        currentUser = result.user;
        alert('Профиль обновлён');
    } else {
        alert('Ошибка');
    }
}

// ----- Административная панель (упрощённо) -----
function renderAdminPage() {
    apiRequest('admin/users', 'GET').then(data => {
        const users = data.users || [];
        let rows = users.map(u => `
            <tr>
                <td>${u.id}</td>
                <td>${u.name}</td>
                <td>${u.email}</td>
                <td>${u.role}</td>
                <td>${(u.departments || []).map(d => d.name).join(', ')}</td>
                <td>${u.blocked ? 'Заблокирован' : 'Активен'}</td>
                <td>
                    <button class="btn btn-outline edit-user" data-id="${u.id}">Ред.</button>
                    <button class="btn btn-outline block-user" data-id="${u.id}">${u.blocked ? 'Разблок.' : 'Блок'}</button>
                </td>
            </tr>
        `).join('');
        const html = `
            <h1>Управление пользователями</h1>
            <button class="btn btn-primary" id="add-user-btn">+ Добавить пользователя</button>
            <table class="table">
                <thead><tr><th>ID</th><th>Имя</th><th>Email</th><th>Роль</th><th>Отделы</th><th>Статус</th><th>Действия</th></tr></thead>
                <tbody>${rows || '<tr><td colspan="7">Нет пользователей</td></tr>'}</tbody>
            </table>
        `;
        renderLayout(html);
    });
}

// ----- Страница комнаты (конференции) -----
function renderRoomPage(roomId) {
    // Загружаем информацию о комнате, получаем токен для LiveKit
    apiRequest('room/join', 'POST', { room_id: roomId }).then(async data => {
        if (data.error) {
            alert('Ошибка доступа: ' + data.error);
            navigate('/');
            return;
        }
        const { room, livekit_token, ws_token } = data;
        // Сохраняем токены
        sessionStorage.setItem('livekit_token_' + roomId, livekit_token);
        // Подключаемся к LiveKit (если SDK загружен)
        // Здесь должна быть интеграция с LiveKit Client SDK
        // Пока заглушка
        const html = `
            <div style="display: flex; flex-direction: column; height: 100%;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h1>${room.name}</h1>
                    <div>
                        <button class="btn btn-outline" id="leave-room-btn">Покинуть</button>
                        ${currentUser.role === 'admin' || currentUser.id === room.created_by ? `<button class="btn btn-primary" id="end-room-btn">Завершить для всех</button>` : ''}
                    </div>
                </div>
                <div style="display: flex; flex: 1; gap: 20px;">
                    <div style="flex: 3; background: #f0f0f0; border-radius: var(--radius); display: flex; align-items: center; justify-content: center;">
                        <p>Видео участников (LiveKit)</p>
                        <!-- Здесь будут видеоэлементы -->
                    </div>
                    <div style="flex: 1; border-left: 1px solid var(--gray-border); padding-left: 20px;">
                        <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                            <button class="btn btn-outline" id="mic-toggle">Микрофон</button>
                            <button class="btn btn-outline" id="cam-toggle">Камера</button>
                            <button class="btn btn-outline" id="screen-share">Экран</button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <h3>Участники</h3>
                            <div id="participants-list">Загрузка...</div>
                        </div>
                        <div>
                            <h3>Чат</h3>
                            <div id="chat-messages" style="height: 200px; overflow-y: auto; border:1px solid var(--gray-border); border-radius: var(--radius-small); padding: 10px;"></div>
                            <div style="display: flex; margin-top: 10px;">
                                <input type="text" id="chat-input" class="input-field" placeholder="Сообщение...">
                                <button class="btn btn-primary" id="chat-send">Отправить</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        renderLayout(html);
        // Подключаем WebSocket для чата и сигналинга
        connectWebSocket(roomId, ws_token);
        // Инициализируем LiveKit
        initLiveKit(roomId, livekit_token);
    });
}

function connectWebSocket(roomId, token) {
    if (wsConnection) wsConnection.close();
    wsConnection = new WebSocket(WS_URL + '?token=' + token);
    wsConnection.onmessage = (event) => {
        const msg = JSON.parse(event.data);
        // Обработка сообщений чата, поднятия руки и т.д.
        if (msg.type === 'chat') {
            addChatMessage(msg);
        } else if (msg.type === 'hand') {
            // Обновить список участников
        }
    };
}

function addChatMessage(msg) {
    const chatDiv = document.getElementById('chat-messages');
    if (chatDiv) {
        chatDiv.innerHTML += `<div><b>${msg.user}:</b> ${msg.text}</div>`;
        chatDiv.scrollTop = chatDiv.scrollHeight;
    }
}

function initLiveKit(roomId, token) {
    // Здесь будет код LiveKit для подключения к SFU
    console.log('LiveKit connecting with token', token);
}

// ----- Запуск приложения -----
window.addEventListener('load', async () => {
    await showLoading(); // показываем логотип
    // Проверяем, есть ли сохранённый токен
    if (accessToken) {
        try {
            const userData = await apiRequest('me', 'GET', null, true);
            if (userData && userData.id) {
                currentUser = userData;
                userDepartments = userData.departments || [];
            } else {
                accessToken = null;
                localStorage.removeItem('accessToken');
            }
        } catch (e) {
            accessToken = null;
            localStorage.removeItem('accessToken');
        }
    }
    // Если пользователь не авторизован, показываем страницу входа
    if (!currentUser) {
        renderLoginPage();
    } else {
        navigate(window.location.pathname || '/');
    }
});

window.addEventListener('popstate', renderPage);