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
    error_log('Unauthorized access attempt to quiz.php: ' . print_r($_SESSION, true));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        finalizeJsonOutput(['success' => false, 'message' => 'User not authenticated']);
    } else {
        header('Location: login.php');
        ob_end_flush();
        exit;
    }
}

require_once 'sambungan_database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    logDatabaseError("Database connection error in quiz.php", $e);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        finalizeJsonOutput(['success' => false, 'message' => 'Gagal terhubung ke database']);
    } else {
        die('Database connection failed. Please try again later.');
    }
}

// Create activity_logs table if it doesn't exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            activity_type VARCHAR(50) NOT NULL,
            activity_details TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    logDatabaseError("Table creation error in quiz.php", $e);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        finalizeJsonOutput(['success' => false, 'message' => 'Gagal menginisialisasi database']);
    } else {
        die('Failed to initialize database.');
    }
}

$quiz_id = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quiz_id'])) {
    $quiz_id = filter_input(INPUT_POST, 'quiz_id', FILTER_VALIDATE_INT);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['quiz_id'])) {
    $quiz_id = filter_input(INPUT_GET, 'quiz_id', FILTER_VALIDATE_INT);
}

if ($quiz_id === false || $quiz_id <= 0) {
    error_log("Invalid quiz_id received: " . var_export($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST['quiz_id'] : ($_GET['quiz_id'] ?? 'null'), true));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        finalizeJsonOutput(['success' => false, 'message' => 'ID kuis tidak valid atau bukan angka positif']);
    } else {
        header('Location: dashboard.php?error=' . urlencode('ID kuis tidak valid'));
        ob_end_flush();
        exit;
    }
}

try {
    $stmt = $pdo->prepare('
        SELECT q.id, q.title, q.description, q.thumbnail, q.is_public, q.quiz_code
        FROM quizzes q
        WHERE q.id = ? AND (q.user_id = ? OR q.is_public = 1)
    ');
    $stmt->execute([$quiz_id, $_SESSION['user_id']]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logDatabaseError("Error fetching quiz details for quiz_id: $quiz_id", $e);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        finalizeJsonOutput(['success' => false, 'message' => 'Gagal mengambil detail kuis']);
    } else {
        header('Location: dashboard.php?error=' . urlencode('Gagal mengambil detail kuis'));
        ob_end_flush();
        exit;
    }
}

if (!$quiz) {
    error_log("Quiz not found for ID: $quiz_id, User ID: {$_SESSION['user_id']}");
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        finalizeJsonOutput(['success' => false, 'message' => 'Kuis tidak ditemukan atau Anda tidak memiliki akses']);
    } else {
        header('Location: dashboard.php?error=' . urlencode('Kuis tidak ditemukan atau Anda tidak memiliki akses'));
        ob_end_flush();
        exit;
    }
}

