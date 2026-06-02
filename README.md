# Agile.Telemost — сервис видеоконференций

> **Полная техническая документация:** [docs/TECHNICAL.md](docs/TECHNICAL.md) · **GitHub:** [github.com/5eeee/agile-telemost](https://github.com/5eeee/agile-telemost)

MVP веб-сервиса видеоконференций для бизнеса

## Стек

| Слой | Технологии |
|------|------------|
| Frontend | Vanilla JavaScript SPA, HTML5, CSS3 |
| Backend | PHP REST API (`api.php`), MySQL |
| Realtime | WebSocket (`ws.php`), LiveKit SFU |
| Auth | JWT (`auth.php`), SSO |

## Возможности

- Регистрация и вход пользователей
- Создание и управление комнатами видеоконференций
- Организация по отделам (11 направлений)
- Выдача LiveKit-токенов для подключения к комнате
- Админ-управление пользователями

## Архитектура

```
Browser (SPA) ──▶ PHP REST API ──▶ MySQL
     │                │
     └──── WebSocket ──┘
     └──── LiveKit SFU (видео/аудио)
```

## Структура

```
├── index.html       # SPA-оболочка
├── script.js        # Клиентская логика
├── style.css        # Стили
├── api.php          # REST API (~900 строк)
├── auth.php         # JWT-авторизация
├── ws.php           # WebSocket
├── livekit.yaml     # Конфиг LiveKit
└── .htaccess        # Apache rewrite
```

## Развёртывание

1. PHP 8+ с расширениями mysqli, json
2. MySQL — импорт схемы БД
3. Apache с mod_rewrite
4. LiveKit Server (отдельный процесс / Docker)
5. Настройка JWT-секрета и LiveKit API keys

## API (основное)

| Endpoint | Описание |
|----------|----------|
| `POST /api.php?action=register` | Регистрация |
| `POST /api.php?action=login` | Вход, выдача JWT |
| `GET /api.php?action=rooms` | Список комнат |
| `POST /api.php?action=create_room` | Создание комнаты |
| `GET /api.php?action=join` | LiveKit-токен для комнаты |

## Автор

Владимир Кутомкин — [GitHub](https://github.com/5eeee)
