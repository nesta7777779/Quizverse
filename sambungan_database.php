<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

const DB_HOST = 'localhost';
const DB_PORT = 3307;
const DB_NAME = 'quizverse';
const DB_USER = 'root';
const DB_PASS = '';

/**
 * Log database errors to file
 * @param string $message Error message
 * @param Exception $exception Exception object
 */
function logDatabaseError($message, $exception = null) {
    $logMessage = date('Y-m-d H:i:s') . " - $message";
    if ($exception) {
        $logMessage .= ": " . $exception->getMessage() . "\nStack trace: " . $exception->getTraceAsString();
    }
    $logMessage .= "\n";
    error_log($logMessage, 3, __DIR__ . '/database_errors.log');
}

class Database {
    private $pdo;
    private static $instance = null;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->pdo->exec("USE " . DB_NAME);
            $this->initializeDatabase();
        } catch (PDOException $e) {
            logDatabaseError("Database connection error", $e);
            throw new Exception('Database connection failed');
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    private function initializeDatabase() {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    fullname VARCHAR(100) NOT NULL,
                    username VARCHAR(50) NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_username (username)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS quizzes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description TEXT,
                    thumbnail VARCHAR(255) NULL,
                    is_public TINYINT(1) NOT NULL DEFAULT 0,
                    quiz_code CHAR(4) UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_user_id (user_id),
                    INDEX idx_quiz_code (quiz_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS questions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    quiz_id INT NOT NULL,
                    question_text TEXT NOT NULL,
                    question_type ENUM('multiple_choice', 'true_false', 'text') NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
                    INDEX idx_quiz_id (quiz_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS answer_options (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    question_id INT NOT NULL,
                    option_text VARCHAR(255) NOT NULL,
                    is_correct TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
                    INDEX idx_question_id (question_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS true_false_answers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    question_id INT NOT NULL,
                    is_true TINYINT(1) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
                    UNIQUE KEY uk_question_id (question_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS text_answers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    question_id INT NOT NULL,
                    answer_text VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
                    UNIQUE KEY uk_question_id (question_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS user_answers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    quiz_id INT NOT NULL,
                    question_id INT NOT NULL,
                    answer_id INT NULL,
                    answer_text VARCHAR(255) NULL,
                    is_correct TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
                    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
                    FOREIGN KEY (answer_id) REFERENCES answer_options(id) ON DELETE SET NULL,
                    UNIQUE KEY uk_user_quiz_question (user_id, quiz_id, question_id),
                    INDEX idx_user_quiz (user_id, quiz_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS user_quiz_completions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    quiz_id INT NOT NULL,
                    score INT NOT NULL,
                    total_questions INT NOT NULL,
                    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
                    INDEX idx_user_quiz (user_id, quiz_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $e) {
            logDatabaseError("Database initialization error", $e);
            throw new Exception('Failed to initialize database');
        }
    }
}