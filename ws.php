<?php
// ws.php - WebSocket сервер для Agile.Telemost (чат, уведомления, поднятие руки)
// Запуск: php ws.php
// Зависимости: composer require cboden/ratchet firebase/php-jwt

require_once __DIR__ . '/vendor/autoload.php';
require_once 'config.php';
require_once 'database.php';
require_once 'auth.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class TelemostWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $rooms; // массив комнат: room_id => [соединения]
    protected $users; // соединение => user_id

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->rooms = [];
        $this->users = [];
    }

    public function onOpen(ConnectionInterface $conn) {
        // При открытии соединения ожидаем токен в query параметре
        $querystring = $conn->httpRequest->getUri()->getQuery();
        parse_str($querystring, $query);
        $token = $query['token'] ?? '';

        if (!$token) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Token required']));
            $conn->close();
            return;
        }

        // Проверка JWT токена (используем наш secret)
        try {
            $decoded = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
            $userId = $decoded->user_id;
            $roomId = $decoded->room_id ?? null; // ожидаем, что в токене есть room_id
        } catch (\Exception $e) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid token']));
            $conn->close();
            return;
        }

        if (!$roomId) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Room ID missing']));
            $conn->close();
            return;
        }

        // Проверка доступа пользователя к комнате
        if (!canUserAccessRoom($userId, $roomId)) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Access denied']));
            $conn->close();
            return;
        }

        // Сохраняем данные соединения
        $this->clients->attach($conn);
        $this->users[$conn->resourceId] = [
            'user_id' => $userId,
            'room_id' => $roomId,
            'name' => getUserById($userId)['name'] ?? 'Unknown'
        ];

        // Добавляем в комнату
        if (!isset($this->rooms[$roomId])) {
            $this->rooms[$roomId] = [];
        }
        $this->rooms[$roomId][$conn->resourceId] = $conn;

        // Уведомляем остальных в комнате о новом участнике
        $this->broadcastToRoom($roomId, [
            'type' => 'user_joined',
            'user_id' => $userId,
            'name' => $this->users[$conn->resourceId]['name']
        ], $conn);

        // Отправляем текущий список участников новому подключению
        $participants = [];
        foreach ($this->rooms[$roomId] as $clientConn) {
            $participants[] = $this->users[$clientConn->resourceId];
        }
        $conn->send(json_encode([
            'type' => 'room_info',
            'participants' => $participants
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) return;

        $userId = $this->users[$from->resourceId]['user_id'];
        $roomId = $this->users[$from->resourceId]['room_id'];
        $userName = $this->users[$from->resourceId]['name'];

        switch ($data['type']) {
            case 'chat':
                // Сообщение чата
                $text = $data['text'] ?? '';
                if (!$text) return;

                // Сохраняем в БД
                $db = Database::getDB();
                $stmt = $db->prepare("INSERT INTO messages (room_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$roomId, $userId, $text]);

                // Рассылаем всем в комнате
                $this->broadcastToRoom($roomId, [
                    'type' => 'chat',
                    'user_id' => $userId,
                    'name' => $userName,
                    'text' => $text,
                    'time' => date('H:i')
                ]);
                break;

            case 'hand_raise':
                // Поднятие руки
                $isRaising = $data['raise'] ?? true; // true - поднять, false - опустить
                $this->broadcastToRoom($roomId, [
                    'type' => 'hand_raise',
                    'user_id' => $userId,
                    'name' => $userName,
                    'raise' => $isRaising
                ]);
                break;

            case 'typing':
                // Индикатор печатания (опционально)
                $this->broadcastToRoom($roomId, [
                    'type' => 'typing',
                    'user_id' => $userId,
                    'name' => $userName,
                    'typing' => $data['typing'] ?? true
                ], $from); // не отправляем самому себе
                break;

            case 'poll_create':
                // Создание опроса (только модератор)
                if (!isModerator($userId, $roomId)) {
                    $from->send(json_encode(['type' => 'error', 'message' => 'Only moderators can create polls']));
                    return;
                }
                $question = $data['question'] ?? '';
                $options = $data['options'] ?? [];
                if (!$question || count($options) < 2) return;

                // Сохраняем опрос в БД
                $db = Database::getDB();
                $db->beginTransaction();
                try {
                    $stmt = $db->prepare("INSERT INTO polls (room_id, created_by, question, created_at) VALUES (?, ?, ?, NOW())");
                    $stmt->execute([$roomId, $userId, $question]);
                    $pollId = $db->lastInsertId();

                    $optStmt = $db->prepare("INSERT INTO poll_options (poll_id, option_text) VALUES (?, ?)");
                    foreach ($options as $opt) {
                        $optStmt->execute([$pollId, $opt]);
                    }
                    $db->commit();

                    // Рассылаем опрос всем в комнате
                    $this->broadcastToRoom($roomId, [
                        'type' => 'poll',
                        'poll_id' => $pollId,
                        'question' => $question,
                        'options' => $options,
                        'created_by' => $userName
                    ]);
                } catch (Exception $e) {
                    $db->rollBack();
                    $from->send(json_encode(['type' => 'error', 'message' => 'Failed to create poll']));
                }
                break;

            case 'poll_vote':
                // Голосование в опросе
                $pollId = $data['poll_id'] ?? 0;
                $optionId = $data['option_id'] ?? 0;
                if (!$pollId || !$optionId) return;

                // Проверяем, не голосовал ли уже
                $db = Database::getDB();
                $check = $db->prepare("SELECT id FROM poll_votes WHERE poll_id = ? AND user_id = ?");
                $check->execute([$pollId, $userId]);
                if ($check->fetch()) {
                    $from->send(json_encode(['type' => 'error', 'message' => 'You already voted']));
                    return;
                }

                // Записываем голос
                $stmt = $db->prepare("INSERT INTO poll_votes (poll_id, option_id, user_id, voted_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$pollId, $optionId, $userId]);

                // Отправляем обновлённые результаты (можно только модератору или всем)
                $this->broadcastPollResults($pollId, $roomId);
                break;

            case 'mute_user':
                // Модератор отключает звук/видео участнику (сигнал клиенту)
                if (!isModerator($userId, $roomId)) return;
                $targetUserId = $data['target_user_id'] ?? 0;
                $kind = $data['kind'] ?? 'audio'; // 'audio' или 'video'
                $mute = $data['mute'] ?? true;

                // Находим соединение целевого пользователя в этой комнате
                foreach ($this->rooms[$roomId] ?? [] as $conn) {
                    if ($this->users[$conn->resourceId]['user_id'] == $targetUserId) {
                        $conn->send(json_encode([
                            'type' => 'moderator_action',
                            'action' => 'mute',
                            'kind' => $kind,
                            'mute' => $mute
                        ]));
                        break;
                    }
                }
                break;

            // Другие события (опросы, файлы и т.д.) можно добавить аналогично
        }
    }

    public function onClose(ConnectionInterface $conn) {
        if (!isset($this->users[$conn->resourceId])) return;

        $userId = $this->users[$conn->resourceId]['user_id'];
        $roomId = $this->users[$conn->resourceId]['room_id'];
        $userName = $this->users[$conn->resourceId]['name'];

        // Удаляем из комнаты
        unset($this->rooms[$roomId][$conn->resourceId]);
        if (empty($this->rooms[$roomId])) {
            unset($this->rooms[$roomId]);
        }

        // Удаляем из списков
        unset($this->users[$conn->resourceId]);
        $this->clients->detach($conn);

        // Уведомляем остальных о выходе
        $this->broadcastToRoom($roomId, [
            'type' => 'user_left',
            'user_id' => $userId,
            'name' => $userName
        ]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    // Рассылка сообщения всем в комнате, кроме указанного соединения (опционально)
    private function broadcastToRoom($roomId, $message, ConnectionInterface $except = null) {
        if (!isset($this->rooms[$roomId])) return;
        foreach ($this->rooms[$roomId] as $conn) {
            if ($except && $conn === $except) continue;
            $conn->send(json_encode($message));
        }
    }

    // Получение и рассылка результатов опроса
    private function broadcastPollResults($pollId, $roomId) {
        $db = Database::getDB();
        // Получаем варианты с количеством голосов
        $stmt = $db->prepare("
            SELECT o.id, o.option_text, COUNT(v.id) as votes
            FROM poll_options o
            LEFT JOIN poll_votes v ON o.id = v.option_id
            WHERE o.poll_id = ?
            GROUP BY o.id
        ");
        $stmt->execute([$pollId]);
        $results = $stmt->fetchAll();

        $this->broadcastToRoom($roomId, [
            'type' => 'poll_results',
            'poll_id' => $pollId,
            'results' => $results
        ]);
    }
}

// Запуск сервера
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new TelemostWebSocket()
        )
    ),
    WS_PORT,
    WS_HOST
);

echo "WebSocket server started on ws://" . WS_HOST . ":" . WS_PORT . "\n";
$server->run();