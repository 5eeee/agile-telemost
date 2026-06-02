<?php
// config.php - конфигурационный файл Agile.Telemost

// ---------- База данных ----------
define('DB_HOST', 'localhost');
define('DB_NAME', 'u3413843_agile_telemost');
define('DB_USER', 'u3413843_agile_telemost');
define('DB_PASS', 'adgile_telemost_123456789');
define('DB_CHARSET', 'utf8mb4');

// DSN для PDO
define('DB_DSN', 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET);

// ---------- Пути ----------
// Корневая директория проекта (абсолютный путь на сервере)
define('ROOT_DIR', __DIR__);

// Директория для загружаемых файлов (относительно ROOT_DIR)
define('UPLOAD_DIR', ROOT_DIR . '/uploads');

// URL префикс для загруженных файлов (веб-доступ)
define('UPLOAD_URL', '/uploads');

// ---------- JWT ----------
// Секретный ключ для подписи JWT (изменить в продакшене)
define('JWT_SECRET', 'your-very-secret-key-change-this');
// Время жизни токена (секунды) - 24 часа
define('JWT_EXPIRE', 86400);

// ---------- LiveKit ----------
// API ключ и секрет для LiveKit SFU
define('LIVEKIT_API_KEY', 'your-livekit-api-key');
define('LIVEKIT_API_SECRET', 'your-livekit-api-secret');
// URL LiveKit сервера (для клиентов)
define('LIVEKIT_URL', 'wss://livekit.example.com');

// ---------- WebSocket ----------
// Хост и порт для WebSocket-сервера (ws.php)
define('WS_HOST', '0.0.0.0');
define('WS_PORT', 8080);

// ---------- Режим отладки ----------
define('DEBUG_MODE', true); // В продакшене выключить

// ---------- Настройки сессии ----------
// (если используются сессии, но мы используем JWT)