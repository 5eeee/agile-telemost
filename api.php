<?php
// api.php - REST API для Agile.Telemost
// Обрабатывает запросы вида /api.php?action=...
// Заголовки CORS и JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Обработка preflight запросов OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';
require_once 'database.php';
require_once 'auth.php';

// Получаем действие
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Авторизация (проверка токена, кроме действий login, register и т.п.)
$publicActions = ['login', 'register', 'sso', 'health'];
if (!in_array($action, $publicActions)) {
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
        $user = authenticateJWT($token);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Недействительный или просроченный токен']);
            exit();
        }
        // Сохраняем пользователя в глобальной переменной для дальнейшего использования
        $GLOBALS['currentUser'] = $user;
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Требуется авторизация']);
        exit();
    }
}

// Маршрутизация по действиям
switch ($action) {
    // ---------- Аутентификация ----------
    case 'login':
        handleLogin();
        break;
    case 'register':
        handleRegister();
        break;
    case 'sso':
        handleSSO();
        break;
    case 'me':
        handleGetMe();
        break;
    case 'updateProfile':
        handleUpdateProfile();
        break;

    // ---------- Пользователи (админ) ----------
    case 'admin/users':
        handleAdminGetUsers();
        break;
    case 'admin/user/create':
        handleAdminCreateUser();
        break;
    case 'admin/user/update':
        handleAdminUpdateUser();
        break;
    case 'admin/user/block':
        handleAdminBlockUser();
        break;

    // ---------- Отделы ----------
    case 'departments':
        handleGetDepartments();
        break;

    // ---------- Комнаты (конференции) ----------
    case 'rooms/active':
        handleGetActiveRooms();
        break;
    case 'rooms/scheduled':
        handleGetScheduledRooms();
        break;
    case 'rooms/create':
        handleCreateRoom();
        break;
    case 'room/join':
        handleJoinRoom();
        break;
    case 'room/info':
        handleRoomInfo();
        break;
    case 'room/end':
        handleEndRoom();
        break;
    case 'room/delete':
        handleDeleteRoom();
        break;

    // ---------- Записи ----------
    case 'recordings':
        handleGetRecordings();
        break;
    case 'recording/info':
        handleRecordingInfo();
        break;

    // ---------- Файлы (загрузка аватаров и т.п.) ----------
    case 'upload/avatar':
        handleUploadAvatar();
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Действие не найдено']);
        break;
}

// ==================== Реализация обработчиков ====================

/**
 * Аутентификация по email/паролю
 */
function handleLogin() {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';

    if (!$email || !$password) {
        http_response_code(400);
        echo json_encode(['error' => 'Email и пароль обязательны']);
        return;
    }

    $db = Database::getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND blocked = 0");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Получаем отделы пользователя
        $depts = getUserDepartments($user['id']);
        $user['departments'] = $depts;

        // Генерируем JWT
        $token = generateJWT($user['id'], $user['role']);
        echo json_encode([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'avatar' => $user['avatar'],
                'departments' => $depts,
                'notify_email' => (bool)$user['notify_email']
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Неверный email или пароль']);
    }
}

/**
 * Регистрация (только для открытой регистрации, если требуется)
 */
function handleRegister() {
    // В ТЗ не описана регистрация, но упомянута, поэтому базовая реализация
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';

    if (!$name || !$email || !$password) {
        http_response_code(400);
        echo json_encode(['error' => 'Все поля обязательны']);
        return;
    }

    $db = Database::getDB();
    // Проверка существования
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Пользователь с таким email уже существует']);
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, created_at) VALUES (?, ?, ?, 'user', NOW())");
    $stmt->execute([$name, $email, $hash]);
    $userId = $db->lastInsertId();

    // Пользователь создан, но не привязан к отделам (администратор назначит позже)
    echo json_encode(['success' => true, 'user_id' => $userId]);
}

/**
 * SSO заглушка
 */
