# Техническая документация — Agile.Telemost

> Репозиторий: [github.com/5eeee/agile-telemost](https://github.com/5eeee/agile-telemost)  
> Автор: Владимир Кутомкин

## 1. Назначение

MVP сервиса видеоконференций для бизнеса: комнаты, отделы (11 направлений), JWT-авторизация, LiveKit SFU, WebSocket.

## 2. Стек

Vanilla JavaScript SPA · PHP REST API · MySQL · JWT · WebSocket · LiveKit SFU · Apache

## 3. Структура

```
├── index.html, script.js, style.css   # SPA
├── api.php          # REST API (~900 строк)
├── auth.php         # JWT
├── ws.php           # WebSocket
├── config.php       # Конфигурация
├── database.php     # MySQL connection
├── install.sql      # Схема БД
├── livekit.yaml     # LiveKit config
└── .htaccess
```

## 4. API (`api.php?action=`)

| action | Описание |
|--------|----------|
| `login`, `register`, `sso` | Аутентификация |
| `me`, `updateProfile` | Профиль |
| `admin/users`, `admin/user/create\|update\|block` | Админ |
| `departments` | 11 отделов |
| `rooms/active`, `rooms/create` | Комнаты |
| `room/join`, `room/info`, `room/end` | Управление |
| `recordings` | Записи |
| `upload/avatar` | Аватар |

## 5. MySQL

Таблицы: `departments`, `users`, `user_departments`, `rooms`, `participants`, `messages`, `recordings` — см. `install.sql`

## 6. Конфигурация (`config.php`)

`DB_*`, `JWT_SECRET`, `JWT_EXPIRE`, `LIVEKIT_API_KEY/SECRET/URL`, `WS_HOST/PORT`, `UPLOAD_DIR`

## 7. Архитектура

```
Browser SPA → PHP REST → MySQL
     ↓
  WebSocket (ws.php)
     ↓
  LiveKit SFU (видео/аудио)
```

## 8. Деплой

1. PHP 8+ (mysqli, json), Apache mod_rewrite
2. `mysql < install.sql`
3. LiveKit Server (Docker / отдельный процесс)
4. Настроить JWT + LiveKit keys в `config.php`
5. Shared hosting или VPS

## 9. Join flow

1. Пользователь создаёт/выбирает комнату
2. API генерирует LiveKit-токен
3. SPA подключается к LiveKit SFU
4. WebSocket для чата/сигналинга
