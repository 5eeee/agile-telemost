<?php
// database.php - класс для работы с БД (PDO синглтон)

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $this->pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec("SET NAMES " . DB_CHARSET);
        } catch (PDOException $e) {
            // В продакшене логировать, не показывать пользователю
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    public static function getDB() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }

    // Запрещаем клонирование
    private function __clone() {}
    public function __wakeup() {}
}