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
    error_log('Unauthorized access attempt to quiz_result.php: ' . print_r($_SESSION, true));
    header('Location: login.php');
    ob_end_flush();
    exit;
}

require_once 'sambungan_database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    logDatabaseError("Database connection error in quiz_result.php", $e);
    die('Database connection failed. Please try again later.');
}

$quiz_id = filter_input(INPUT_GET, 'quiz_id', FILTER_VALIDATE_INT);
$score = filter_input(INPUT_GET, 'score', FILTER_VALIDATE_INT);
$total_questions = filter_input(INPUT_GET, 'total', FILTER_VALIDATE_INT);
$answers = json_decode(urldecode($_GET['answers'] ?? '[]'), true);

if ($quiz_id === false || $quiz_id <= 0 || $score === false || $total_questions <= 0 || !is_array($answers)) {
    error_log("Invalid parameters in quiz_result.php: quiz_id=$quiz_id, score=$score, total_questions=$total_questions, answers=" . print_r($answers, true));
    header('Location: dashboard.php?error=' . urlencode('Parameter hasil kuis tidak valid'));
    ob_end_flush();
    exit;
}

try {
    $stmt = $pdo->prepare('
        SELECT q.id, q.title
        FROM quizzes q
        WHERE q.id = ? AND (q.user_id = ? OR q.is_public = 1)
    ');
    $stmt->execute([$quiz_id, $_SESSION['user_id']]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logDatabaseError("Error fetching quiz details for quiz_id: $quiz_id", $e);
    header('Location: dashboard.php?error=' . urlencode('Gagal mengambil detail kuis'));
    ob_end_flush();
    exit;
}

if (!$quiz) {
    error_log("Quiz not found for ID: $quiz_id, User ID: {$_SESSION['user_id']}");
    header('Location: dashboard.php?error=' . urlencode('Kuis tidak ditemukan atau Anda tidak memiliki akses'));
    ob_end_flush();
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuizVerse - Hasil Quiz</title>
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
            --soft-pink: #f9a8d4;
            --soft-pink-dark: #ec4899;
            --soft-green: #34d399;
            --soft-green-dark: #059669;
            --soft-red: #f87171;
            --soft-red-dark: #dc2626;
            --panel-dark: rgba(8, 4, 18, 0.85);
            --panel-bg: rgba(255, 255, 255, 0.05);
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
            z-index: 100;
            background: radial-gradient(circle at 50% 50%, rgba(138, 79, 255, 0.1) 0%, transparent 70%);
            animation: glowPulse 3s infinite alternate;
            pointer-events: none;
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
            z-index: 0;
            pointer-events: none;
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

        .header-controls {
            position: fixed;
            top: 2vh;
            left: 2vw;
            right: 2vw;
            display: flex;
            justify-content: space-between;
            z-index: 2000;
        }

        .back-btn, .fullscreen-result-btn {
            background: linear-gradient(135deg, var(--light-blue), var(--light-blue-dark));
            border: none;
            padding: 1.5vh 2vw;
            border-radius: 0.8rem;
            color: var(--text-light);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5vw;
            min-width: 150px;
            text-align: center;
            z-index: 2001;
            pointer-events: auto;
        }

        .fullscreen-result-btn {
            background: linear-gradient(135deg, var(--sage), var(--sage-dark));
        }

        .back-btn:active, .fullscreen-result-btn:active {
            transform: translateY(0) scale(0.98);
            box-shadow: none;
        }

        .result-panel {
            position: absolute;
            top: 12vh;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 90vw;
            max-width: 900px;
            min-height: 70vh;
            max-height: 80vh;
            background: rgba(8, 4, 18, 0.7);
            backdrop-filter: blur(12px);
            color: var(--text-light);
            text-align: center;
            padding: 3vh 3vw;
            border-radius: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 1vh 3vh rgba(45, 21, 79, 0.3);
            animation: smoothAppear 0.5s ease-out;
            z-index: 150;
            overflow: hidden;
        }

        @keyframes smoothAppear {
            from { opacity: 0; transform: translateX(-50%) scale(0.95); }
            to { opacity: 1; transform: translateX(-50%) scale(1); }
        }

        .result-title {
            font-size: 2.5rem;
            background: linear-gradient(135deg, var(--soft-pink), var(--soft-pink-dark));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 2vh;
        }

        .result-player {
            font-size: 1.8rem;
            color: var(--text-light);
            margin-bottom: 1.5vh;
        }

        .result-score {
            font-size: 2rem;
            color: var(--soft-green);
            margin-bottom: 1vh;
        }

        .result-incorrect {
            font-size: 1.5rem;
            color: var(--soft-red);
            margin-bottom: 1.5vh;
        }

        .result-message {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 2vh;
        }

        .play-again-btn {
            background: linear-gradient(135deg, var(--soft-pink), var(--soft-pink-dark));
            border: none;
            padding: 2vh 4vw;
            border-radius: 1rem;
            color: var(--text-light);
            font-size: 1.2rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5vw;
            min-width: 200px;
            text-align: center;
            z-index: 1001;
            pointer-events: auto;
            margin-bottom: 2vh;
        }

        .play-again-btn:hover {
            transform: translateY(-0.5vh) scale(1.03);
            box-shadow: 0 0.8vh 2vh var(--shadow-color);
            opacity: 0.9;
        }

        .play-again-btn:active {
            transform: translateY(0) scale(0.98);
            box-shadow: none;
        }

        .result-details {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 1.5vh;
        }

        .result-question {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 0.8rem;
            padding: 1.5vh 2vw;
            text-align: left;
            transition: transform 0.2s ease;
        }

        .result-question:hover {
            transform: translateY(-0.3vh);
            box-shadow: 0 0.5vh 1vh rgba(0, 0, 0, 0.1);
        }

        .result-question-text {
            font-size: 1.2rem;
            color: var(--text-light);
            margin-bottom: 0.5vh;
        }

        .result-answer {
            font-size: 1rem;
            margin-bottom: 0.3vh;
        }

        .result-answer.correct {
            color: var(--soft-green);
        }

        .result-answer.incorrect {
            color: var(--soft-red);
        }

        .notification {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            width: 90vw;
            max-width: 400px;
            background: linear-gradient(135deg, var(--soft-green), var(--soft-green-dark));
            color: var(--text-light);
            padding: 1.5vh 2vw;
            border-radius: 0.8rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1002;
            display: flex;
            align-items: center;
            gap: 1vw;
            font-size: 1rem;
            font-weight: 500;
            animation: slideUp 0.5s ease-out forwards;
        }

        .notification-error {
            background: linear-gradient(135deg, var(--soft-red), var(--soft-red-dark));
        }

        @keyframes slideUp {
            from { transform: translateX(-50%) translateY(100%); opacity: 0; }
            to { transform: translateX(-50%) translateY(0); opacity: 1; }
        }

        @keyframes slideDown {
            from { transform: translateX(-50%) translateY(0); opacity: 1; }
            to { transform: translateX(-50%) translateY(100%); opacity: 0; }
        }

        @media (max-width: 768px) {
            .result-panel {
                padding: 2vh 2vw;
                width: 95vw;
                min-height: 75vh;
                max-height: 85vh;
            }

            .result-title { font-size: 2rem; }
            .result-player { font-size: 1.5rem; }
            .result-score { font-size: 1.5rem; }
            .result-incorrect { font-size: 1.2rem; }
            .result-message { font-size: 1rem; }
            .result-question-text { font-size: 1rem; }
            .result-answer { font-size: 0.9rem; }
            .play-again-btn {
                font-size: 1rem;
                padding: 1.5vh 3vw;
                width: 80vw;
                justify-content: center;
            }
            .back-btn, .fullscreen-result-btn {
                font-size: 0.9rem;
                padding: 1vh 2vw;
                min-width: 120px;
            }
            .notification { font-size: 0.9rem; padding: 1vh 1.5vw; }
        }
    </style>
</head>
<body>
    <div class="bg-wrapper">
        <div id="stars"></div>
    </div>
    <div class="header-controls">
        <button class="back-btn" id="backBtn">
            <i class="fas fa-home button-icon"></i>
            Kembali ke Dashboard
        </button>
        <button class="fullscreen-result-btn" id="fullscreenResultBtn">
            <i class="fas fa-expand button-icon"></i>
            Layar Penuh
        </button>
    </div>
    <div class="result-panel" id="resultScreen">
        <h1 class="result-title">Game Selesai!</h1>
        <p class="result-player"><?php echo htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="result-score" id="resultScore"></p>
        <p class="result-incorrect" id="resultIncorrect"></p>
        <p class="result-message" id="resultMessage"></p>
        <button class="play-again-btn" id="playAgainBtn">
            <i class="fas fa-redo button-icon"></i>
            Main Lagi
        </button>
        <div class="result-details" id="resultDetails"></div>
    </div>

    <script>
        console.log('Quiz Result Page Loaded at:', new Date().toISOString());
        console.log('Quiz Parameters:', { score: <?php echo json_encode($score); ?>, totalQuestions: <?php echo json_encode($total_questions); ?>, quizId: <?php echo json_encode($quiz_id); ?>, answers: <?php echo json_encode($answers); ?> });

        const score = <?php echo json_encode($score, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
        const totalQuestions = <?php echo json_encode($total_questions, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
        const answers = <?php echo json_encode($answers, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
        const quizId = <?php echo json_encode($quiz_id, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
        const resultScreen = document.getElementById('resultScreen');
        const resultScore = document.getElementById('resultScore');
        const resultIncorrect = document.getElementById('resultIncorrect');
        const resultMessage = document.getElementById('resultMessage');
        const resultDetails = document.getElementById('resultDetails');
        const backBtn = document.getElementById('backBtn');
        const playAgainBtn = document.getElementById('playAgainBtn');
        const fullscreenResultBtn = document.getElementById('fullscreenResultBtn');
        const starsContainer = document.getElementById('stars');

        // Generate stars
        console.log('Generating background stars...');
        try {
            if (!starsContainer) {
                throw new Error('Stars container not found');
            }
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
            console.log('Stars generated successfully');
        } catch (err) {
            console.error('Star generation error:', err);
            showNotification('Gagal membuat efek bintang di latar belakang', 'error');
        }

        // Show notification
        function showNotification(message, type = 'success') {
            console.log(`Showing notification: ${message} (${type})`);
            try {
                const notification = document.createElement('div');
                notification.className = `notification notification-${type === 'error' ? 'error' : 'success'}`;
                notification.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i><span>${message}</span>`;
                document.body.appendChild(notification);
                setTimeout(() => {
                    notification.style.animation = 'slideDown 0.5s ease-in forwards';
                    setTimeout(() => notification.remove(), 500);
                }, 3000);
            } catch (err) {
                console.error('Notification error:', err);
                alert(`[${type.toUpperCase()}] ${message}`);
            }
        }

        // Toggle fullscreen
        function toggleFullscreen(button) {
            console.log('Fullscreen button clicked');
            try {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen().then(() => {
                        button.innerHTML = `<i class="fas fa-compress button-icon"></i> Keluar Layar Penuh`;
                        console.log('Fullscreen enabled');
                    }).catch(err => {
                        console.error('Fullscreen enable error:', err);
                    });
                } else {
                    document.exitFullscreen().then(() => {
                        button.innerHTML = `<i class="fas fa-expand button-icon"></i> Layar Penuh`;
                        console.log('Fullscreen exited');
                    }).catch(err => {
                        showNotification('Gagal keluar mode layar penuh: ' + err.message, 'error');
                    });
                }
            } catch (err) {
                console.error('Fullscreen toggle error:', err);
                showNotification('Error saat mengubah mode layar penuh', 'error');
            }
        }

        // Redirect function
        function redirectTo(url, button, loadingText, successMessage) {
            console.log(`Redirecting to: ${url}`);
            try {
                button.disabled = true;
                button.innerHTML = `<i class="fas fa-spinner fa-spin button-icon"></i> ${loadingText}`;
                showNotification(successMessage, 'success');
                window.location.href = url;
            } catch (err) {
                console.error('Redirect error:', err);
                button.disabled = false;
                button.innerHTML = button.dataset.originalText || button.innerHTML;
                showNotification('Gagal mengalihkan: ' + err.message, 'error');
                alert('Gagal mengalihkan ke ' + url + '. Silakan coba lagi.');
            }
        }

        // Button event listeners
        console.log('Binding button event listeners...');
        try {
            backBtn.addEventListener('click', () => {
                console.log('Back to Dashboard button clicked');
                redirectTo('dashboard.php', backBtn, 'Mengalihkan...', 'Mengalihkan ke dashboard...');
            });

            playAgainBtn.addEventListener('click', () => {
                console.log('Play Again button clicked');
                if (!Number.isInteger(quizId) || quizId <= 0) {
                    console.error('Invalid quizId:', quizId);
                    showNotification('ID kuis tidak valid', 'error');
                    return;
                }
                redirectTo(`quiz.php?quiz_id=${encodeURIComponent(quizId)}`, playAgainBtn, 'Memulai ulang...', 'Memulai ulang quiz...');
            });

            fullscreenResultBtn.addEventListener('click', () => {
                console.log('Fullscreen button clicked');
                toggleFullscreen(fullscreenResultBtn);
            });

            // Fullscreen change handler
            document.addEventListener('fullscreenchange', () => {
                console.log('Fullscreen state changed:', document.fullscreenElement ? 'Entered' : 'Exited');
                try {
                    document.body.style.width = '100vw';
                    document.body.style.height = '100vh';
                    resultScreen.style.width = '90vw';
                    resultScreen.style.maxWidth = '900px';
                } catch (err) {
                    console.error('Fullscreen change error:', err);
                }
            });

            console.log('Button event listeners bound successfully');
        } catch (err) {
            console.error('Button binding error:', err);
            showNotification('Gagal mengatur tombol', 'error');
        }

        // Setup result screen
        function setupResultScreen() {
            console.log('Setting up result screen...');
            try {
                if (!Number.isInteger(score) || !Number.isInteger(totalQuestions) || !Number.isInteger(quizId)) {
                    throw new Error('Invalid quiz parameters: score=' + score + ', totalQuestions=' + totalQuestions + ', quizId=' + quizId);
                }

                resultScore.textContent = `Skor Anda: ${score}/${totalQuestions}`;
                const incorrectCount = totalQuestions - score;
                resultIncorrect.textContent = `Jawaban Salah: ${incorrectCount}`;
                const percentage = (score / totalQuestions) * 100;
                resultMessage.textContent = percentage >= 80 ? 'Luar biasa! Anda menguasai quiz ini!' :
                                           percentage >= 50 ? 'Bagus! Anda hampir menguasainya!' :
                                           'Jangan menyerah! Coba lagi untuk skor lebih baik!';

                resultDetails.innerHTML = '';
                if (Array.isArray(answers)) {
                    answers.forEach((ans, idx) => {
                        const questionDiv = document.createElement('div');
                        questionDiv.className = 'result-question';
                        questionDiv.innerHTML = `
                            <p class="result-question-text">Pertanyaan ${idx + 1}: ${ans.question}</p>
                            <p class="result-answer ${ans.is_correct ? 'correct' : 'incorrect'}">
                                Jawaban Anda: ${ans.selected}
                            </p>
                            ${!ans.is_correct ? `<p class="result-answer correct">Jawaban Benar: ${ans.correct_answer}</p>` : ''}
                        `;
                        resultDetails.appendChild(questionDiv);
                    });
                } else {
                    console.warn('Answers is not an array:', answers);
                    resultDetails.innerHTML = '<p>Data jawaban tidak valid.</p>';
                }

                // Store original button texts
                backBtn.dataset.originalText = backBtn.innerHTML;
                playAgainBtn.dataset.originalText = playAgainBtn.innerHTML;
                fullscreenResultBtn.dataset.originalText = fullscreenResultBtn.innerHTML;

                console.log('Result screen setup completed');
            } catch (error) {
                console.error('Setup error:', error);
                showNotification('Gagal memuat hasil quiz: ' + error.message, 'error');
                setTimeout(() => {
                    window.location.href = 'dashboard.php';
                }, 2000);
            }
        }

        // Initialize
        function initialize() {
            console.log('Initializing quiz result page...');
            try {
                // Verify button elements exist
                if (!backBtn || !playAgainBtn || !fullscreenResultBtn) {
                    throw new Error('Button elements not found');
                }
                console.log('Button elements found:', { backBtn, playAgainBtn, fullscreenResultBtn });

                setupResultScreen();
                showNotification('Hasil quiz berhasil dimuat', 'success');
            } catch (error) {
                console.error('Initialization error:', error);
                showNotification('Terjadi kesalahan saat memuat hasil: ' + error.message, 'error');
                alert('Terjadi kesalahan saat memuat hasil. Mengalihkan ke dashboard...');
                window.location.href = 'dashboard.php';
            }
        }

        // Run initialization
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                console.log('DOM fully loaded');
                initialize();
            });
        } else {
            console.log('DOM already loaded');
            initialize();
        }
    </script>
</body>
</html>
<?php
    ob_end_flush();
?>