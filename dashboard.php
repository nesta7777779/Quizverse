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
    error_log('Unauthorized access attempt to dashboard.php: ' . print_r($_SESSION, true));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        finalizeJsonOutput(['success' => false, 'message' => 'User not authenticated']);
    }
    header('Location: login.php');
    ob_end_flush();
    exit;
}

require_once 'sambungan_database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    logDatabaseError("Database connection error in dashboard.php", $e);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        finalizeJsonOutput(['success' => false, 'message' => 'Gagal terhubung ke database']);
    }
    die('Database connection failed. Please try again later.');
}

/**
 * Generates a unique 4-digit join code
 * @return string
 */
function generateJoinCode() {
    return sprintf("%04d", rand(0, 9999));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ensureCleanOutput();
    try {
        switch ($_POST['action']) {
            case 'log_activity':
                $activity_type = trim($_POST['activity_type'] ?? '');
                $activity_details = trim($_POST['activity_details'] ?? '');
                if (empty($activity_type) || empty($activity_details)) {
                    finalizeJsonOutput(['success' => false, 'message' => 'Data aktivitas tidak valid']);
                }
                $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, activity_type, activity_details, created_at) VALUES (?, ?, ?, NOW())');
                $stmt->execute([$_SESSION['user_id'], $activity_type, $activity_details]);
                finalizeJsonOutput(['success' => true]);
                break;

            case 'change_password':
                $current_password = trim($_POST['current_password'] ?? '');
                $new_password = trim($_POST['new_password'] ?? '');
                if (empty($current_password) || empty($new_password)) {
                    finalizeJsonOutput(['success' => false, 'message' => 'Semua kolom diperlukan']);
                }
                if (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) ||
                    !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password) ||
                    !preg_match('/[@#$%^&*]/', $new_password)) {
                    finalizeJsonOutput(['success' => false, 'message' => 'Password baru harus minimal 8 karakter dan mengandung huruf besar, kecil, angka, dan simbol']);
                }
                $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user && password_verify($current_password, $user['password'])) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $stmt->execute([$hashed_password, $_SESSION['user_id']]);
                    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, activity_type, activity_details, created_at) VALUES (?, ?, ?, NOW())');
                    $stmt->execute([$_SESSION['user_id'], 'Password Changed', 'Password berhasil diubah']);
                    finalizeJsonOutput(['success' => true, 'message' => 'Password berhasil diubah']);
                } else {
                    finalizeJsonOutput(['success' => false, 'message' => 'Password saat ini salah']);
                }
                break;

            case 'delete_account':
                $password = trim($_POST['password'] ?? '');
                if (empty($password)) {
                    finalizeJsonOutput(['success' => false, 'message' => 'Password diperlukan']);
                }
                $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user && password_verify($password, $user['password'])) {
                    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                    $stmt->execute([$_SESSION['user_id']]);
                    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, activity_type, activity_details, created_at) VALUES (?, ?, ?, NOW())');
                    $stmt->execute([$_SESSION['user_id'], 'Account Deleted', 'Akun berhasil dihapus']);
                    session_destroy();
                    finalizeJsonOutput(['success' => true, 'message' => 'Akun berhasil dihapus']);
                } else {
                    finalizeJsonOutput(['success' => false, 'message' => 'Password salah']);
                }
                break;

            case 'set_welcome_shown':
                $_SESSION['welcome_shown'] = true;
                finalizeJsonOutput(['success' => true, 'message' => 'Welcome flag set']);
                break;

            case 'check_welcome_shown':
                finalizeJsonOutput(['welcome_shown' => isset($_SESSION['welcome_shown']) && $_SESSION['welcome_shown']]);
                break;

            case 'generate_quiz_code':
                error_log("Generating quiz code for quiz_id: " . ($_POST['quiz_id'] ?? 'unknown'));
                $quizId = filter_var($_POST['quiz_id'] ?? 0, FILTER_VALIDATE_INT);
                if (!$quizId || $quizId <= 0) {
                    finalizeJsonOutput(['success' => false, 'message' => 'ID kuis tidak valid']);
                }
                try {
                    $stmt = $pdo->prepare('SELECT is_public, quiz_code FROM quizzes WHERE id = ? AND user_id = ?');
                    $stmt->execute([$quizId, $_SESSION['user_id']]);
                    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
                    error_log("Quiz fetch result for generate_quiz_code: " . print_r($quiz, true));
                    if (!$quiz) {
                        finalizeJsonOutput(['success' => false, 'message' => 'Kuis tidak ditemukan atau tidak diizinkan']);
                    }
                    if (!$quiz['is_public']) {
                        finalizeJsonOutput(['success' => false, 'message' => 'Kuis harus publik untuk menghasilkan kode']);
                    }
                    $quizCode = $quiz['quiz_code'];
                    if (empty($quizCode) || $quizCode === 'NULL' || $quizCode === null) {
                        do {
                            $quizCode = generateJoinCode();
                            $stmt = $pdo->prepare('SELECT COUNT(*) FROM quizzes WHERE quiz_code = ?');
                            $stmt->execute([$quizCode]);
                            $codeExists = $stmt->fetchColumn();
                        } while ($codeExists > 0);
                        $stmt = $pdo->prepare('UPDATE quizzes SET quiz_code = ? WHERE id = ?');
                        $stmt->execute([$quizCode, $quizId]);
                    }
                    finalizeJsonOutput(['success' => true, 'quiz_code' => $quizCode]);
                } catch (PDOException $e) {
                    logDatabaseError("Error generating quiz code for quiz_id: $quizId", $e);
                    finalizeJsonOutput(['success' => false, 'message' => 'Gagal menghasilkan kode kuis']);
                }
                break;

            case 'get_options':
                $question_id = filter_var($_POST['question_id'] ?? 0, FILTER_VALIDATE_INT);
                if (!$question_id || $question_id <= 0) {
                    finalizeJsonOutput(['success' => false, 'message' => 'ID pertanyaan tidak valid']);
                }
                try {
                    $stmt = $pdo->prepare('SELECT id, option_text AS text FROM answer_options WHERE question_id = ? ORDER BY id');
                    $stmt->execute([$question_id]);
                    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    finalizeJsonOutput(['success' => true, 'options' => $options]);
                } catch (PDOException $e) {
                    logDatabaseError("Error fetching options for question_id: $question_id", $e);
                    finalizeJsonOutput(['success' => false, 'message' => 'Gagal mengambil opsi jawaban']);
                }
                break;

            case 'join_quiz':
                error_log("Joining quiz with code: " . ($_POST['quiz_code'] ?? 'unknown'));
                $joinCode = trim($_POST['quiz_code'] ?? '');
                if (strlen($joinCode) !== 4 || !ctype_digit($joinCode)) {
                    error_log("Invalid quiz code format: $joinCode");
                    finalizeJsonOutput(['success' => false, 'message' => 'Kode kuis harus 4 digit angka']);
                }
                try {
                    $stmt = $pdo->prepare('
                        SELECT id, title, description, thumbnail, is_public, quiz_code
                        FROM quizzes
                        WHERE quiz_code = ? AND is_public = 1
                    ');
                    $stmt->execute([$joinCode]);
                    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
                    error_log("Quiz fetch result for join_quiz: " . print_r($quiz, true));
                    if ($quiz && is_numeric($quiz['id']) && (int)$quiz['id'] > 0) {
                        $quiz_id = (int)$quiz['id'];
                        finalizeJsonOutput([
                            'success' => true,
                            'quiz_id' => $quiz_id,
                            'quiz_title' => htmlspecialchars($quiz['title'], ENT_QUOTES, 'UTF-8'),
                            'quiz_description' => htmlspecialchars($quiz['description'] ?? 'Tidak ada deskripsi tersedia.', ENT_QUOTES, 'UTF-8'),
                            'quiz_thumbnail' => htmlspecialchars($quiz['thumbnail'] ?? 'default.jpg', ENT_QUOTES, 'UTF-8'),
                            'is_public' => (bool)$quiz['is_public'],
                            'quiz_code' => htmlspecialchars($quiz['quiz_code'], ENT_QUOTES, 'UTF-8'),
                            'redirect' => 'quiz.php?quiz_id=' . $quiz_id
                        ]);
                    } else {
                        error_log("No valid quiz found for code: $joinCode");
                        finalizeJsonOutput(['success' => false, 'message' => 'Kode kuis tidak valid atau kuis tidak publik']);
                    }
                } catch (PDOException $e) {
                    logDatabaseError("Error joining quiz with code: $joinCode", $e);
                    finalizeJsonOutput(['success' => false, 'message' => 'Gagal bergabung dengan kuis']);
                }
                break;

            case 'fetch_activity_logs':
                try {
                    $stmt = $pdo->prepare('SELECT activity_type, activity_details, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
                    $stmt->execute([$_SESSION['user_id']]);
                    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $response = [
                        'success' => true,
                        'logs' => []
                    ];
                    foreach ($logs as $log) {
                        $response['logs'][] = [
                            'activity_type' => htmlspecialchars($log['activity_type'], ENT_QUOTES, 'UTF-8'),
                            'activity_details' => htmlspecialchars($log['activity_details'], ENT_QUOTES, 'UTF-8'),
                            'created_at' => date('d M Y H:i', strtotime($log['created_at']))
                        ];
                    }
                    finalizeJsonOutput($response);
                } catch (PDOException $e) {
                    logDatabaseError("Error fetching activity logs for user_id: {$_SESSION['user_id']}", $e);
                    finalizeJsonOutput(['success' => false, 'message' => 'Gagal mengambil log aktivitas']);
                }
                break;

            default:
                finalizeJsonOutput(['success' => false, 'message' => 'Aksi tidak valid']);
        }
    } catch (Exception $e) {
        logDatabaseError("Dashboard POST error", $e);
        finalizeJsonOutput(['success' => false, 'message' => 'Terjadi kesalahan: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    }
}

$stmt = $pdo->prepare('SELECT fullname, username FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    ob_end_flush();
    exit;
}

$fullname = htmlspecialchars($user['fullname'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars($user['username'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuizVerse - Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>
    <div class="navbar-overlay" id="navbarOverlay"></div>
    
    <nav class="sidebar" id="sidebar" aria-label="Main navigation">
        <div class="sidebar-header">
            <div class="logo-container">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <span class="logo-text">QuizVerse</span>
            </div>
        </div>

        <div class="menu-section">
            <h3 class="menu-title">Main Menu</h3>
            <ul class="menu-list">
                <li class="menu-item active">
                    <a href="#" class="menu-link" data-section="dashboard">
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
                <li class="menu-item">
                    <a href="#" class="menu-link" data-section="activity">
                        <i class="fas fa-chart-line"></i>
                        <span>Activity</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="create_quiz.php" class="menu-link">
                        <i class="fas fa-plus-circle"></i>
                        <span>Create Quiz</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="#" class="menu-link" data-section="created-quiz">
                        <i class="fas fa-file-circle-plus"></i>
                        <span>Created Quiz</span>
                    </a>
                </li>
            </ul>
        </div>

        <div class="account-section">
            <h3 class="menu-title">Account</h3>
            <ul class="menu-list">
                <li class="menu-item">
                    <a href="#" class="menu-link" id="settingsLink">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="logout.php" class="menu-link logout">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <button class="mobile-toggle" id="mobileToggle" aria-label="Toggle menu">
        <i class="fas fa-bars"></i>
    </button>

    <main class="main-content">
        <section id="dashboard" class="content-section active">
            <div class="content-container">
                <div class="search-panel">
                    <div class="search-container">
                        <input type="text" id="searchInput" placeholder="Masukkan kode game (4 angka)" class="search-input">
                        <button class="search-btn" id="searchBtn">Gabung</button>
                    </div>
                </div>
                
                <div class="welcome-panel" id="welcomePanel">
                    <div class="welcome-header">
                        <h1>Selamat datang di QuizVerse 🛸</h1>
                        <p class="welcome-subtitle">Jelajahi pengetahuan Alam semesta</p>
                    </div>
                    <div class="welcome-content">
                        <div class="user-profile">
                            <div class="user-name">
                                <h3 id="userName"><?php echo $username; ?></h3>
                            </div>
                            <div class="profile-actions">
                                <button class="edit-profile-btn" id="editProfileBtn">
                                    <i class="fas fa-edit"></i>
                                    <span>Edit Character</span>
                                </button>
                            </div>
                        </div>
                        <div class="character-display" id="characterDisplay">
                            <img src="men.png" alt="User Character" id="characterImage">
                        </div>
                    </div>
                </div>

                <div class="quiz-panels">
                    <div class="quiz-panel" data-subject="Matematika" data-image="matematika.jpg">
                        <div class="quiz-thumbnail">
                            <img src="matematika.jpg" alt="Matematika Thumbnail">
                        </div>
                        <div class="quiz-info">
                            <h3>Matematika</h3>
                            <p>3 Soal</p>
                        </div>
                    </div>
                    <div class="quiz-panel" data-subject="Bahasa Indonesia" data-image="bahasa indonesia.jpg">
                        <div class="quiz-thumbnail">
                            <img src="bahasa indonesia.jpg" alt="Bahasa Indonesia Thumbnail">
                        </div>
                        <div class="quiz-info">
                            <h3>Bahasa Indonesia</h3>
                            <p>3 Soal</p>
                        </div>
                    </div>
                    <div class="quiz-panel" data-subject="Bahasa Inggris" data-image="bahasa inggris.jpg">
                        <div class="quiz-thumbnail">
                            <img src="bahasa inggris.jpg" alt="Bahasa Inggris Thumbnail">
                        </div>
                        <div class="quiz-info">
                            <h3>Bahasa Inggris</h3>
                            <p>3 Soal</p>
                        </div>
                    </div>
                    <div class="quiz-panel" data-subject="Ilmu Pengetahuan Umum" data-image="ilmu pengetahuan umum.jpg">
                        <div class="quiz-thumbnail">
                            <img src="ilmu pengetahuan umum.jpg" alt="Ilmu Pengetahuan Umum Thumbnail">
                        </div>
                        <div class="quiz-info">
                            <h3>Ilmu Pengetahuan Umum</h3>
                            <p>3 Soal</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="activity" class="content-section">
            <div class="content-container">
                <div class="activity-panel" id="activityPanel">
                    <div class="activity-panel-header">
                        <h2>Riwayat Aktivitas</h2>
                        <button class="clear-activity-btn" id="clearActivityBtn">
                            <i class="fas fa-trash-alt"></i>
                            Clear Activity
                        </button>
                    </div>
                    <div class="activity-panel-content">
                        <div class="notification-panel">
                            <p class="panel-description">Lihat pemberitahuan terbaru tentang aktivitas akun Anda, seperti perubahan profil atau pembuatan kuis.</p>
                            <h3 class="panel-title">Notifikasi</h3>
                            <div class="notification-content">
                                <div class="notification-grid">
                                    <div class="activity-notification show">
                                        <div class="notification-icon">ℹ</div>
                                        <div class="notification-content">
                                            <div class="notification-title">Profile Updated</div>
                                            <div class="notification-details">Mengubah karakter ke female</div>
                                            <div class="notification-date">19 Jun 2025 20:02</div>
                                        </div>
                                    </div>
                                    <div class="activity-notification show">
                                        <div class="notification-icon">➕</div>
                                        <div class="notification-content">
                                            <div class="notification-title">lyakah</div>
                                            <div class="notification-details">Dimainkan oleh seif (Skor: 1/2)</div>
                                            <div class="notification-date">18 Jun 2025 19:05</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="quiz-history-panel">
                            <p class="panel-description">Tinjau riwayat kuis yang telah Anda ikuti, lengkap dengan skor dan tanggal penyelesaian.</p>
                            <h3 class="panel-title">Riwayat Kuis</h3>
                            <div class="quiz-history-content">
                                <div class="quiz-grid">
                                    <div class="activity-quiz show">
                                        <div class="quiz-icon">🧩</div>
                                        <div class="quiz-content">
                                            <div class="quiz-title">aa</div>
                                            <div class="quiz-score">Skor: 0/1</div>
                                            <div class="quiz-date">20 Jun 2025 00:56</div>
                                        </div>
                                    </div>
                                    <div class="activity-quiz show">
                                        <div class="quiz-icon">🧩</div>
                                        <div class="quiz-content">
                                            <div class="quiz-title">lyakah</div>
                                            <div class="quiz-score">Skor: 1/2</div>
                                            <div class="quiz-date">19 Jun 2025 21:24</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="created-quiz" class="content-section">
            <div class="created-quiz-container">
                <div class="created-quiz-panels" id="createdQuizPanels"></div>
            </div>
        </section>
    </main>

    <div class="modal-overlay" id="modalOverlay">
        <div class="modal">
            <div class="modal-header">
                <h3>Edit Profil</h3>
                <button class="modal-close" id="modalClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="character-selection">
                    <label>Pilih Karakter</label>
                    <div class="character-options">
                        <div class="character-option active" data-gender="male">
                            <div class="character-preview">
                                <img src="men.png" alt="Male Character">
                            </div>
                            <span>Laki-laki</span>
                        </div>
                        <div class="character-option" data-gender="female">
                            <div class="character-preview">
                                <img src="girl.png" alt="Female Character">
                            </div>
                            <span>Perempuan</span>
                        </div>
                        <div class="character-option" data-gender="robot">
                            <div class="character-preview">
                                <img src="robot.png" alt="Robot Character">
                            </div>
                            <span>Robot</span>
                        </div>
                        <div class="character-option" data-gender="alien">
                            <div class="character-preview">
                                <img src="alien.png" alt="Alien Character">
                            </div>
                            <span>Alien</span>
                        </div>
                        <div class="character-option" data-gender="Vampire">
                            <div class="character-preview">
                                <img src="vampire.png" alt="Astronaut Character">
                            </div>
                            <span>Vampire</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" id="btnCancel">Batal</button>
                <button class="btn-save" id="btnSave">Simpan</button>
            </div>
        </div>
    </div>

    <div class="settings-overlay" id="settingsOverlay">
        <div class="settings-panel">
            <div class="settings-header">
                <h3>Pengaturan Akun</h3>
                <button class="settings-close" id="settingsClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="settings-body">
                <div class="account-info">
                    <div class="info-group">
                        <label>Nama Lengkap</label>
                        <input type="text" value="<?php echo $fullname; ?>" readonly>
                    </div>
                    <div class="info-group">
                        <label>Username</label>
                        <input type="text" value="<?php echo $username; ?>" readonly>
                    </div>
                </div>
                <div class="action-buttons">
                    <button class="btn-action" id="changePasswordBtn">
                        <i class="fas fa-lock"></i>
                        <span>Ubah Password</span>
                    </button>
                    <button class="btn-action btn-danger" id="deleteAccountBtn">
                        <i class="fas fa-trash-alt"></i>
                        <span>Hapus Akun</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="password-overlay" id="passwordOverlay">
        <div class="password-panel">
            <div class="password-header">
                <h3>Ubah Password</h3>
                <button class="password-close" id="passwordClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="password-body">
                <form id="changePasswordForm">
                    <div class="input-group">
                        <label>Password Saat Ini</label>
                        <input type="password" id="currentPassword" name="current_password" required>
                    </div>
                    <div class="input-group">
                        <label>Password Baru</label>
                        <input type="password" id="newPassword" name="new_password" required>
                    </div>
                    <div class="password-strength" id="passwordStrength">
                        <div class="strength-bar-container">
                            <div class="strength-bar" id="strengthBar"></div>
                        </div>
                        <div id="strengthText"></div>
                    </div>
                    <div class="error-message" id="passwordError"></div>
                    <button type="submit" class="btn-submit">
                        <span>Simpan</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="delete-overlay" id="deleteOverlay">
        <div class="delete-panel">
            <div class="delete-header">
                <h3>Hapus Akun</h3>
                <button class="delete-close" id="deleteClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="delete-body">
                <form id="deleteAccountForm">
                    <div class="input-group">
                        <label>Masukkan Password</label>
                        <input type="password" id="deletePassword" name="password" required>
                    </div>
                    <div class="error-message" id="deleteError"></div>
                    <p class="warning-text">Apakah Anda yakin ingin menghapus akun? Tindakan ini tidak dapat dibatalkan.</p>
                    <div class="delete-actions">
                        <button type="button" class="btn-cancel" id="cancelDelete">Batal</button>
                        <button type="submit" class="btn-confirm">Hapus</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="quiz-modal-overlay" id="quizModalOverlay">
        <div class="quiz-modal">
            <div class="quiz-modal-header">
                <button class="quiz-exit-btn" id="quizExitBtn">
                    <i class="fas fa-times"></i>
                </button>
                <h3 class="quiz-modal-title" id="quizModalTitle"></h3>
                <div class="quiz-modal-divider"></div>
                <div class="quiz-modal-content">
                    <div class="quiz-modal-image">
                        <img id="quizModalImage" src="" alt="Quiz Thumbnail">
                    </div>
                    <div class="quiz-modal-text">
                        <p id="quizModalDescription"></p>
                        <p id="quizCodeContainer" style="display: none;"><strong>Kode: </strong><span id="quizCodeDisplay"></span></p>
                    </div>
                </div>
            </div>
            <div class="quiz-modal-footer">
                <button class="play-btn" id="playQuizBtn">
                    <span>Mainkan</span>
                    <i class="fas fa-play"></i>
                </button>
            </div>
        </div>
    </div>

    <script src="dashboard.js"></script>
    <?php
    if (isset($_GET['error'])) {
        $error_message = htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8');
        echo "<script>document.addEventListener('DOMContentLoaded', () => { window.QuizVerse.showNotification('$error_message', 'error'); });</script>";
    }
    ?>
</body>
</html>
<?php
ob_end_flush();
?>