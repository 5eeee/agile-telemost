<?php
// auth.php - функции аутентификации и авторизации для Agile.Telemost

// Подключаем конфигурацию, если ещё не подключена
if (!defined('JWT_SECRET')) {
    require_once 'config.php';
}
require_once 'database.php';

/**
 * Генерация JWT токена
 * @param int $userId
 * @param string $role
 * @return string
 */
function generateJWT($userId, $role) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode([
        'user_id' => $userId,
        'role' => $role,
        'exp' => time() + JWT_EXPIRE
    ]);

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

/**
 * Проверка JWT токена и получение данных пользователя
 * @param string $token
 * @return array|false ассоциативный массив с user_id, role или false при ошибке
 */
function verifyJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }

    list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;

    // Декодируем заголовок и payload
    $header = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64UrlHeader)), true);
    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64UrlPayload)), true);

    if (!$header || !$payload) {
        return false;
    }

    // Проверка срока действия
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return false;
    }

    // Проверка подписи
    $signature = base64_decode(str_replace(['-', '_'], ['+', '/'], $base64UrlSignature));
    $expectedSignature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);

    if (!hash_equals($expectedSignature, $signature)) {
        return false;
    }

    return $payload;
}

/**
 * Аутентификация по JWT токену (получение полного пользователя из БД)
 * @param string $token
 * @return array|false массив с данными пользователя или false
 */
function authenticateJWT($token) {
    $payload = verifyJWT($token);
    if (!$payload || !isset($payload['user_id'])) {
        return false;
    }

    $db = Database::getDB();
    $stmt = $db->prepare("SELECT id, name, email, role, avatar, notify_email, blocked FROM users WHERE id = ? AND blocked = 0");
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }

    // Добавляем отделы пользователя
    $user['departments'] = getUserDepartments($user['id']);
    return $user;
}

/**
 * Получение отделов пользователя (массив объектов)
 * @param int $userId
 * @return array
 */
function getUserDepartments($userId) {
    $db = Database::getDB();
    $stmt = $db->prepare("
        SELECT d.id, d.name
        FROM departments d
        JOIN user_departments ud ON d.id = ud.department_id
        WHERE ud.user_id = ?
        ORDER BY d.name
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Проверка доступа пользователя к комнате (по отделу)
 * @param int $userId
 * @param int $roomId
 * @return bool
 */
function canUserAccessRoom($userId, $roomId) {
    $db = Database::getDB();

    // Получаем отдел комнаты
    $stmt = $db->prepare("SELECT department_id, created_by FROM rooms WHERE id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();

    if (!$room) return false;

    // Админ имеет доступ ко всему
    $user = getUserById($userId);
    if ($user && $user['role'] === 'admin') return true;

    // Проверяем, принадлежит ли пользователь к отделу комнаты
    $deptStmt = $db->prepare("SELECT 1 FROM user_departments WHERE user_id = ? AND department_id = ?");
    $deptStmt->execute([$userId, $room['department_id']]);
    if ($deptStmt->fetch()) return true;

    // Или пользователь создатель комнаты
    if ($room['created_by'] == $userId) return true;

    return false;
}

/**
 * Проверка, является ли пользователь модератором данной комнаты
 * @param int $userId
 * @param int $roomId
 * @return bool
 */
function isModerator($userId, $roomId) {
    $db = Database::getDB();
    $stmt = $db->prepare("
        SELECT is_moderator FROM participants
        WHERE room_id = ? AND user_id = ? AND left_at IS NULL
    ");
    $stmt->execute([$roomId, $userId]);
    $participant = $stmt->fetch();
    return $participant && $participant['is_moderator'] == 1;
}

/**
 * Получение пользователя по ID
 * @param int $userId
 * @return array|false
 */
function getUserById($userId) {
    $db = Database::getDB();
    $stmt = $db->prepare("SELECT id, name, email, role, avatar, notify_email, blocked FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

/**
 * Проверка роли администратора
 * @param int $userId
 * @return bool
 */
function isAdmin($userId) {
    $user = getUserById($userId);
    return $user && $user['role'] === 'admin';
}