function handleSSO() {
    // Здесь должна быть интеграция с SSO провайдером
    echo json_encode(['error' => 'SSO не реализовано']);
}

/**
 * Получение данных текущего пользователя
 */
function handleGetMe() {
    global $currentUser;
    $user = $currentUser;
    // Обновим отделы (на всякий случай)
    $user['departments'] = getUserDepartments($user['id']);
    echo json_encode($user);
}

/**
 * Обновление профиля (включая аватар)
 */
function handleUpdateProfile() {
    global $currentUser;
    $userId = $currentUser['id'];

    // Если multipart/form-data
    $name = $_POST['name'] ?? $currentUser['name'];
    $password = $_POST['password'] ?? '';
    $notifyEmail = isset($_POST['notify_email']) ? (int)$_POST['notify_email'] : $currentUser['notify_email'];

    $db = Database::getDB();

    // Обработка загрузки аватара
    $avatarPath = $currentUser['avatar']; // сохраняем старый путь по умолчанию
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = UPLOAD_DIR . '/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
        $destination = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destination)) {
            $avatarPath = '/uploads/avatars/' . $filename; // веб-путь
        }
    }

    // Обновление в БД
    if ($password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET name = ?, password_hash = ?, notify_email = ?, avatar = ? WHERE id = ?");
        $stmt->execute([$name, $hash, $notifyEmail, $avatarPath, $userId]);
    } else {
        $stmt = $db->prepare("UPDATE users SET name = ?, notify_email = ?, avatar = ? WHERE id = ?");
        $stmt->execute([$name, $notifyEmail, $avatarPath, $userId]);
    }

    // Возвращаем обновлённого пользователя
    $stmt = $db->prepare("SELECT id, name, email, role, avatar, notify_email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $user['departments'] = getUserDepartments($userId);

    echo json_encode(['success' => true, 'user' => $user]);
}

/**
 * Получить всех пользователей (админ)
 */
function handleAdminGetUsers() {
    global $currentUser;
    if ($currentUser['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён']);
        return;
    }

    $db = Database::getDB();
    $stmt = $db->query("SELECT id, name, email, role, blocked, avatar, notify_email, created_at FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Добавляем отделы для каждого
    foreach ($users as &$u) {
        $u['departments'] = getUserDepartments($u['id']);
    }

    echo json_encode(['users' => $users]);
}

/**
 * Создание пользователя администратором
 */
function handleAdminCreateUser() {
    global $currentUser;
    if ($currentUser['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? ''; // можно генерировать временный пароль
    $role = $input['role'] ?? 'user';
    $departmentIds = $input['department_ids'] ?? [];

    if (!$name || !$email || !$password) {
        http_response_code(400);
        echo json_encode(['error' => 'Не все поля заполнены']);
        return;
    }

    $db = Database::getDB();
    // Проверка дубликата
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Email уже используется']);
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$name, $email, $hash, $role]);
        $userId = $db->lastInsertId();

        // Назначаем отделы
        if (!empty($departmentIds)) {
            $insertDept = $db->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (?, ?)");
            foreach ($departmentIds as $deptId) {
                $insertDept->execute([$userId, $deptId]);
            }
        }

        $db->commit();
        echo json_encode(['success' => true, 'user_id' => $userId]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка создания: ' . $e->getMessage()]);
    }
}

/**
 * Обновление пользователя администратором
 */
