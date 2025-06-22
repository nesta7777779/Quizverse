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
    error_log('Unauthorized access attempt to activity.php: ' . print_r($_SESSION, true));
    finalizeJsonOutput(['success' => false, 'message' => 'User not authenticated', 'my_quizzes' => [], 'notifications' => []]);
}

require_once 'sambungan_database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    logDatabaseError("Database connection error in activity.php", $e);
    finalizeJsonOutput(['success' => false, 'message' => 'Gagal terhubung ke database', 'my_quizzes' => [], 'notifications' => []]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    finalizeJsonOutput(['success' => false, 'message' => 'Invalid request', 'my_quizzes' => [], 'notifications' => []]);
}

ensureCleanOutput();

try {
    switch ($_POST['action']) {
        case 'fetch_activity':
            // Fetch quizzes played by the user
            try {
                $stmt = $pdo->prepare("
                    SELECT uqc.quiz_id, q.title AS quiz_title, uqc.score, uqc.total_questions, 
                           DATE_FORMAT(uqc.completed_at, '%d %b %Y %H:%i') AS completed_at
                    FROM user_quiz_completions uqc
                    JOIN quizzes q ON uqc.quiz_id = q.id
                    WHERE uqc.user_id = ?
                    ORDER BY uqc.completed_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $my_quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (PDOException $e) {
                logDatabaseError("Error fetching user quiz completions for user_id: {$_SESSION['user_id']}", $e);
                $my_quizzes = [];
            }

            // Fetch notifications for quizzes created by the user played by others and other activities
            try {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT uqc.id, uqc.quiz_id, q.title AS quiz_title, uqc.score, uqc.total_questions, 
                           COALESCE(u.username, 'Unknown') AS username, 
                           DATE_FORMAT(uqc.completed_at, '%d %b %Y %H:%i') AS created_at,
                           'quiz_played' AS activity_type
                    FROM user_quiz_completions uqc
                    JOIN quizzes q ON uqc.quiz_id = q.id
                    JOIN users u ON uqc.user_id = u.id
                    WHERE q.user_id = ? AND uqc.user_id != ?
                    UNION
                    SELECT al.id, NULL AS quiz_id, '' AS quiz_title, NULL AS score, NULL AS total_questions, 
                           NULL AS username, DATE_FORMAT(al.created_at, '%d %b %Y %H:%i') AS created_at,
                           LOWER(REPLACE(al.activity_type, ' ', '_')) AS activity_type
                    FROM activity_logs al
                    WHERE al.user_id = ? 
                    AND al.activity_type IN ('Password Changed', 'Profile Updated', 'Account Deleted')
                    ORDER BY created_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
                $notifications_raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (PDOException $e) {
                logDatabaseError("Error fetching notifications for user_id: {$_SESSION['user_id']}", $e);
                $notifications_raw = [];
            }

            // Process notifications
            $notifications = [];
            foreach ($notifications_raw as $notif) {
                if ($notif['activity_type'] === 'quiz_played') {
                    $notifications[] = [
                        'type' => 'quiz_played',
                        'quiz_id' => (int)$notif['quiz_id'],
                        'quiz_title' => htmlspecialchars($notif['quiz_title'], ENT_QUOTES, 'UTF-8'),
                        'username' => htmlspecialchars($notif['username'], ENT_QUOTES, 'UTF-8'),
                        'score' => isset($notif['score']) ? (int)$notif['score'] : null,
                        'total_questions' => isset($notif['total_questions']) ? (int)$notif['total_questions'] : null,
                        'created_at' => htmlspecialchars($notif['created_at'], ENT_QUOTES, 'UTF-8')
                    ];
                } else {
                    // Fetch activity details for non-quiz notifications
                    $stmt = $pdo->prepare("
                        SELECT activity_type, activity_details
                        FROM activity_logs
                        WHERE id = ?
                    ");
                    $stmt->execute([$notif['id']]);
                    $activity = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($activity) {
                        $notifications[] = [
                            'type' => htmlspecialchars($notif['activity_type'], ENT_QUOTES, 'UTF-8'),
                            'details' => htmlspecialchars($activity['activity_details'], ENT_QUOTES, 'UTF-8'),
                            'username' => null,
                            'score' => null,
                            'total_questions' => null,
                            'created_at' => htmlspecialchars($notif['created_at'], ENT_QUOTES, 'UTF-8')
                        ];
                    }
                }
            }

            $response = [
                'success' => true,
                'my_quizzes' => array_map(function ($quiz) {
                    return [
                        'quiz_id' => (int)$quiz['quiz_id'],
                        'quiz_title' => htmlspecialchars($quiz['quiz_title'], ENT_QUOTES, 'UTF-8'),
                        'score' => (int)$quiz['score'],
                        'total_questions' => (int)$quiz['total_questions'],
                        'completed_at' => htmlspecialchars($quiz['completed_at'], ENT_QUOTES, 'UTF-8')
                    ];
                }, $my_quizzes),
                'notifications' => $notifications
            ];

            error_log('Activity.php response: ' . json_encode($response));
            finalizeJsonOutput($response);
            break;

        case 'clear_activity':
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("DELETE FROM activity_logs WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $stmt = $pdo->prepare("DELETE FROM user_quiz_completions WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $pdo->commit();

                finalizeJsonOutput(['success' => true, 'message' => 'Semua aktivitas berhasil dihapus', 'my_quizzes' => [], 'notifications' => []]);
            } catch (PDOException $e) {
                $pdo->rollBack();
                logDatabaseError("Error clearing activity for user_id: {$_SESSION['user_id']}", $e);
                finalizeJsonOutput(['success' => false, 'message' => 'Gagal menghapus aktivitas: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'my_quizzes' => [], 'notifications' => []]);
            }
            break;

        default:
            finalizeJsonOutput(['success' => false, 'message' => 'Aksi tidak valid', 'my_quizzes' => [], 'notifications' => []]);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logDatabaseError("General error in activity.php", $e);
    finalizeJsonOutput(['success' => false, 'message' => 'Terjadi kesalahan: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'my_quizzes' => [], 'notifications' => []]);
}
?>