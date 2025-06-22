<?php
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
    header('Location: login.php');
    exit;
}

require_once 'sambungan_database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_quiz') {
    header('Content-Type: application/json');
    try {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_public = filter_var($_POST['is_public'], FILTER_VALIDATE_INT) ?? 0;
        $questions = json_decode($_POST['questions'], true);

        if (empty($title)) {
            throw new Exception('Judul quiz diperlukan');
        }

        if (!is_array($questions) || empty($questions)) {
            throw new Exception('Setidaknya satu pertanyaan diperlukan');
        }

        // Handle thumbnail upload
        $thumbnail = null;
        if (isset($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $max_size = 5 * 1024 * 1024; // 5MB
            if (!in_array($_FILES['cover']['type'], $allowed_types)) {
                throw new Exception('Format gambar tidak didukung. Gunakan JPG, PNG, atau GIF');
            }
            if ($_FILES['cover']['size'] > $max_size) {
                throw new Exception('Ukuran gambar maksimal 5MB');
            }
            $ext = pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('quiz_', true) . '.' . $ext;
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $upload_path = $upload_dir . $filename;
            if (!move_uploaded_file($_FILES['cover']['tmp_name'], $upload_path)) {
                throw new Exception('Gagal mengunggah gambar');
            }
            $thumbnail = 'uploads/' . $filename;
        }

        $quiz_code = null;
        if ($is_public) {
            $stmt = $pdo->prepare('SELECT quiz_code FROM quizzes WHERE quiz_code = ?');
            do {
                $code = sprintf("%04d", mt_rand(0, 9999)); 
                $stmt->execute([$code]);
            } while ($stmt->fetch());
            $quiz_code = $code;
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO quizzes (user_id, title, description, thumbnail, is_public, quiz_code) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$_SESSION['user_id'], $title, $description ?: null, $thumbnail, $is_public, $quiz_code]);
        $quiz_id = $pdo->lastInsertId();

        foreach ($questions as $q) {
            if (empty($q['text']) || empty($q['type'])) {
                throw new Exception('Pertanyaan atau tipe tidak valid');
            }

            $stmt = $pdo->prepare('INSERT INTO questions (quiz_id, question_text, question_type) VALUES (?, ?, ?)');
            $stmt->execute([$quiz_id, $q['text'], $q['type']]);
            $question_id = $pdo->lastInsertId();

            if ($q['type'] === 'multiple_choice') {
                if (!isset($q['choices']) || !is_array($q['choices']) || count($q['choices']) !== 4 || !isset($q['correct'])) {
                    throw new Exception('Pilihan ganda harus memiliki 4 opsi dan jawaban benar');
                }
                $correct_index = filter_var($q['correct'], FILTER_VALIDATE_INT);
                if ($correct_index < 0 || $correct_index > 3) {
                    throw new Exception('Indeks jawaban benar tidak valid');
                }
                foreach ($q['choices'] as $index => $option) {
                    if (empty($option)) {
                        throw new Exception('Opsi pilihan ganda tidak boleh kosong');
                    }
                    $stmt = $pdo->prepare('INSERT INTO answer_options (question_id, option_text, is_correct) VALUES (?, ?, ?)');
                    $stmt->execute([$question_id, $option, $index == $correct_index ? 1 : 0]);
                }
            } elseif ($q['type'] === 'true_false') {
                if (!isset($q['answer'])) {
                    throw new Exception('Jawaban benar/salah diperlukan');
                }
                $answer = filter_var($q['answer'], FILTER_VALIDATE_BOOLEAN);
                $stmt = $pdo->prepare('INSERT INTO true_false_answers (question_id, is_true) VALUES (?, ?)');
                $stmt->execute([$question_id, $answer ? 1 : 0]);
            } elseif ($q['type'] === 'text') {
                if (empty($q['answer'])) {
                    throw new Exception('Jawaban teks diperlukan');
                }
                $stmt = $pdo->prepare('INSERT INTO text_answers (question_id, answer_text) VALUES (?, ?)');
                $stmt->execute([$question_id, $q['answer']]);
            } else {
                throw new Exception('Tipe pertanyaan tidak valid');
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Quiz berhasil disimpan',
            'code' => $is_public ? $quiz_code : null,
            'redirect' => 'dashboard.php?auto_click=created_quiz'
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membuat Quiz - QuizVerse</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --primary: #667eea;
            --primary-dark: #764ba2;
            --secondary: #ff6baf;
            --secondary-light: #ff9ec7;
            --secondary-dark: #da5497;
            --success: #10b981;
            --success-dark: #059669;
            --error: #ef4444;
            --error-dark: #dc2626;
            --warning: #f59e0b;
            --warning-dark: #d97706;
            --neutral: #6b7280;
            --neutral-dark: #4b5563;
            --text-light: #f8f9fa;
            --bg-dark: #1e1e2e;
            --bg-surface: #2a2a3e;
            --shadow: rgba(102, 126, 234, 0.3);
            --hover-bg: rgba(255, 107, 175, 0.2);
            --border-radius: 12px;
        }

        html, body {
            background: linear-gradient(135deg, var(--bg-dark), var(--bg-surface));
            color: var(--text-light);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 80px;
            background: linear-gradient(90deg, #1e1e2e 0%, #2a2a3e 50%, #373751 100%);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            display: flex;
            align-items: center;
            transition: transform 0.3s ease-out;
            backdrop-filter: blur(20px);
        }

        .navbar-header {
            padding: 0 2rem;
            display: flex;
            align-items: center;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
            z-index: 1;
        }

        .logo-icon {
            width: 3rem;
            height: 3rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #fff;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            animation: float 3s ease-in-out infinite;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .logo-icon::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transform: rotate(-45deg);
            transition: all 0.5s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-0.3rem) rotate(2deg); }
        }

        .logo-text {
            font-size: 1.6rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark), var(--secondary));
            background-clip: text;
            -webkit-text-fill-color: transparent;
            background-size: 200% 200%;
            animation: gradientShift 3s ease-in-out infinite;
        }

        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .menu-section {
            flex: 1;
            display: flex;
            justify-content: flex-start;
            align-items: center;
            padding-left: 4rem;
        }

        .menu-list {
            list-style: none;
            display: flex;
            gap: 1.5rem;
            margin: 0;
            padding: 0;
        }

        .menu-item {
            display: flex;
            align-items: center;
        }

        .menu-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            letter-spacing: 0.02rem;
            position: relative;
            border-radius: 8px;
            background: transparent;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .menu-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 0;
            height: 100%;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.2), rgba(118, 75, 162, 0.2));
            transition: width 0.3s ease;
            border-radius: 8px;
        }

        .menu-link::after {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent, rgba(255, 255, 255, 0.05), transparent);
            transform: translateX(-100%);
            transition: transform 0.6s ease;
        }

        .menu-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.08);
            transform: translateX(0.5rem);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
        }

        .menu-link:hover::before {
            width: 0.3rem;
        }

        .menu-link:hover::after {
            transform: translateX(100%);
        }

        .menu-link:hover .grid-squares {
            transform: rotate(5deg) scale(1.1);
        }

        .menu-link:hover .square {
            background: var(--primary);
            box-shadow: 0 0 8px rgba(102, 126, 234, 0.6);
        }

        .menu-link:hover i {
            transform: scale(1.1);
        }

        .menu-item.active .menu-link {
            color: #fff;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.3), rgba(118, 75, 162, 0.3));
            border-left: 0.2rem solid var(--primary);
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.3);
        }

        .dashboard-icon {
            width: 3rem;
            height: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .grid-squares {
            display: grid;
            grid-template-columns: repeat(2, 5px);
            grid-template-rows: repeat(2, 5px);
            gap: 1.5px;
            width: 50px;
            transition: transform 0.3s ease;
        }

        .square {
            width: 5px;
            height: 5px;
            background: currentColor;
            border-radius: 2px;
            box-shadow: 0 0 4px rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
        }

        .square:nth-child(3) {
            grid-column: 1 / 3;
            width: 12px;
        }

        .menu-link i {
            font-size: 1.2rem;
            width: 1.5rem;
            text-align: center;
            flex-shrink: 0;
            text-shadow: 0 0 4px rgba(255, 255, 255, 0.3);
            transition: transform 0.3s ease;
        }

        .mobile-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            border-radius: 12px;
            width: 3rem;
            height: 3rem;
            color: #fff;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            transition: transform 0.3s ease;
        }

        .mobile-toggle:active {
            transform: translateY(2px);
            box-shadow: 0 2px 5px rgba(102, 126, 234, 0.2);
        }

        .navbar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s ease;
            backdrop-filter: blur(5px);
        }

        .navbar-overlay.show {
            opacity: 1;
        }

        .main-content {
            padding: 100px 20px 20px;
            max-width: 1400px;
            margin: 0 auto;
            transition: margin-left 0.3s ease, opacity 0.5s ease;
        }

        .quiz-builder {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(12px);
        }

        .builder-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .header-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 2.5rem;
            background: linear-gradient(90deg, var(--primary), var(--secondary), var(--secondary-dark));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header-subtitle {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1.1rem;
        }

        .quiz-form {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .quiz-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        .action-code-container {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .questions-section {
            grid-column: 1 / -1;
        }

        .form-section {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(255, 107, 175, 0.1));
            border-radius: 10px;
            padding: 1.5rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        }

        .section-header i {
            color: var(--primary);
            font-size: 1.8rem;
            text-shadow: 0 0 4px var(--shadow);
        }

        .section-header h2 {
            font-size: 1.8rem;
            color: var(--text-light);
        }

        .input-group {
            margin-bottom: 1.5rem;
        }

        .input-group label {
            display: block;
            font-weight: 500;
            color: var(--text-light);
            margin-bottom: 0.5rem;
        }

        .input-group input,
        .input-group textarea {
            width: 100%;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .input-group input:focus,
        .input-group textarea:focus {
            border-color: var(--secondary);
            background: rgba(255, 255, 255, 0.2);
            box-shadow: 0 0 10px var(--shadow);
            outline: none;
        }

        .input-group input:invalid,
        .input-group textarea:invalid {
            border-color: var(--error);
        }

        .error-message {
            color: var(--error);
            font-size: 0.85rem;
            margin-top: 0.5rem;
            display: none;
        }

        .cover-preview {
            margin-top: 1rem;
            text-align: center;
        }

        .preview-image {
            max-width: 100%;
            max-height: 150px;
            border-radius: 8px;
            border: 1px solid var(--primary);
        }

        .input-note {
            color: var(--success);
            font-size: 0.85rem;
            margin-top: 0.5rem;
            display: block;
        }

        .add-question-btn {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--text-light);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            margin-left: auto;
            transition: transform 0.2s ease;
        }

        .add-question-btn:active {
            transform: translateY(2px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .questions-container {
            display: grid;
            gap: 1rem;
            transition: all 0.3s ease;
            max-width: 100%;
        }

        .questions-container.single-question {
            grid-template-columns: 1fr;
        }

        .questions-container.multiple-questions {
            grid-template-columns: repeat(2, 1fr);
        }

        @media (max-width: 768px) {
            .questions-container {
                grid-template-columns: 1fr;
            }
        }

        .question-card {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(102, 126, 234, 0.1));
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            height: 100%;
            box-sizing: border-box;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .question-number {
            font-size: 1.3rem;
            color: var(--secondary);
        }

        .question-controls {
            display: flex;
            gap: 0.5rem;
        }

        .control-btn {
            background: rgba(255, 255, 255, 0.15);
            border: none;
            padding: 0.5rem;
            border-radius: 50%;
            color: var(--text-light);
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s ease;
        }

        .control-btn:active {
            transform: translateY(2px);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .control-btn i {
            font-size: 1rem;
            text-shadow: 0 0 4px rgba(255, 255, 255, 0.3);
        }

        .question-type-label {
            font-weight: 500;
            color: var(--text-light);
            margin-bottom: 0.5rem;
            display: block;
        }

        .question-type-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .type-option {
            flex: 1;
            min-width: 100px;
            position: relative;
        }

        .type-option input[type="radio"] {
            display: none;
        }

        .type-card {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem;
            background: rgba(255, 255, 2255, 0.15);
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-light);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s ease, background 0.3s ease, color 0.3s ease;
            text-align: center;
        }

        .type-option input:checked + .type-card {
            background: linear-gradient(135deg, var(--success), var(--success-dark));
            color: #fff;
            transform: translateY(-2px);
        }

        .type-card:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        .type-card:active {
            transform: translateY(2px);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .choices-container {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .choice-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .choice-input {
            flex: 1;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid var(--neutral);
            border-radius: 8px;
            color: var(--text-light);
        }

        .correct-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s ease;
        }

        .correct-indicator:active {
            transform: translateY(2px);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .correct-indicator i {
            font-size: 1rem;
            text-shadow: 0 0 4px rgba(255, 255, 255, 0.3);
        }

        .choice-item input[type="radio"]:checked + .choice-input + .correct-indicator {
            background: var(--success);
            color: #fff;
        }

        .true-false-selector {
            display: flex;
            gap: 0.5rem;
        }

        .tf-option {
            flex: 1;
            position: relative;
        }

        .tf-option input[type="radio"] {
            display: none;
        }

        .tf-card {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-light);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s ease, background 0.3s ease, color 0.3s ease;
            text-align: center;
        }

        .tf-option input:checked + .tf-card.true-card {
            background: linear-gradient(135deg, var(--success), var(--success-dark));
            color: #fff;
            transform: translateY(-2px);
        }

        .tf-option input:checked + .tf-card.false-card {
            background: linear-gradient(135deg, var(--error), var(--error-dark));
            color: #fff;
            transform: translateY(-2px);
        }

        .tf-card:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        .tf-card:active {
            transform: translateY(2px);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .text-answer-note {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }

        .text-answer-note i {
            font-size: 1rem;
            text-shadow: 0 0 4px var(--shadow);
        }

        .actions-controls {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        .action-btn {
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s ease;
        }

        .action-btn:active {
            transform: translateY(2px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .action-btn i {
            font-size: 1rem;
            text-shadow: 0 0 4px rgba(255, 255, 255, 0.3);
        }

        .primary-btn {
            background: linear-gradient(135deg, var(--secondary-light), var(--secondary));
            color: var(--text-light);
        }

        .secondary-btn {
            background: linear-gradient(135deg, var(--neutral), var(--neutral-dark));
            color: var(--text-light);
        }

        .quiz-code-section {
            text-align: center;
        }

        .code-display {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            background: rgba(255, 255, 255, 0.15);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .quiz-code {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--success);
        }

        .copy-code-btn {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s ease;
        }

        .copy-code-btn:active {
            transform: translateY(2px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .copy-code-btn i {
            font-size: 1rem;
            text-shadow: 0 0 4px rgba(255, 255, 255, 0.3);
        }

        .subtitle {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1rem;
        }

        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .form-section, .question-card {
            animation: slideIn 0.5s ease-out;
        }

        @media (max-width: 768px) {
            .navbar {
                transform: translateX(-100%);
                width: 280px;
                height: 100vh;
                flex-direction: column;
                align-items: flex-start;
                padding-top: 2rem;
            }

            .navbar.show {
                transform: translateX(0);
            }

            .navbar-header {
                padding: 2rem 1.5rem;
                width: 100%;
            }

            .menu-section {
                width: 100%;
                justify-content: flex-start;
                padding: 1rem 0;
                padding-left: 1.5rem;
            }

            .menu-list {
                flex-direction: column;
                gap: 0.5rem;
                width: 100%;
            }

            .menu-link {
                padding: 1.2rem 1.5rem;
                margin: 0 1rem;
            }

            .mobile-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .navbar-overlay {
                display: block;
            }

            .main-content {
                padding: 80px 10px 10px;
                margin-left: 0;
            }

            .quiz-grid {
                grid-template-columns: 1fr;
            }

            .question-type-selector {
                flex-direction: column;
            }

            .true-false-selector {
                flex-direction: column;
            }

            .actions-controls {
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }
        }

        .action-image-container {
            text-align: center;
            margin-top: 1rem;
        }

        .action-image {
            max-width: 100%;
            width: 400px;
            height: 400px;
            border-radius: 12px;
            cursor: pointer;
        }

        .action-image:hover {
            transform: scale(1.05);
        }

        .action-image:active {
            transform: scale(0.95);
        }

        .visibility-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            transition: opacity 0.3s ease;
        }

        .visibility-modal.show {
            display: flex;
            opacity: 1;
        }

        .visibility-modal-content {
            background: var(--bg-surface);
            border-radius: 12px;
            padding: 2rem;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.3s ease-out;
        }

        .visibility-modal-header {
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .visibility-modal-header h2 {
            color: var(--text-light);
            font-size: 1.5rem;
            font-weight: 600;
        }

        .visibility-options {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .visibility-option {
            padding: 1rem;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-light);
            text-align: center;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .visibility-option:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
            transform: translateY(-2px);
        }

        .visibility-option:active {
            transform: translateY(2px);
        }

        .visibility-option.public {
            border: 1px solid var(--success);
        }

        .visibility-option.private {
            border: 1px solid var(--neutral);
        }

        .visibility-option.selected {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            border: none;
        }

        .visibility-modal-footer {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }

        .visibility-btn {
            flex: 1;
            padding: 0.75rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .visibility-btn:active {
            transform: translateY(2px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .cancel-btn {
            background: linear-gradient(135deg, var(--neutral), var(--neutral-dark));
            color: var(--text-light);
        }

        .confirm-btn {
            background: linear-gradient(135deg, var(--secondary-light), var(--secondary));
            color: var(--text-light);
        }
    </style>
</head>
<body>
    <div class="navbar-overlay" id="navbarOverlay"></div>

    <nav class="navbar" id="navbar">
        <div class="navbar-header">
            <div class="logo-container">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <span class="logo-text">QuizVerse</span>
            </div>
        </div>
        <div class="menu-section">
            <ul class="menu-list">
                <li class="menu-item">
                    <a href="dashboard.php" class="menu-link">
                        <div class="dashboard-icon">
                            <div class="grid-squares">
                                <div class="square"></div>
                                <div class="square"></div>
                                <div class="square"></div>
                            </div>
                        </div>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item active">
                    <a href="create_quiz.php" class="menu-link">
                        <i class="fas fa-plus-circle"></i>
                        <span>Create Quiz</span>
                    </a>
                </li>
                <li class="menu-item">
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <button class="mobile-toggle" id="mobileToggle" aria-label="Toggle menu">
        <i class="fas fa-bars"></i>
    </button>

    <main class="main-content">
        <div class="quiz-builder">
            <div class="builder-header">
                <h1 class="header-title">Buat Quiz Baru</h1>
                <p class="header-subtitle">Ciptakan pengalaman belajar yang menyenangkan!</p>
            </div>
            <form id="quizForm" class="quiz-form" enctype="multipart/form-data">
                <div class="quiz-grid">
                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-info-circle"></i>
                            <h2>Detail Quiz</h2>
                        </div>
                        <div class="input-group">
                            <label for="title">Judul Quiz</label>
                            <input type="text" id="title" name="title" required maxlength="255">
                            <p class="error-message" id="titleError"></p>
                        </div>
                        <div class="input-group">
                            <label for="description">Deskripsi</label>
                            <textarea id="description" name="description" rows="4"></textarea>
                            <p class="error-message" id="descriptionError"></p>
                        </div>
                    </div>
                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-image"></i>
                            <h2>Cover Quiz</h2>
                        </div>
                        <div class="input-group">
                            <label for="cover">Pilih Gambar Cover</label>
                            <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/gif">
                            <p class="input-note">Format: JPG, PNG, GIF. Maksimal 5MB.</p>
                            <p class="error-message" id="coverError"></p>
                            <div class="cover-preview" id="coverPreview"></div>
                        </div>
                    </div>
                    <div class="questions-section form-section">
                        <div class="section-header">
                            <i class="fas fa-question-circle"></i>
                            <h2>Pertanyaan</h2>
                            <button type="button" class="add-question-btn" id="addQuestionBtn">
                                <i class="fas fa-plus"></i>
                                Tambah Pertanyaan
                            </button>
                        </div>
                        <div class="questions-container single-question" id="questionsContainer">
                            <div class="question-card" data-question-id="1">
                                <div class="question-header">
                                    <span class="question-number">Pertanyaan 1</span>
                                    <div class="question-controls">
                                        <button type="button" class="control-btn delete-question">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <label for="question-1">Teks Pertanyaan</label>
                                    <textarea id="question-1" class="question-text" required></textarea>
                                    <p class="error-message"></p>
                                </div>
                                <div class="question-type-label">Tipe Pertanyaan</div>
                                <div class="question-type-selector">
                                    <div class="type-option">
                                        <input type="radio" name="type-1" id="multiple-choice-1" value="multiple_choice">
                                        <label class="type-card" for="multiple-choice-1">Pilihan Ganda</label>
                                    </div>
                                    <div class="type-option">
                                        <input type="radio" name="type-1" id="true-false-1" value="true_false">
                                        <label class="type-card" for="true-false-1">Benar/Salah</label>
                                    </div>
                                    <div class="type-option">
                                        <input type="radio" name="type-1" id="text-1" value="text">
                                        <label class="type-card" for="text-1">Teks</label>
                                    </div>
                                </div>
                                <div class="question-content" id="content-1">
                                    <div class="choices-container">
                                        <div class="choice-item">
                                            <input type="radio" name="correct-1" id="correct-1-1" value="0">
                                            <input type="text" class="choice-input" id="choice-1-1" placeholder="Opsi 1">
                                            <label class="correct-indicator" for="correct-1-1">
                                                <i class="fas fa-check"></i>
                                            </label>
                                        </div>
                                        <div class="choice-item">
                                            <input type="radio" name="correct-1" id="correct-1-2" value="1">
                                            <input type="text" class="choice-input" id="choice-1-2" placeholder="Opsi 2">
                                            <label class="correct-indicator" for="correct-1-2">
                                                <i class="fas fa-check"></i>
                                            </label>
                                        </div>
                                        <div class="choice-item">
                                            <input type="radio" name="correct-1" id="correct-1-3" value="2">
                                            <input type="text" class="choice-input" id="choice-1-3" placeholder="Opsi 3">
                                            <label class="correct-indicator" for="correct-1-3">
                                                <i class="fas fa-check"></i>
                                            </label>
                                        </div>
                                        <div class="choice-item">
                                            <input type="radio" name="correct-1" id="correct-1-4" value="3">
                                            <input type="text" class="choice-input" id="choice-1-4" placeholder="Opsi 4">
                                            <label class="correct-indicator" for="correct-1-4">
                                                <i class="fas fa-check"></i>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="error-message" id="questionsError"></p>
                    </div>
                </div>
                <div class="action-code-container">
                    <div class="actions-controls">
                        <button type="button" class="action-btn primary-btn" id="saveQuizBtn">
                            <i class="fas fa-save"></i>
                            Simpan Quiz
                        </button>
                        <button type="button" class="action-btn secondary-btn" id="cancelQuizBtn">
                            <i class="fas fa-times"></i>
                            Batal
                        </button>
                    </div>
                    <div class="quiz-code-section" id="quizCodeSection" style="display: none;">
                        <div class="code-display">
                            <span class="quiz-code" id="quizCode"></span>
                            <button type="button" class="copy-code-btn" id="copyCodeBtn">
                                <i class="fas fa-copy"></i>
                                Salin Kode
                            </button>
                        </div>
                        <p class="subtitle">Bagikan kode ini untuk mengakses quiz publik.</p>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <div class="visibility-modal" id="visibilityModal">
        <div class="visibility-modal-content">
            <div class="visibility-modal-header">
                <h2>Pilih Visibilitas Quiz</h2>
            </div>
            <div class="visibility-options">
                <div class="visibility-option public" data-visibility="public">
                    Publik
                </div>
                <div class="visibility-option private selected" data-visibility="private">
                    Pribadi
                </div>
            </div>
            <div class="visibility-modal-footer">
                <button class="visibility-btn cancel-btn" id="cancelVisibilityBtn">Batal</button>
                <button class="visibility-btn confirm-btn" id="confirmVisibilityBtn">Konfirmasi</button>
            </div>
        </div>
    </div>

    <script>
        const navbarToggle = document.getElementById('mobileToggle');
        const navbar = document.getElementById('navbar');
        const navbarOverlay = document.getElementById('navbarOverlay');
        const quizForm = document.getElementById('quizForm');
        const addQuestionBtn = document.getElementById('addQuestionBtn');
        const questionsContainer = document.getElementById('questionsContainer');
        const saveQuizBtn = document.getElementById('saveQuizBtn');
        const cancelQuizBtn = document.getElementById('cancelQuizBtn');
        const coverInput = document.getElementById('cover');
        const coverPreview = document.getElementById('coverPreview');
        const titleInput = document.getElementById('title');
        const descriptionInput = document.getElementById('description');
        const quizCodeSection = document.getElementById('quizCodeSection');
        const quizCode = document.getElementById('quizCode');
        const copyCodeBtn = document.getElementById('copyCodeBtn');
        const visibilityModal = document.getElementById('visibilityModal');
        const cancelVisibilityBtn = document.getElementById('cancelVisibilityBtn');
        const confirmVisibilityBtn = document.getElementById('confirmVisibilityBtn');
        const visibilityOptions = document.querySelectorAll('.visibility-option');

        let questionCount = 1;
        let questionAddCount = 0; // Track number of questions added
        let selectedVisibility = 'private';

        const showNotification = (message, type = 'info') => {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            const icons = {
                success: 'fas fa-check-circle',
                error: 'fas fa-exclamation-circle',
                warning: 'fas fa-exclamation-triangle',
                info: 'fas fa-info-circle'
            };
            notification.innerHTML = `<i class="${icons[type]}"></i><span>${message}</span>`;
            notification.style.cssText = `
                position: fixed; top: 20px; right: 20px; background: ${getNotificationColor(type)};
                color: white; padding: 12px 16px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                z-index: 9999; display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500;
                max-width: 400px; animation: slideIn 0.3s ease-out; backdrop-filter: blur(10px);
            `;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-in forwards';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        };

        const getNotificationColor = (type) => {
            const colors = {
                success: 'linear-gradient(135deg, #10b981, #059669)',
                error: 'linear-gradient(135deg, #ef4444, #dc2626)',
                warning: 'linear-gradient(135deg, #f59e0b, #d97706)',
                info: 'linear-gradient(135deg, #3b82f6, #2563eb)'
            };
            return colors[type] || colors.info;
        };

        const addNotificationStyles = () => {
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
                @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
                @keyframes shake { 0%, 100% { transform: translateX(0); } 10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); } 20%, 40%, 60%, 80% { transform: translateX(5px); } }
            `;
            document.head.appendChild(style);
        };

        const updateQuestionsLayout = () => {
            const cards = questionsContainer.querySelectorAll('.question-card');
            if (cards.length === 1) {
                questionsContainer.classList.remove('multiple-questions');
                questionsContainer.classList.add('single-question');
            } else {
                questionsContainer.classList.remove('single-question');
                questionsContainer.classList.add('multiple-questions');
            }
        };

        navbarToggle.addEventListener('click', () => {
            navbar.classList.toggle('show');
            navbarOverlay.classList.toggle('show');
            document.body.style.overflow = navbar.classList.contains('show') ? 'hidden' : '';
        });

        navbarOverlay.addEventListener('click', () => {
            navbar.classList.remove('show');
            navbarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        });

        coverInput.addEventListener('change', () => {
            const file = coverInput.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    coverPreview.innerHTML = `<img src="${e.target.result}" class="preview-image" alt="Cover Preview">`;
                };
                reader.readAsDataURL(file);
            } else {
                coverPreview.innerHTML = '';
            }
        });

        const addQuestion = () => {
            questionAddCount++; // Increment question addition counter
            questionCount++;
            const questionCard = document.createElement('div');
            questionCard.className = 'question-card';
            questionCard.dataset.questionId = questionCount;
            questionCard.innerHTML = `
                <div class="question-header">
                    <span class="question-number">Pertanyaan ${questionCount}</span>
                    <div class="question-controls">
                        <button type="button" class="control-btn delete-question">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <div class="input-group">
                    <label for="question-${questionCount}">Teks Pertanyaan</label>
                    <textarea id="question-${questionCount}" class="question-text" required></textarea>
                    <p class="error-message"></p>
                </div>
                <div class="question-type-label">Tipe Pertanyaan</div>
                <div class="question-type-selector">
                    <div class="type-option">
                        <input type="radio" name="type-${questionCount}" id="multiple-choice-${questionCount}" value="multiple_choice">
                        <label class="type-card" for="multiple-choice-${questionCount}">Pilihan Ganda</label>
                    </div>
                    <div class="type-option">
                        <input type="radio" name="type-${questionCount}" id="true-false-${questionCount}" value="true_false">
                        <label class="type-card" for="true-false-${questionCount}">Benar/Salah</label>
                    </div>
                    <div class="type-option">
                        <input type="radio" name="type-${questionCount}" id="text-${questionCount}" value="text">
                        <label class="type-card" for="text-${questionCount}">Teks</label>
                    </div>
                </div>
                <div class="question-content" id="content-${questionCount}">
                    <div class="choices-container">
                        ${[1, 2, 3, 4].map(i => `
                            <div class="choice-item">
                                <input type="radio" name="correct-${questionCount}" id="correct-${questionCount}-${i}" value="${i - 1}">
                                <input type="text" class="choice-input" id="choice-${questionCount}-${i}" placeholder="Opsi ${i}">
                                <label class="correct-indicator" for="correct-${questionCount}-${i}">
                                    <i class="fas fa-check"></i>
                                </label>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
            questionsContainer.appendChild(questionCard);
            updateQuestionContent(questionCount);
            attachQuestionEventListeners(questionCard);
            updateQuestionsLayout();
            // Show notification only on first question or every 7th question
            if (questionAddCount === 1 || (questionAddCount - 1) % 7 === 0) {
                showNotification('Pertanyaan ditambahkan', 'success');
            }
        };

        const updateQuestionContent = (qId) => {
            const content = document.getElementById(`content-${qId}`);
            const typeInputs = document.querySelectorAll(`input[name="type-${qId}"]`);
            let type = null;
            typeInputs.forEach(input => {
                if (input.checked) type = input.value;
            });
            if (type === 'multiple_choice') {
                content.innerHTML = `
                    <div class="choices-container">
                        ${[1, 2, 3, 4].map(i => `
                            <div class="choice-item">
                                <input type="radio" name="correct-${qId}" id="correct-${qId}-${i}" value="${i - 1}">
                                <input type="text" class="choice-input" id="choice-${qId}-${i}" placeholder="Opsi ${i}">
                                <label class="correct-indicator" for="correct-${qId}-${i}">
                                    <i class="fas fa-check"></i>
                                </label>
                            </div>
                        `).join('')}
                    </div>
                `;
            } else if (type === 'true_false') {
                content.innerHTML = `
                    <div class="true-false-selector">
                        <div class="tf-option">
                            <input type="radio" name="tf-${qId}" id="true-${qId}" value="true">
                            <label class="tf-card true-card" for="true-${qId}">Benar</label>
                        </div>
                        <div class="tf-option">
                            <input type="radio" name="tf-${qId}" id="false-${qId}" value="false">
                            <label class="tf-card false-card" for="false-${qId}">Salah</label>
                        </div>
                    </div>
                `;
            } else if (type === 'text') {
                content.innerHTML = `
                    <div class="input-group">
                        <label for="answer-${qId}">Jawaban</label>
                        <input type="text" id="answer-${qId}" class="text-answer" required>
                        <p class="text-answer-note">
                            <i class="fas fa-info-circle"></i>
                            Masukkan kata kunci jawaban
                        </p>
                    </div>
                `;
            } else {
                content.innerHTML = `
                    <p class="error-message">Pilih tipe pertanyaan terlebih dahulu.</p>
                `;
            }
            // Reattach toggle behavior to new radio inputs
            attachToggleBehavior(content.querySelectorAll('input[type="radio"]'));
        };

        const attachQuestionEventListeners = (card) => {
            const qId = card.dataset.questionId;
            const deleteBtn = card.querySelector('.delete-question');
            const typeRadios = card.querySelectorAll(`input[name="type-${qId}"]`);

            deleteBtn.addEventListener('click', () => {
                card.remove();
                updateQuestionNumbers();
                updateQuestionsLayout();
                showNotification('Pertanyaan dihapus', 'info');
            });

            typeRadios.forEach(radio => {
                radio.addEventListener('change', () => updateQuestionContent(qId));
            });

            // Attach toggle behavior to radio inputs
            attachToggleBehavior(card.querySelectorAll('input[type="radio"]'));
        };

        const attachToggleBehavior = (radios) => {
            radios.forEach(radio => {
                radio.addEventListener('click', (e) => {
                    if (radio.checked && radio.dataset.wasChecked === 'true') {
                        radio.checked = false;
                        radio.dataset.wasChecked = 'false';
                    } else {
                        radio.dataset.wasChecked = 'true';
                    }
                    // Update wasChecked for all radios in the same group
                    const groupName = radio.name;
                    document.querySelectorAll(`input[name="${groupName}"]`).forEach(r => {
                        if (r !== radio) r.dataset.wasChecked = 'false';
                    });
                });
            });
        };

        const updateQuestionNumbers = () => {
            const cards = questionsContainer.querySelectorAll('.question-card');
            cards.forEach((card, index) => {
                const num = index + 1;
                card.dataset.questionId = num;
                card.querySelector('.question-number').textContent = `Pertanyaan ${num}`;
                const inputs = card.querySelectorAll('input, textarea');
                inputs.forEach(input => {
                    if (input.id) {
                        input.id = input.id.replace(/-\d+$/, `-${num}`);
                    }
                    if (input.name) {
                        input.name = input.name.replace(/-\d+$/, `-${num}`);
                    }
                });
                const labels = card.querySelectorAll('label');
                labels.forEach(label => {
                    if (label.htmlFor) {
                        label.htmlFor = label.htmlFor.replace(/-\d+$/, `-${num}`);
                    }
                });
                const content = card.querySelector('.question-content');
                content.id = `content-${num}`;
            });
            questionCount = cards.length;
            updateQuestionsLayout();
        };

        addQuestionBtn.addEventListener('click', () => {
            addQuestion();
        });

        cancelQuizBtn.addEventListener('click', () => {
            if (confirm('Apakah Anda yakin ingin membatalkan? Semua perubahan akan hilang.')) {
                quizForm.reset();
                questionsContainer.innerHTML = '';
                coverPreview.innerHTML = '';
                quizCodeSection.style.display = 'none';
                questionCount = 0;
                questionAddCount = 0; // Reset question addition counter
                addQuestion();
                showNotification('Pembuatan quiz dibatalkan', 'info');
            }
        });

        saveQuizBtn.addEventListener('click', () => {
            visibilityModal.style.display = 'flex';
            visibilityOptions.forEach(option => {
                option.classList.toggle('selected', option.dataset.visibility === selectedVisibility);
            });
        });

        visibilityOptions.forEach(option => {
            option.addEventListener('click', () => {
                visibilityOptions.forEach(opt => opt.classList.remove('selected'));
                option.classList.add('selected');
                selectedVisibility = option.dataset.visibility;
            });
        });

        cancelVisibilityBtn.addEventListener('click', () => {
            visibilityModal.style.display = 'none';
            showNotification('Pilihan visibilitas dibatalkan', 'info');
        });

        confirmVisibilityBtn.addEventListener('click', async () => {
            visibilityModal.style.display = 'none';
            const questions = [];
            const cards = questionsContainer.querySelectorAll('.question-card');
            for (const card of cards) {
                const qId = card.dataset.questionId;
                const text = card.querySelector('.question-text').value.trim();
                const typeInputs = card.querySelectorAll(`input[name="type-${qId}"]`);
                let type = null;
                typeInputs.forEach(input => {
                    if (input.checked) type = input.value;
                });
                if (!type) {
                    showNotification('Pilih tipe pertanyaan untuk semua pertanyaan', 'error');
                    return;
                }
                let questionData = { text, type };
                if (type === 'multiple_choice') {
                    const choices = Array.from(card.querySelectorAll('.choice-input')).map(input => input.value.trim());
                    const correctInput = card.querySelector(`input[name="correct-${qId}"]:checked`);
                    if (choices.some(choice => !choice)) {
                        showNotification('Semua opsi pilihan ganda harus diisi', 'error');
                        return;
                    }
                    if (!correctInput) {
                        showNotification('Pilih jawaban benar untuk pilihan ganda', 'error');
                        return;
                    }
                    const correct = correctInput.value;
                    questionData.choices = choices;
                    questionData.correct = parseInt(correct);
                } else if (type === 'true_false') {
                    const answerInput = card.querySelector(`input[name="tf-${qId}"]:checked`);
                    if (!answerInput) {
                        showNotification('Pilih jawaban untuk pertanyaan benar/salah', 'error');
                        return;
                    }
                    questionData.answer = answerInput.value === 'true';
                } else if (type === 'text') {
                    const answer = card.querySelector('.text-answer').value.trim();
                    if (!answer) {
                        showNotification('Jawaban teks harus diisi', 'error');
                        return;
                    }
                    questionData.answer = answer;
                }
                if (!text) {
                    showNotification('Teks pertanyaan harus diisi', 'error');
                    return;
                }
                questions.push(questionData);
            }

            if (questions.length === 0) {
                showNotification('Setidaknya satu pertanyaan diperlukan', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'create_quiz');
            formData.append('title', titleInput.value.trim());
            formData.append('description', descriptionInput.value.trim());
            formData.append('is_public', selectedVisibility === 'public' ? 1 : 0);
            formData.append('questions', JSON.stringify(questions));
            if (coverInput.files[0]) {
                formData.append('cover', coverInput.files[0]);
            }

            saveQuizBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
            saveQuizBtn.disabled = true;

            try {
                const response = await fetch('create_quiz.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                saveQuizBtn.innerHTML = '<i class="fas fa-save"></i> Simpan Quiz';
                saveQuizBtn.disabled = false;

                if (data.success) {
                    quizForm.reset();
                    questionsContainer.innerHTML = '';
                    coverPreview.innerHTML = '';
                    questionCount = 0;
                    questionAddCount = 0; // Reset question addition counter
                    addQuestion();
                    if (data.code) {
                        quizCode.textContent = data.code;
                        quizCodeSection.style.display = 'block';
                    } else {
                        quizCodeSection.style.display = 'none';
                    }
                    showNotification(data.message, 'success');
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    }
                } else {
                    showNotification(data.message, 'error');
                }
            } catch (error) {
                saveQuizBtn.innerHTML = '<i class="fas fa-save"></i> Simpan Quiz';
                saveQuizBtn.disabled = false;
                showNotification('Terjadi kesalahan: ' + error.message, 'error');
            }
        });

        copyCodeBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(quizCode.textContent).then(() => {
                showNotification('Kode disalin ke clipboard!', 'success');
            }).catch(() => {
                showNotification('Gagal menyalin kode', 'error');
            });
        });

        const initialize = () => {
            addNotificationStyles();
            attachQuestionEventListeners(document.querySelector('.question-card'));
            updateQuestionsLayout();
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initialize);
        } else {
            initialize();
        }
    </script>
</body>
</html>