function handleAdminUpdateUser() {
    global $currentUser;
    if ($currentUser['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['id'] ?? 0;
    $name = $input['name'] ?? null;
    $email = $input['email'] ?? null;
    $role = $input['role'] ?? null;
    $departmentIds = $input['department_ids'] ?? null; // массив ID отделов
    $blocked = isset($input['blocked']) ? (int)$input['blocked'] : null;

    if (!$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID пользователя не указан']);
        return;
    }

    $db = Database::getDB();
    $db->beginTransaction();
    try {
        // Обновление основных полей
        $fields = [];
        $params = [];
        if ($name !== null) {
            $fields[] = "name = ?";
            $params[] = $name;
        }
        if ($email !== null) {
            // Проверка уникальности email
            $check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check->execute([$email, $userId]);
            if ($check->fetch()) {
                throw new Exception('Email уже используется другим пользователем');
            }
            $fields[] = "email = ?";
            $params[] = $email;
        }
        if ($role !== null) {
            $fields[] = "role = ?";
            $params[] = $role;
        }
        if ($blocked !== null) {
            $fields[] = "blocked = ?";
            $params[] = $blocked;
        }

        if (!empty($fields)) {
            $params[] = $userId;
            $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        }

        // Обновление отделов
        if ($departmentIds !== null) {
            // Удаляем старые связи
            $stmt = $db->prepare("DELETE FROM user_departments WHERE user_id = ?");
            $stmt->execute([$userId]);

            // Добавляем новые
            if (!empty($departmentIds)) {
                $insert = $db->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (?, ?)");
                foreach ($departmentIds as $deptId) {
                    $insert->execute([$userId, $deptId]);
                }
            }
        }

        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

/**
 * Блокировка/разблокировка пользователя
 */
function handleAdminBlockUser() {
    global $currentUser;
    if ($currentUser['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['id'] ?? 0;
    $block = isset($input['block']) ? (int)$input['block'] : 1;

    if (!$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID пользователя не указан']);
        return;
    }

    $db = Database::getDB();
    $stmt = $db->prepare("UPDATE users SET blocked = ? WHERE id = ?");
    $stmt->execute([$block, $userId]);

    echo json_encode(['success' => true]);
}

/**
 * Получить список всех отделов (доступно всем авторизованным)
 */
function handleGetDepartments() {
    $db = Database::getDB();
    $stmt = $db->query("SELECT * FROM departments ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['departments' => $departments]);
}

/**
 * Получить активные комнаты (текущие конференции), доступные пользователю
 */
function handleGetActiveRooms() {
    global $currentUser;
    $userId = $currentUser['id'];
    $deptIds = getUserDepartments($userId, true); // получить только ID

    $db = Database::getDB();

    // Активные комнаты: сейчас в статусе active (started) и доступные по отделу или созданные пользователем/модератором
    // Для простоты считаем, что активные - те, у которых start_time <= NOW() и end_time IS NULL
    // Ограничиваем по отделам: комната должна принадлежать одному из отделов пользователя, если пользователь не админ
    $sql = "SELECT r.*, d.name as department_name,
            (SELECT COUNT(*) FROM participants WHERE room_id = r.id AND left_at IS NULL) as participants_count
            FROM rooms r
            LEFT JOIN departments d ON r.department_id = d.id
            WHERE r.start_time <= NOW() AND r.end_time IS NULL";

    if ($currentUser['role'] !== 'admin') {
        $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
        $sql .= " AND r.department_id IN ($placeholders)";
    }

    $sql .= " ORDER BY r.start_time DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($deptIds);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['rooms' => $rooms]);
}

/**
 * Получить запланированные комнаты (будущие)
 */
function handleGetScheduledRooms() {
    global $currentUser;
    $userId = $currentUser['id'];
    $deptIds = getUserDepartments($userId, true);

    $db = Database::getDB();

    $sql = "SELECT r.*, d.name as department_name
            FROM rooms r
            LEFT JOIN departments d ON r.department_id = d.id
            WHERE r.start_time > NOW() AND r.cancelled = 0";

    if ($currentUser['role'] !== 'admin') {
        $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
        $sql .= " AND (r.department_id IN ($placeholders) OR r.created_by = ?)";
        // добавляем userId в параметры для OR
        $params = array_merge($deptIds, [$userId]);
    } else {
        $params = [];
    }

    $sql .= " ORDER BY r.start_time";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['rooms' => $rooms]);
}

/**
 * Создание комнаты (конференции)
 */
function handleCreateRoom() {
    global $currentUser;
    $input = json_decode(file_get_contents('php://input'), true);

    $name = $input['name'] ?? '';
    $departmentId = $input['department_id'] ?? 0;
    $description = $input['description'] ?? '';
    $password = $input['password'] ?? null;
    $scheduledTime = $input['scheduled'] ?? null; // если null, создаём сейчас

    if (!$name || !$departmentId) {
        http_response_code(400);
        echo json_encode(['error' => 'Название и направление обязательны']);
        return;
    }

    // Проверка, что пользователь имеет доступ к этому отделу (или админ)
    if ($currentUser['role'] !== 'admin') {
        $userDepts = getUserDepartments($currentUser['id'], true);
        if (!in_array($departmentId, $userDepts)) {
            http_response_code(403);
            echo json_encode(['error' => 'У вас нет доступа к выбранному направлению']);
            return;
        }
    }

    // Генерация уникального идентификатора комнаты (например, случайная строка)
    $roomId = generateRoomId();

    $db = Database::getDB();
    $stmt = $db->prepare("
        INSERT INTO rooms (id, name, department_id, description, password, created_by, start_time, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $startTime = $scheduledTime ?: date('Y-m-d H:i:s'); // если не указано, начинаем сейчас
    $stmt->execute([$roomId, $name, $departmentId, $description, $password, $currentUser['id'], $startTime]);

    // Создатель автоматически становится модератором (запись в participants)
    $stmt2 = $db->prepare("INSERT INTO participants (room_id, user_id, is_moderator, joined_at) VALUES (?, ?, 1, NOW())");
    $stmt2->execute([$roomId, $currentUser['id']]);

    echo json_encode([
        'success' => true,
        'room_id' => $roomId,
        'invite_link' => 'https://' . $_SERVER['HTTP_HOST'] . '/room/' . $roomId
    ]);
}

/**
 * Присоединение к комнате (получение токенов LiveKit и WebSocket)
 */
function handleJoinRoom() {
    global $currentUser;
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId = $input['room_id'] ?? '';
    $password = $input['password'] ?? null;

    if (!$roomId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID комнаты не указан']);
        return;
    }

    $db = Database::getDB();
    $stmt = $db->prepare("
        SELECT r.*, d.name as department_name
        FROM rooms r
        LEFT JOIN departments d ON r.department_id = d.id
        WHERE r.id = ? AND r.cancelled = 0
    ");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        http_response_code(404);
        echo json_encode(['error' => 'Комната не найдена или отменена']);
        return;
    }

    // Проверка пароля
    if ($room['password'] && $room['password'] !== $password) {
        http_response_code(403);
        echo json_encode(['error' => 'Неверный пароль']);
        return;
    }

    // Проверка доступа по отделу (если не админ)
    if ($currentUser['role'] !== 'admin') {
        $userDepts = getUserDepartments($currentUser['id'], true);
        if (!in_array($room['department_id'], $userDepts)) {
            http_response_code(403);
            echo json_encode(['error' => 'У вас нет доступа к этой конференции']);
            return;
        }
    }

    // Добавляем пользователя в участники, если его там нет
    $check = $db->prepare("SELECT id FROM participants WHERE room_id = ? AND user_id = ? AND left_at IS NULL");
    $check->execute([$roomId, $currentUser['id']]);
    if (!$check->fetch()) {
        $join = $db->prepare("INSERT INTO participants (room_id, user_id, joined_at) VALUES (?, ?, NOW())");
        $join->execute([$roomId, $currentUser['id']]);
    }

    // Генерируем токены для LiveKit и WebSocket (заглушка, реальная генерация с использованием LiveKit API)
    $livekitToken = generateLiveKitToken($currentUser['id'], $roomId);
    $wsToken = generateWebSocketToken($currentUser['id'], $roomId);

    echo json_encode([
        'success' => true,
        'room' => $room,
        'livekit_token' => $livekitToken,
        'ws_token' => $wsToken
    ]);
}

/**
 * Получить информацию о комнате (для страницы конференции)
 */
function handleRoomInfo() {
    // Аналогично join, но без токенов (используется при загрузке страницы, если уже есть токен)
    global $currentUser;
    $roomId = $_GET['room_id'] ?? '';

    if (!$roomId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID комнаты не указан']);
        return;
    }

    $db = Database::getDB();
    $stmt = $db->prepare("
        SELECT r.*, d.name as department_name,
        (SELECT COUNT(*) FROM participants WHERE room_id = r.id AND left_at IS NULL) as participants_count
        FROM rooms r
        LEFT JOIN departments d ON r.department_id = d.id
        WHERE r.id = ? AND r.cancelled = 0
    ");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        http_response_code(404);
        echo json_encode(['error' => 'Комната не найдена']);
        return;
    }

    // Проверка доступа (аналогично)
    if ($currentUser['role'] !== 'admin') {
        $userDepts = getUserDepartments($currentUser['id'], true);
        if (!in_array($room['department_id'], $userDepts)) {
            http_response_code(403);
            echo json_encode(['error' => 'Нет доступа']);
            return;
        }
    }

    echo json_encode(['room' => $room]);
}

/**
 * Завершение конференции (только для модератора/админа)
 */
function handleEndRoom() {
    global $currentUser;
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId = $input['room_id'] ?? '';

    if (!$roomId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID комнаты не указан']);
        return;
    }

    $db = Database::getDB();
    // Проверка прав: пользователь должен быть создателем комнаты или админом, или модератором этой комнаты
    $stmt = $db->prepare("
        SELECT r.*, p.is_moderator
        FROM rooms r
        LEFT JOIN participants p ON r.id = p.room_id AND p.user_id = ? AND p.left_at IS NULL
        WHERE r.id = ?
    ");
    $stmt->execute([$currentUser['id'], $roomId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        http_response_code(404);
        echo json_encode(['error' => 'Комната не найдена']);
        return;
    }

    $canEnd = ($currentUser['role'] === 'admin') || ($room['created_by'] == $currentUser['id']) || ($room['is_moderator'] == 1);
    if (!$canEnd) {
        http_response_code(403);
        echo json_encode(['error' => 'Недостаточно прав для завершения конференции']);
        return;
    }

    // Завершаем: устанавливаем end_time = NOW()
    $update = $db->prepare("UPDATE rooms SET end_time = NOW() WHERE id = ?");
    $update->execute([$roomId]);

    // Также можно проставить left_at для всех участников (выход из комнаты)
    $db->prepare("UPDATE participants SET left_at = NOW() WHERE room_id = ? AND left_at IS NULL")->execute([$roomId]);

    echo json_encode(['success' => true]);
}

/**
 * Удаление комнаты (админ или создатель)
 */
function handleDeleteRoom() {
    // Аналогично end, но физическое удаление или пометка deleted
    global $currentUser;
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId = $input['room_id'] ?? '';

    if (!$roomId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID комнаты не указан']);
        return;
    }

    $db = Database::getDB();
    $stmt = $db->prepare("SELECT created_by FROM rooms WHERE id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();

    if (!$room) {
        http_response_code(404);
        echo json_encode(['error' => 'Комната не найдена']);
        return;
    }

    if ($currentUser['role'] !== 'admin' && $room['created_by'] != $currentUser['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Недостаточно прав']);
        return;
    }

    // Мягкое удаление: помечаем cancelled = 1
    $update = $db->prepare("UPDATE rooms SET cancelled = 1 WHERE id = ?");
    $update->execute([$roomId]);

    echo json_encode(['success' => true]);
}

/**
 * Получить записи конференций
 */
function handleGetRecordings() {
    global $currentUser;
    $db = Database::getDB();

    if ($currentUser['role'] === 'admin') {
        // Админ видит все записи
        $stmt = $db->query("
            SELECT r.id, r.name, r.start_time, r.end_time, d.name as department_name, rec.url, rec.created_at
            FROM recordings rec
            JOIN rooms r ON rec.room_id = r.id
            LEFT JOIN departments d ON r.department_id = d.id
            ORDER BY rec.created_at DESC
        ");
    } else {
        // Модератор/участник видит только свои (где он был модератором или участником)
        $stmt = $db->prepare("
            SELECT DISTINCT r.id, r.name, r.start_time, r.end_time, d.name as department_name, rec.url, rec.created_at
            FROM recordings rec
            JOIN rooms r ON rec.room_id = r.id
            LEFT JOIN departments d ON r.department_id = d.id
            JOIN participants p ON r.id = p.room_id
            WHERE p.user_id = ? AND (p.is_moderator = 1 OR r.created_by = ?)
            ORDER BY rec.created_at DESC
        ");
        $stmt->execute([$currentUser['id'], $currentUser['id']]);
    }

    $recordings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Форматируем дату и время отдельно для удобства фронта
    foreach ($recordings as &$rec) {
        $dt = new DateTime($rec['start_time']);
        $rec['date'] = $dt->format('Y-m-d');
        $rec['time'] = $dt->format('H:i');
    }

    echo json_encode(['recordings' => $recordings]);
}

/**
 * Информация о записи (возможно, детали)
 */
function handleRecordingInfo() {
    // Пока заглушка
    echo json_encode(['error' => 'Not implemented']);
}

/**
 * Загрузка аватара (отдельный endpoint для формы)
 */
function handleUploadAvatar() {
    global $currentUser;
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Файл не загружен']);
        return;
    }

    $uploadDir = UPLOAD_DIR . '/avatars/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
    $filename = 'avatar_' . $currentUser['id'] . '_' . time() . '.' . $ext;
    $destination = $uploadDir . $filename;

    if (move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
        $avatarPath = '/uploads/avatars/' . $filename;
        $db = Database::getDB();
        $stmt = $db->prepare("UPDATE users SET avatar = ? WHERE id = ?");
        $stmt->execute([$avatarPath, $currentUser['id']]);

        echo json_encode(['success' => true, 'avatar_url' => $avatarPath]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка сохранения файла']);
    }
}

// ==================== Вспомогательные функции ====================

/**
 * Получить отделы пользователя
 * @param int $userId
 * @param bool $onlyIds - вернуть только массив ID
 * @return array
 */
function getUserDepartments($userId, $onlyIds = false) {
    $db = Database::getDB();
    $stmt = $db->prepare("
        SELECT d.id, d.name
        FROM departments d
        JOIN user_departments ud ON d.id = ud.department_id
        WHERE ud.user_id = ?
        ORDER BY d.name
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($onlyIds) {
        return array_column($rows, 'id');
    }
    return $rows;
}

/**
 * Генерация уникального ID комнаты
 */
function generateRoomId($length = 8) {
    return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $length);
}

/**
 * Генерация JWT токена для пользователя (используется в auth.php, но продублируем)
 */
function generateJWT($userId, $role) {
    // В реальном проекте используем библиотеку firebase/php-jwt
    // Здесь заглушка
    return base64_encode(json_encode(['user_id' => $userId, 'role' => $role, 'exp' => time() + 86400]));
}

/**
 * Проверка JWT токена (из auth.php, но для api.php она уже использована выше)
 * Здесь не нужна, так как вызывается из auth.php
 */

/**
 * Генерация токена для LiveKit (заглушка)
 */
function generateLiveKitToken($userId, $roomId) {
    // В реальности используем LiveKit SDK для создания токена
    return 'livekit_token_' . $userId . '_' . $roomId . '_' . time();
}

/**
 * Генерация токена для WebSocket (заглушка)
 */
function generateWebSocketToken($userId, $roomId) {
    return 'ws_token_' . $userId . '_' . $roomId . '_' . time();
}