$quiz_data = [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $stmt = $pdo->prepare('
            SELECT q.id, q.question_text, q.question_type
            FROM questions q
            WHERE q.quiz_id = ?
            ORDER BY q.id
        ');
        $stmt->execute([$quiz_id]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logDatabaseError("Error fetching questions", $e);
        header('Location: dashboard.php?error=' . urlencode('Gagal mengambil pertanyaan'));
        ob_end_flush();
        exit;
    }

    foreach ($questions as &$question) {
        $question_id = $question['id'];
        try {
            if ($question['question_type'] === 'multiple_choice') {
                $stmt = $pdo->prepare('
                    SELECT id, option_text, is_correct
                    FROM answer_options
                    WHERE question_id = ?
                    ORDER BY id
                ');
                $stmt->execute([$question_id]);
                $question['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare('
                    SELECT option_text
                    FROM answer_options
                    WHERE question_id = ? AND is_correct = 1
                    LIMIT 1
                ');
                $stmt->execute([$question_id]);
                $question['correct_answer'] = $stmt->fetch(PDO::FETCH_ASSOC)['option_text'];
            } elseif ($question['question_type'] === 'true_false') {
                $stmt = $pdo->prepare('
                    SELECT is_true
                    FROM true_false_answers
                    WHERE question_id = ?
                ');
                $stmt->execute([$question_id]);
                $question['answer'] = $stmt->fetch(PDO::FETCH_ASSOC)['is_true'];
                $question['correct_answer'] = $question['answer'] ? 'Benar' : 'Salah';
            } elseif ($question['question_type'] === 'text') {
                $stmt = $pdo->prepare('
                    SELECT answer_text
                    FROM text_answers
                    WHERE question_id = ?
                ');
                $stmt->execute([$question_id]);
                $question['answer'] = $stmt->fetch(PDO::FETCH_ASSOC)['answer_text'];
                $question['correct_answer'] = $question['answer'];
            }
            $quiz_data[] = $question;
        } catch (PDOException $e) {
            logDatabaseError("Error fetching answers for question ID: $question_id", $e);
            header('Location: dashboard.php?error=' . urlencode('Gagal mengambil jawaban'));
            ob_end_flush();
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ensureCleanOutput();
    try {
        if ($_POST['action'] === 'submit_answer') {
            $question_id = filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);
            $user_answer = trim($_POST['answer'] ?? '');
            $question_index = filter_input(INPUT_POST, 'question_index', FILTER_VALIDATE_INT);

            if (!$question_id || $question_index < 0 || empty($user_answer)) {
                error_log("Invalid answer submission: question_id=$question_id, question_index=$question_index, user_answer=$user_answer");
                finalizeJsonOutput(['success' => false, 'message' => 'Pertanyaan atau jawaban tidak valid']);
            }

            $stmt = $pdo->prepare('SELECT quiz_id, question_type FROM questions WHERE id = ?');
            $stmt->execute([$question_id]);
            $question = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$question || $question['quiz_id'] != $quiz_id) {
                finalizeJsonOutput(['success' => false, 'message' => 'Pertanyaan tidak valid untuk kuis ini']);
            }

            $is_correct = false;
            $answer_id = null;
            $correct_answer = null;

            if ($question['question_type'] === 'multiple_choice') {
                $stmt = $pdo->prepare('SELECT id, is_correct, option_text FROM answer_options WHERE id = ? AND question_id = ?');
                $stmt->execute([$user_answer, $question_id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$result) {
                    error_log("Invalid answer option: answer_id=$user_answer, question_id=$question_id");
                    finalizeJsonOutput(['success' => false, 'message' => 'Opsi jawaban tidak valid']);
                }
                $is_correct = $result['is_correct'] == 1;
                $answer_id = $result['id'];
                $stmt = $pdo->prepare('SELECT option_text FROM answer_options WHERE question_id = ? AND is_correct = 1 LIMIT 1');
                $stmt->execute([$question_id]);
                $correct_answer = $stmt->fetch(PDO::FETCH_ASSOC)['option_text'];
            } elseif ($question['question_type'] === 'true_false') {
                $stmt = $pdo->prepare('SELECT is_true FROM true_false_answers WHERE question_id = ?');
                $stmt->execute([$question_id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$result) {
                    error_log("True/false answer not found for question_id=$question_id");
                    finalizeJsonOutput(['success' => false, 'message' => 'Jawaban benar/salah tidak ditemukan']);
                }
                $correct_answer = $result['is_true'] ? 'Benar' : 'Salah';
                $is_correct = ($user_answer === 'true' && $result['is_true'] == 1) || ($user_answer === 'false' && $result['is_true'] == 0);
            } elseif ($question['question_type'] === 'text') {
                $stmt = $pdo->prepare('SELECT answer_text FROM text_answers WHERE question_id = ?');
                $stmt->execute([$question_id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$result) {
                    error_log("Text answer not found for question_id=$question_id");
                    finalizeJsonOutput(['success' => false, 'message' => 'Jawaban teks tidak ditemukan']);
                }
                $correct_answer = $result['answer_text'];
                $is_correct = strtolower($user_answer) === strtolower(trim($correct_answer));
            }

            try {
                $stmt = $pdo->prepare('
                    INSERT INTO user_answers (user_id, quiz_id, question_id, answer_id, answer_text, is_correct)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE answer_id = ?, answer_text = ?, is_correct = ?
                ');
                $stmt->execute([
                    $_SESSION['user_id'], $quiz_id, $question_id, $answer_id, $user_answer, $is_correct ? 1 : 0,
                    $answer_id, $user_answer, $is_correct ? 1 : 0
                ]);
            } catch (PDOException $e) {
                logDatabaseError("Error saving user answer for question_id: $question_id", $e);
            }

            try {
                $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, activity_type, activity_details, created_at) VALUES (?, ?, ?, NOW())');
                $stmt->execute([
                    $_SESSION['user_id'],
                    'Quiz Answer',
                    'Answered question ' . ($question_index + 1) . ' in quiz ID ' . $quiz_id . ': ' . ($is_correct ? 'Correct' : 'Incorrect')
                ]);
            } catch (PDOException $e) {
                logDatabaseError("Error logging activity for question $question_index", $e);
            }

            finalizeJsonOutput([
                'success' => true,
                'is_correct' => $is_correct,
                'correct_answer' => $correct_answer,
                'next_question' => $question_index + 1
            ]);
        } elseif ($_POST['action'] === 'complete_quiz') {
            $score = filter_input(INPUT_POST, 'score', FILTER_VALIDATE_INT);
            $total_questions = filter_input(INPUT_POST, 'total_questions', FILTER_VALIDATE_INT);

            if ($score === false || $score < 0 || $total_questions <= 0) {
                error_log("Invalid score or total_questions received: score=$score, total_questions=$total_questions");
                finalizeJsonOutput(['success' => false, 'message' => 'Skor atau jumlah pertanyaan tidak valid']);
            }

            try {
                $stmt = $pdo->prepare('INSERT INTO user_quiz_completions (user_id, quiz_id, score, total_questions) VALUES (?, ?, ?, ?)');
                $stmt->execute([$_SESSION['user_id'], $quiz_id, $score, $total_questions]);
            } catch (PDOException $e) {
                logDatabaseError("Database error in user_quiz_completions insert", $e);
                finalizeJsonOutput(['success' => false, 'message' => 'Gagal menyimpan hasil kuis']);
            }

            try {
                $stmt = $pdo->prepare('SELECT title FROM quizzes WHERE id = ?');
                $stmt->execute([$quiz_id]);
                $quiz_title = $stmt->fetch(PDO::FETCH_ASSOC)['title'] ?? 'Unknown Quiz';

                $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, activity_type, activity_details, created_at) VALUES (?, ?, ?, NOW())');
                $stmt->execute([
                    $_SESSION['user_id'],
                    'Quiz Completed',
                    'Completed quiz "' . htmlspecialchars($quiz_title, ENT_QUOTES, 'UTF-8') . '" (ID: ' . $quiz_id . ') with score ' . $score . '/' . $total_questions
                ]);
            } catch (PDOException $e) {
                logDatabaseError("Error logging quiz completion activity", $e);
            }

            finalizeJsonOutput(['success' => true, 'message' => 'Quiz berhasil diselesaikan', 'redirect' => 'quiz_result.php?quiz_id=' . $quiz_id]);
        } else {
            finalizeJsonOutput(['success' => false, 'message' => 'Aksi tidak valid']);
        }
    } catch (Exception $e) {
        logDatabaseError("Error processing POST request in quiz.php", $e);
        finalizeJsonOutput(['success' => false, 'message' => 'Terjadi kesalahan server']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuizVerse - Mainkan Quiz</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --bg-dark: #0c0620;
            --text-light: #f8f9fa;
            --shadow-color: rgba(106, 48, 195, 0.3);
            --light-blue: #60a5fa;
            --light-blue-dark: #3b82f6;
            --sage: #a3bffa;
            --sage-dark: #818cf8;
            --soft-gold: #f9e2af;
            --soft-gold-dark: #f4d03f;
            --soft-pink: #f9a8d4;
            --soft-pink-dark: #ec4899;
            --soft-green: #34d399;
            --soft-green-dark: #059669;
            --soft-red: #f87171;
            --soft-red-dark: #dc2626;
            --panel-dark: rgba(8, 4, 18, 0.9);
            --user-panel-dark: rgba(20, 16, 38, 0.95);
        }

        body, html {
            height: 100vh;
            width: 100vw;
            overflow: hidden;
            background-color: var(--bg-dark);
            margin: 0;
        }

        .bg-wrapper {
            position: absolute;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 1;
        }

        .bg-wrapper::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 50% 50%, rgba(138, 79, 255, 0.1) 0%, transparent 70%);
            z-index: -1;
            animation: glowPulse 3s infinite alternate;
        }

        @keyframes glowPulse {
            0% { opacity: 0.3; }
            100% { opacity: 0.6; }
        }

        #stars {
            position: absolute;
            width: 100%;
            height: 100%;
        }

        .star {
            position: absolute;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            animation: twinkle 5s infinite;
        }

        @keyframes twinkle {
            0%, 100% { opacity: 0.8; }
            50% { opacity: 0.3; }
        }

        .container {
            position: relative;
            padding: 2vh 2vw;
            z-index: 10;
            text-align: center;
            height: 100vh;
            width: 100vw;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .header {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            padding: 2vh 2vw;
            z-index: 11;
            position: fixed;
            top: 2vh;
            right: 0;
        }

        .user-info {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100vw;
            background: var(--user-panel-dark);
            backdrop-filter: blur(1px);
            border: none;
            padding: 2.3vh 3vw;
            color: var(--text-light);
            font-weight: 600;
            font-size: 1.2rem;
            text-align: left;
            z-index: 12;
        }

        .header-controls {
            display: flex;
            align-items: center;
            gap: 1vw;
        }

        .settings, .fullscreen {
            background: linear-gradient(135deg, var(--light-blue), var(--light-blue-dark));
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 1vh 2vw;
            border-radius: 0.6rem;
            color: var(--text-light);
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5vw;
        }

        .settings::before, .fullscreen::before {
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-right: 0.5vw;
        }

        .settings::before {
            content: '\f013';
        }

        .fullscreen::before {
            content: '\f065';
        }

        .question-number {
            position: fixed;
            top: 7vh;
            left: 50%;
            transform: translateX(-50%);
            font-size: 1.2rem;
            color: var(--soft-pink);
            background: rgba(255, 255, 255, 0.1);
            padding: 0.8vh 2vw;
            border-radius: 0.6rem;
            z-index: 11;
        }

        .question-text {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 1rem;
            padding: 6vh 3vw;
            margin: 1vh auto 1vh;
            font-size: 1.5rem;
            color: var(--text-light);
            width: 90vw;
            max-width: 90vw;
            min-height: 35vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            top: 13vh;
            transition: transform 0.5s ease, opacity 0.5s ease;
        }

        .options {
            display: flex;
            flex-direction: row;
            justify-content: center;
            gap: 1vw;
            width: 90vw;
            max-width: 90vw;
            margin: 0 auto;
            padding-bottom: 2vh;
            position: relative;
            top: 15vh;
            transition: transform 0.5s ease, opacity 0.5s ease;
        }

        .option-btn {
            border: none;
            padding: 2vh 1vw;
            border-radius: 1rem;
            font-size: 1.2rem;
            color: #fff;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease, background 0.3s ease, opacity 0.3s ease;
            text-align: center;
            flex: 1;
            min-width: 20vw;
            max-width: 22vw;
            height: 35vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .option-btn:nth-child(1) {
            background: linear-gradient(135deg, var(--light-blue), var(--light-blue-dark));
        }

        .option-btn:nth-child(2) {
            background: linear-gradient(135deg, var(--sage), var(--sage-dark));
        }

        .option-btn:nth-child(3) {
            background: linear-gradient(135deg, var(--soft-gold), var(--soft-gold-dark));
        }

        .option-btn:nth-child(4) {
            background: linear-gradient(135deg, var(--soft-pink), var(--soft-pink-dark));
        }

        .option-btn:hover {
            transform: translateY(-0.5vh) scale(1.03);
            box-shadow: 0 0.8vh 2vh rgba(138, 79, 255, 0.4);
        }

        .option-btn:active {
            transform: translateY(0) scale(0.98);
        }

        .option-btn.wrong {
            background: linear-gradient(135deg, var(--soft-red), var(--soft-red-dark)) !important;
            animation: shake 0.3s ease;
        }

        .option-btn.hidden {
            opacity: 0;
            pointer-events: none;
        }

        @keyframes shake {
            0% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            50% { transform: translateX(5px); }
            75% { transform: translateX(-5px); }
            100% { transform: translateX(0); }
        }

        .true-false-options {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 3vw;
            width: 90vw;
            max-width: 90vw;
            margin: 0 auto;
            padding-bottom: 2vh;
            position: relative;
            top: 17vh;
            transition: transform 0.5s ease, opacity 0.5s ease;
        }

        .true-false-btn {
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
            font-size: 1.4rem;
            color: var(--text-light);
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease, background 0.3s ease, opacity 0.3s ease;
            text-align: center;
            width: 35vw;
            max-width: 250px;
            height: 20vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            position: relative;
        }

        .true-false-btn.true {
            background: linear-gradient(135deg, var(--soft-green), var(--soft-green-dark));
            animation: slideInLeft 0.5s ease-out;
        }

        .true-false-btn.false {
            background: linear-gradient(135deg, var(--soft-red), var(--soft-red-dark));
            animation: slideInRight 0.5s ease-out;
        }

        .true-false-btn.wrong {
            background: linear-gradient(135deg, var(--soft-red), var(--soft-red-dark)) !important;
            animation: shake 0.3s ease;
        }

        .true-false-btn.hidden {
            opacity: 0;
            pointer-events: none;
        }

        @keyframes slideInLeft {
            from { transform: translateX(-10vw); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideInRight {
            from { transform: translateX(10vw); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .true-false-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 0.8vh 2vh rgba(138, 79, 255, 0.3);
        }

        .true-false-btn:active {
            transform: scale(0.98);
        }

        .text-answer {
            width: 70vw;
            max-width: 90vw;
            margin: 0 auto;
            padding-bottom: 2vh;
            position: relative;
            top: 18vh;
            transition: transform 0.5s ease, opacity 0.5s ease;
        }

        .text-input {
            width: 100%;
            padding: 2vh 2vw;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            font-size: 1.2rem;
            outline: none;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .text-input:focus {
            border-color: var(--soft-pink);
            box-shadow: 0 0 10px rgba(249, 168, 212, 0.3);
        }

        .submit-text-btn {
            margin-top: 2vh;
            background: linear-gradient(135deg, var(--soft-pink), var(--soft-pink-dark));
            border: none;
            padding: 2vh 4vw;
            border-radius: 1rem;
            color: var(--text-light);
            font-size: 1.2rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .submit-text-btn:hover {
            transform: translateY(-0.5vh) scale(1.03);
            box-shadow: 0 0.8vh 2vh rgba(138, 79, 255, 0.4);
        }

        .submit-text-btn:active {
            transform: translateY(0) scale(0.98);
        }

        .fade-out {
            transform: translateX(-10vw) scale(0.95);
            opacity: 0;
        }

        .fade-in {
            transform: translateX(0) scale(1);
            opacity: 1;
        }

        #intro {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            opacity: 0;
            animation: fadeIn 2s forwards;
            z-index: 20;
        }

        @keyframes fadeIn {
            to { opacity: 1; }
        }

        @keyframes countDown {
            0% { opacity: 0; transform: scale(0.5); }
            33% { opacity: 1; transform: scale(1); }
            66% { opacity: 1; transform: scale(1); }
            100% { opacity: 0; transform: scale(0.5); }
        }

        .countdown {
            font-size: 3rem;
            margin: 1vh 0;
            color: var(--soft-pink);
            animation: countDown 1s forwards;
        }

        .loading-screen {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: var(--panel-dark);
            backdrop-filter: blur(10px);
            z-index: 20;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            animation: smoothAppear 0.5s ease-out;
        }

        @keyframes smoothAppear {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .loader {
            width: 60px;
            height: 60px;
            border: 5px solid var(--soft-pink);
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 2vh;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-text {
            font-size: 1.5rem;
            font-weight: 500;
            color: var(--soft-pink);
        }

        .notification {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100vw;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 2.3vh 3vw;
            border-radius: 0;
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.15);
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.2rem;
            font-weight: 500;
            animation: slideUp 0.5s ease-out forwards;
        }

        .notification-error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        @keyframes slideUp {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes slideDown {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(100%); opacity: 0; }
        }

        @media (max-width: 768px) {
            .container {
                padding: 1vh 1vw;
            }

            .header {
                padding: 1.5vh 1vw;
                top: 1.5vh;
            }

            .question-text {
                font-size: 1.2rem;
                padding: 5vh 2vw;
                min-height: 36vh;
                margin: 1vh auto;
                top: 10vh;
            }

            .question-number {
                top: 7vh;
                font-size: 1rem;
                padding: 0.6vh 1.5vw;
            }

            .options {
                flex-direction: column;
                gap: 1.5vh;
                padding-bottom: 1.5vh;
                top: 14vh;
            }

            .option-btn {
                font-size: 1rem;
                height: 10vh;
                padding: 1.5vh 1.5vw;
                min-width: unset;
                max-width: 70vw;
            }

            .true-false-options {
                flex-direction: column;
                gap: 2vh;
                align-items: center;
                padding-bottom: 1.5vh;
                top: 14vh;
            }

            .true-false-btn {
                font-size: 1.2rem;
                width: 70vw;
                max-width: 70vw;
                height: 10vh;
                border-radius: 0.8rem;
            }

            .true-false-btn.true, .true-false-btn.false {
                transform: translateY(0);
                animation: fadeInOption 0.5s ease-out;
            }

            @keyframes fadeInOption {
                from { opacity: 0; transform: translateY(2vh); }
                to { opacity: 1; transform: translateY(0); }
            }

            .true-false-btn:hover {
                transform: scale(1.05);
            }

            .true-false-btn:active {
                transform: scale(0.98);
            }

            .text-answer {
                font-size: 1rem;
                padding-bottom: 1.5vh;
                top: 14vh;
            }

            .text-input {
                font-size: 1rem;
                padding: 1.5vh 1.5vw;
            }

            .submit-text-btn {
                font-size: 1rem;
                padding: 1.5vh 3vw;
            }

            .user-info, .settings, .fullscreen {
                font-size: 0.9rem;
                padding: 0.8vh 1.5vw;
            }

            .header-controls {
                gap: 0.5vw;
            }

            .notification {
                font-size: 1rem;
                padding: 2vh 2vw;
            }

            .loader {
                width: 40px;
                height: 40px;
                border-width: 4px;
            }

            .loading-text {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="bg-wrapper">
        <div id="stars"></div>
    </div>
    <div class="container" id="quizContainer" style="display: none;">
        <div class="header">
            <div class="header-controls">
                <button class="settings">Pengaturan</button>
                <button class="fullscreen">Layar Penuh</button>
            </div>
        </div>
        <div class="question-number">🔥 <span id="currentQuestion">1</span>/<?php echo count($quiz_data); ?></div>
        <div class="question-text fade-in" id="questionText"></div>
        <div class="options fade-in" id="options" style="display: none;"></div>
        <div class="true-false-options fade-in" id="trueFalseOptions" style="display: none;"></div>
        <div class="text-answer fade-in" id="textAnswer" style="display: none;">
            <input type="text" class="text-input" id="textInput" placeholder="Masukkan jawaban Anda">
            <button class="submit-text-btn" id="submitTextBtn">Kirim</button>
        </div>
        <div class="user-info"><?php echo htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <div id="intro">
        <h1 style="font-size: 2.5rem; color: var(--soft-pink);">Bergabung</h1>
        <div class="countdown" id="countdown">3</div>
        <div class="countdown" id="countdown2" style="display: none;">2</div>
        <div class="countdown" id="countdown3" style="display: none;">1</div>
        <div class="countdown" id="startText" style="display: none;">Mulai!</div>
    </div>
    <div class="loading-screen" id="loadingScreen">
        <div class="loader"></div>
        <div class="loading-text">Menghitung Skor...</div>
    </div>

    <script>
        const quizData = <?php echo json_encode($quiz_data, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
        const quizId = <?php echo json_encode($quiz_id, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
        const quizContainer = document.getElementById('quizContainer');
        const intro = document.getElementById('intro');
        const countdown = document.getElementById('countdown');
        const countdown2 = document.getElementById('countdown2');
        const countdown3 = document.getElementById('countdown3');
        const startText = document.getElementById('startText');
        const questionText = document.getElementById('questionText');
        const options = document.getElementById('options');
        const trueFalseOptions = document.getElementById('trueFalseOptions');
        const textAnswer = document.getElementById('textAnswer');
        const textInput = document.getElementById('textInput');
        const submitTextBtn = document.getElementById('submitTextBtn');
        const currentQuestion = document.getElementById('currentQuestion');
        const starsContainer = document.getElementById('stars');
        const fullscreenBtn = document.querySelector('.fullscreen');
        const settingsBtn = document.querySelector('.settings');
        const loadingScreen = document.getElementById('loadingScreen');

        let currentQ = 0;
        let score = 0;
        let answers = [];

        // Levenshtein distance function for fuzzy matching
        function levenshteinDistance(a, b) {
            const matrix = [];
            for (let i = 0; i <= b.length; i++) {
                matrix[i] = [i];
            }
            for (let j = 0; j <= a.length; j++) {
                matrix[0][j] = j;
            }
            for (let i = 1; i <= b.length; i++) {
                for (let j = 1; j <= a.length; j++) {
                    if (b.charAt(i - 1) === a.charAt(j - 1)) {
                        matrix[i][j] = matrix[i - 1][j - 1];
                    } else {
                        matrix[i][j] = Math.min(
                            matrix[i - 1][j - 1] + 1,
                            matrix[i][j - 1] + 1,
                            matrix[i - 1][j] + 1
                        );
                    }
                }
            }
            return matrix[b.length][a.length];
        }

        // Generate stars for background
        for (let i = 0; i < 80; i++) {
            const star = document.createElement('div');
            star.classList.add('star');
            const size = Math.random() * 0.3 + 0.1;
            star.style.width = size + 'vw';
            star.style.height = size + 'vw';
            star.style.left = Math.random() * 100 + '%';
            star.style.top = Math.random() * 100 + '%';
            star.style.animationDelay = Math.random() * 5 + 's';
            star.style.opacity = Math.random() * 0.5 + 0.5;
            starsContainer.appendChild(star);
        }

        // Show notification
        function showNotification(message, type = 'info') {
            console.log(`Notification: ${message} (${type})`);
            const notification = document.createElement('div');
            notification.className = `notification notification-${type === 'error' ? 'error' : 'success'}`;
            const icons = {
                success: 'fas fa-check-circle',
                error: 'fas fa-exclamation-circle'
            };
            notification.innerHTML = `<i class="${icons[type]}"></i><span>${message}</span>`;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.style.animation = 'slideDown 0.5s ease-in forwards';
                setTimeout(() => notification.remove(), 500);
            }, 3000);
        }

        // Start quiz with countdown
        function startQuiz() {
            console.log('Starting quiz...');
            let count = 3;
            countdown.style.display = 'block';
            const interval = setInterval(() => {
                count--;
                if (count === 2) {
                    countdown.style.display = 'none';
                    countdown2.style.display = 'block';
                } else if (count === 1) {
                    countdown2.style.display = 'none';
                    countdown3.style.display = 'block';
                } else if (count === 0) {
                    countdown3.style.display = 'none';
                    startText.style.display = 'block';
                    clearInterval(interval);
                    setTimeout(() => {
                        intro.style.display = 'none';
                        quizContainer.style.display = 'flex';
                        loadQuestion(currentQ);
                    }, 1000);
                }
            }, 1000);
        }

        // Animate question out
        function animateQuestionOut() {
            return new Promise(resolve => {
                [questionText, options, trueFalseOptions, textAnswer].forEach(el => {
                    if (el.style.display !== 'none') {
                        el.classList.remove('fade-in');
                        el.classList.add('fade-out');
                    }
                });
                setTimeout(resolve, 500);
            });
        }

        // Animate question in
        function animateQuestionIn() {
            [questionText, options, trueFalseOptions, textAnswer].forEach(el => {
                if (el.style.display !== 'none') {
                    el.classList.remove('fade-out');
                    el.classList.add('fade-in');
                }
            });
        }

        // Load question
        function loadQuestion(index) {
            console.log(`Loading question ${index + 1} of ${quizData.length}`);
            if (index >= quizData.length) {
                showResults();
                return;
            }
            currentQuestion.textContent = index + 1;
            const question = quizData[index];
            questionText.textContent = question.question_text;
            options.style.display = 'none';
            trueFalseOptions.style.display = 'none';
            textAnswer.style.display = 'none';

            if (question.question_type === 'multiple_choice') {
                options.style.display = 'flex';
                options.innerHTML = question.options.map((opt, i) => `
                    <button class="option-btn" data-option-id="${opt.id}">${opt.option_text}</button>
                `).join('');
                options.querySelectorAll('.option-btn').forEach(btn => {
                    btn.addEventListener('click', () => handleAnswer(index, btn.dataset.optionId, btn));
                });
            } else if (question.question_type === 'true_false') {
                trueFalseOptions.style.display = 'flex';
                trueFalseOptions.innerHTML = `
                    <button class="true-false-btn true" data-value="true">Benar</button>
                    <button class="true-false-btn false" data-value="false">Salah</button>
                `;
                trueFalseOptions.querySelectorAll('.true-false-btn').forEach(btn => {
                    btn.addEventListener('click', () => handleAnswer(index, btn.dataset.value, btn));
                });
            } else if (question.question_type === 'text') {
                textAnswer.style.display = 'block';
                textInput.value = '';
                submitTextBtn.onclick = () => handleAnswer(index, textInput.value.trim(), submitTextBtn);
            }
            animateQuestionIn();
        }

        // Handle answer submission
        async function handleAnswer(index, answer, button) {
            console.log(`Handling answer for question ${index + 1}: Answer=${answer}`);
            const question = quizData[index];
            const formData = new FormData();
            formData.append('action', 'submit_answer');
            formData.append('quiz_id', quizId);
            formData.append('question_id', question.id);
            formData.append('answer', answer);
            formData.append('question_index', index);

            try {
                const response = await fetch('quiz.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error: ${response.status}`);
                }

                const text = await response.text();
                if (!text.trim()) {
                    throw new Error('Empty response from server');
                }

                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('JSON parse error:', e.message, 'Response:', text);
                    throw new Error('Invalid server response format');
                }

                if (data.success) {
                    let displayAnswer = answer;
                    let isCorrect = data.is_correct;

                    if (question.question_type === 'multiple_choice') {
                        displayAnswer = question.options.find(opt => opt.id == answer)?.option_text || answer;
                    } else if (question.question_type === 'true_false') {
                        displayAnswer = answer === 'true' ? 'Benar' : 'Salah';
                    } else if (question.question_type === 'text') {
                        // Fuzzy matching for text answers
                        const correctAnswer = question.correct_answer.toLowerCase().trim();
                        const userAnswer = answer.toLowerCase().trim();
                        const distance = levenshteinDistance(userAnswer, correctAnswer);
                        const maxLength = Math.max(userAnswer.length, correctAnswer.length);
                        const similarityThreshold = 0.8; // 80% similarity
                        const similarity = maxLength > 0 ? 1 - distance / maxLength : 1;
                        if (!isCorrect && similarity >= similarityThreshold) {
                            isCorrect = true;
                            console.log(`Fuzzy match: User answer "${userAnswer}" is similar to "${correctAnswer}" (similarity: ${similarity})`);
                        }
                    }

                    if (isCorrect) {
                        score++;
                        showNotification('Jawaban benar!', 'success');
                        button.style.background = 'linear-gradient(135deg, #10b981, #059669)';
                    } else {
                        showNotification('Jawaban salah!', 'error');
                        button.classList.add('wrong');
                        if (question.question_type === 'multiple_choice') {
                            options.querySelectorAll('.option-btn').forEach(btn => {
                                const opt = question.options.find(o => o.id == btn.dataset.optionId);
                                if (opt.is_correct) {
                                    btn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
                                } else if (btn.dataset.optionId !== answer) {
                                    btn.classList.add('hidden');
                                }
                            });
                        } else if (question.question_type === 'true_false') {
                            trueFalseOptions.querySelectorAll('.true-false-btn').forEach(btn => {
                                const isCorrect = (btn.dataset.value === 'true' && question.answer == 1) || 
                                                (btn.dataset.value === 'false' && question.answer == 0);
                                if (isCorrect) {
                                    btn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
                                } else if (btn.dataset.value !== answer) {
                                    btn.classList.add('hidden');
                                }
                            });
                        }
                    }

                    answers[index] = {
                        question: question.question_text,
                        selected: displayAnswer,
                        is_correct: isCorrect,
                        correct_answer: data.correct_answer || question.correct_answer
                    };

                    if (question.question_type === 'multiple_choice' || question.question_type === 'true_false') {
                        button.parentElement.querySelectorAll('button').forEach(b => b.disabled = true);
                    } else {
                        submitTextBtn.disabled = true;
                        textInput.disabled = true;
                    }

                    setTimeout(async () => {
                        await animateQuestionOut();
                        loadQuestion(data.next_question || index + 1);
                    }, 2500);
                } else {
                    showNotification(data.message || 'Gagal memproses jawaban', 'error');
                }
            } catch (error) {
                console.error('Fetch error:', error.message);
                showNotification('Terjadi kesalahan: ' + error.message, 'error');
            }
        }

        // Show quiz results
        async function showResults() {
            console.log(`Showing results: Score=${score}, Total=${quizData.length}`);
            quizContainer.style.display = 'none';
            loadingScreen.style.display = 'flex';
            
            await new Promise(resolve => setTimeout(resolve, 2000));

            const formData = new FormData();
            formData.append('action', 'complete_quiz');
            formData.append('quiz_id', quizId);
            formData.append('score', score);
            formData.append('total_questions', quizData.length);

            try {
                const response = await fetch('quiz.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error: ${response.status}`);
                }

                const text = await response.text();
                if (!text.trim()) {
                    throw new Error('Empty response from server');
                }

                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('JSON parse error:', e.message, 'Response:', text);
                    throw new Error('Invalid server response format');
                }

                if (data.success) {
                    window.location.href = data.redirect + '&score=' + encodeURIComponent(score) + '&total=' + encodeURIComponent(quizData.length) + '&answers=' + encodeURIComponent(JSON.stringify(answers));
                } else {
                    showNotification(data.message || 'Gagal menyimpan hasil kuis', 'error');
                }
            } catch (error) {
                console.error('Fetch error:', error.message);
                showNotification('Terjadi kesalahan saat menyimpan hasil: ' + error.message, 'error');
            }
        }

        // Toggle fullscreen
        function toggleFullscreen(button, exitText, enterText) {
            console.log('Toggling fullscreen...');
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().then(() => {
                    button.innerHTML = exitText;
                    showNotification('Masuk ke mode layar penuh', 'success');
                }).catch(err => {
                    console.error(`Error enabling fullscreen: ${err.message}`);
                    showNotification('Gagal masuk ke mode layar penuh', 'error');
                });
            } else {
                document.exitFullscreen().then(() => {
                    button.innerHTML = enterText;
                    showNotification('Keluar dari mode layar penuh', 'success');
                }).catch(err => {
                    console.error(`Error exiting fullscreen: ${err.message}`);
                    showNotification('Gagal keluar dari mode layar penuh', 'error');
                });
            }
        }

        // Event listeners for header buttons
        settingsBtn.addEventListener('click', () => {
            console.log('Settings clicked');
            if (confirm('Keluar ke dashboard? Progres kuis akan disimpan.')) {
                window.location.href = 'dashboard.php';
            }
        });

        fullscreenBtn.addEventListener('click', () => {
            console.log('Fullscreen clicked');
            toggleFullscreen(fullscreenBtn, '<i class="fas fa-compress"></i> Keluar Layar Penuh', '<i class="fas fa-expand"></i> Layar Penuh');
        });

        document.addEventListener('fullscreenchange', () => {
            console.log('Fullscreen state changed');
            document.body.style.width = '100vw';
            document.body.style.height = '100vh';
            quizContainer.style.width = '100vw';
            quizContainer.style.height = '100vh';
            quizContainer.style.display = quizContainer.style.display === 'none' ? 'none' : 'flex';
            quizContainer.style.flexDirection = 'column';
        });

        // Initialize quiz
        function initialize() {
            console.log('Initializing quiz...');
            if (quizData.length === 0) {
                showNotification('Kuis tidak memiliki pertanyaan', 'error');
                setTimeout(() => {
                    window.location.href = 'dashboard.php';
                }, 2000);
                return;
            }
            startQuiz();
        }

        try {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => {
                    console.log('DOM fully loaded');
                    initialize();
                });
            } else {
                console.log('DOM already loaded');
                initialize();
            }
        } catch (error) {
            console.error('Initialization error:', error);
            showNotification('Terjadi kesalahan saat memulai kuis', 'error');
            setTimeout(() => {
                window.location.href = 'dashboard.php';
            }, 2000);
        }
    </script>
</body>
</html>
<?php
    ob_end_flush();
}
?>