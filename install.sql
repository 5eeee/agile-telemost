-- install.sql - SQL дамп для создания структуры БД Agile.Telemost
-- Запустить: mysql -u root -p agile_telemost < install.sql

CREATE DATABASE IF NOT EXISTS agile_telemost CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agile_telemost;

-- ==================== Таблица отделов (направлений) ====================
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Предопределённый список из 11 отделов (согласно ТЗ)
INSERT INTO departments (name) VALUES
('Управление и Стратегия'),
('Аналитика и Данные'),
('Финансовый консалтинг и учет'),
('Инвестиции и оценка'),
('ИТ и разработка'),
('Маркетинг'),
('Продажи и развитие клиентов'),
('Креатив дизайн'),
('Операции и Логистика'),
('Кадры и организации (HR)'),
('Юридическое Сопровождение');

-- ==================== Таблица пользователей ====================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',  -- 'admin' или обычный пользователь
    avatar VARCHAR(500) DEFAULT NULL,            -- путь к аватару
    notify_email TINYINT(1) DEFAULT 1,           -- подписка на уведомления по email
    blocked TINYINT(1) DEFAULT 0,                 -- заблокирован ли пользователь
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Создаём администратора по умолчанию (пароль: admin123, нужно сменить при первом входе)
-- Хеш password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO users (name, email, password_hash, role) VALUES
('Administrator', 'admin@agile.telemost', '$2y$10$YourHashHereChangeMe', 'admin');

-- ==================== Связь пользователей с отделами ====================
CREATE TABLE user_departments (
    user_id INT NOT NULL,
    department_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, department_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==================== Таблица комнат (конференций) ====================
CREATE TABLE rooms (
    id VARCHAR(32) PRIMARY KEY,                -- уникальный короткий идентификатор (например, abc123)
    name VARCHAR(255) NOT NULL,
    department_id INT NOT NULL,
    description TEXT,
    password VARCHAR(255) DEFAULT NULL,        -- опциональный пароль для входа
    created_by INT NOT NULL,                    -- кто создал (user_id)
    start_time DATETIME NOT NULL,               -- время начала (если scheduled)
    end_time DATETIME DEFAULT NULL,              -- фактическое время завершения
    cancelled TINYINT(1) DEFAULT 0,              -- отменена ли (для запланированных)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_start_time (start_time),
    INDEX idx_department (department_id),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==================== Участники комнат ====================
CREATE TABLE participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(32) NOT NULL,
    user_id INT NOT NULL,
    is_moderator TINYINT(1) DEFAULT 0,          -- является ли модератором в этой комнате
    joined_at DATETIME NOT NULL,
    left_at DATETIME DEFAULT NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_participant (room_id, user_id, left_at) -- для активного участия
) ENGINE=InnoDB;

-- ==================== Сообщения чата ====================
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(32) NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_room_time (room_id, created_at)
) ENGINE=InnoDB;

-- ==================== Файлы (вложения чата) ====================
CREATE TABLE files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(32) NOT NULL,
    user_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,            -- путь к файлу на сервере
    file_size INT NOT NULL,                       -- размер в байтах
    mime_type VARCHAR(100),
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==================== Опросы ====================
CREATE TABLE polls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(32) NOT NULL,
    created_by INT NOT NULL,
    question VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed TINYINT(1) DEFAULT 0,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Варианты ответов в опросе
CREATE TABLE poll_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    option_text VARCHAR(255) NOT NULL,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Голоса пользователей
CREATE TABLE poll_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    option_id INT NOT NULL,
    user_id INT NOT NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_vote (poll_id, user_id)   -- один пользователь может голосовать только один раз в опросе
) ENGINE=InnoDB;

-- ==================== Записи конференций ====================
CREATE TABLE recordings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(32) NOT NULL,
    started_by INT NOT NULL,                      -- кто начал запись
    stopped_by INT DEFAULT NULL,                   -- кто остановил (если null, запись автоматически завершена)
    url VARCHAR(500) NOT NULL,                     -- ссылка на файл записи
    duration INT DEFAULT NULL,                      -- длительность в секундах
    started_at DATETIME NOT NULL,
    stopped_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (started_by) REFERENCES users(id),
    FOREIGN KEY (stopped_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ==================== Журнал действий (аудит) - опционально ====================
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action)
) ENGINE=InnoDB;

-- ==================== Индексы для производительности ====================
CREATE INDEX idx_participants_room ON participants(room_id, left_at);
CREATE INDEX idx_participants_user ON participants(user_id, left_at);
CREATE INDEX idx_messages_room ON messages(room_id);
CREATE INDEX idx_recordings_room ON recordings(room_id);