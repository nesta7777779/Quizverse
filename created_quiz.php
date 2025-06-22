<?php
ob_start();
require_once 'middleware.php';

session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'use_strict_mode' => true
]);

if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    error_log('Unauthorized access attempt to created_quiz.php: ' . print_r($_SESSION, true));
    finalizeJsonOutput(['success' => false, 'message' => 'User not authenticated']);
}

require_once 'sambungan_database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    logDatabaseError("Database connection error in created_quiz.php", $e);
    finalizeJsonOutput(['success' => false, 'message' => 'Gagal terhubung ke database']);
}

function generateJoinCode() {
    return sprintf("%04d", rand(0, 9999));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ensureCleanOutput();
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'fetch_created_quizzes') {
            $stmt = $pdo->prepare('
                SELECT q.id, q.title, q.description, q.thumbnail, q.is_public, q.quiz_code, COUNT(ques.id) as question_count
                FROM quizzes q
                LEFT JOIN questions ques ON q.id = ques.quiz_id
                WHERE q.user_id = ?
                GROUP BY q.id, q.title, q.description, q.thumbnail, q.is_public, q.quiz_code
            ');
            $stmt->execute([$_SESSION['user_id']]);
            $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response = [
                'success' => true,
                'quizzes' => []
            ];

            foreach ($quizzes as $quiz) {
                $response['quizzes'][] = [
                    'id' => (int)$quiz['id'],
                    'title' => htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8'),
                    'description' => htmlspecialchars($quiz['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                    'thumbnail' => htmlspecialchars($quiz['thumbnail'] ?? 'default.jpg', ENT_QUOTES, 'UTF-8'),
                    'question_count' => (int)$quiz['question_count'],
                    'is_public' => (bool)$quiz['is_public'],
                    'join_code' => $quiz['is_public'] ? ($quiz['quiz_code'] ?? null) : null
                ];
            }

            finalizeJsonOutput($response);
        } elseif ($_POST['action'] === 'generate_join_code' && isset($_POST['quiz_id'])) {
            $quizId = filter_var($_POST['quiz_id'], FILTER_VALIDATE_INT);
            if (!$quizId) {
                finalizeJsonOutput(['success' => false, 'message' => 'Invalid quiz ID']);
            }

            $stmt = $pdo->prepare('SELECT is_public FROM quizzes WHERE id = ? AND user_id = ?');
            $stmt->execute([$quizId, $_SESSION['user_id']]);
            $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$quiz) {
                finalizeJsonOutput(['success' => false, 'message' => 'Quiz not found or unauthorized']);
            }

            if (!$quiz['is_public']) {
                finalizeJsonOutput(['success' => false, 'message' => 'Quiz must be public to generate join code']);
            }

            do {
                $joinCode = generateJoinCode();
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM quizzes WHERE quiz_code = ?');
                $stmt->execute([$joinCode]);
                $codeExists = $stmt->fetchColumn();
            } while ($codeExists);

            $stmt = $pdo->prepare('UPDATE quizzes SET quiz_code = ? WHERE id = ?');
            $stmt->execute([$joinCode, $quizId]);

            finalizeJsonOutput([
                'success' => true,
                'quiz_code' => $joinCode
            ]);
        } elseif ($_POST['action'] === 'validate_join_code' && isset($_POST['join_code'])) {
            $joinCode = trim($_POST['join_code']);
            $stmt = $pdo->prepare('SELECT id, title FROM quizzes WHERE quiz_code = ? AND is_public = 1');
            $stmt->execute([$joinCode]);
            $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($quiz) {
                finalizeJsonOutput([
                    'success' => true,
                    'quiz_id' => (int)$quiz['id'],
                    'quiz_title' => htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8')
                ]);
            } else {
                finalizeJsonOutput(['success' => false, 'message' => 'Invalid join code']);
            }
        } else {
            finalizeJsonOutput(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        logDatabaseError("Error in created_quiz.php", $e);
        finalizeJsonOutput(['success' => false, 'message' => 'Terjadi kesalahan: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    }
}

finalizeJsonOutput(['success' => false, 'message' => 'Invalid action']);
?>