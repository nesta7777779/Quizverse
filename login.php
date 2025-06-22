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

require_once 'sambungan_database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    error_log("Database connection error in login.php: " . $e->getMessage(), 3, __DIR__ . '/error.log');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    header('Content-Type: application/json');
    try {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Username dan password diperlukan']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, username, password FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            echo json_encode(['success' => true, 'message' => 'Login berhasil', 'redirect' => 'dashboard.php']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Username atau password salah']);
            error_log("Login attempt failed for username: $username", 3, __DIR__ . '/error.log');
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
        error_log("Login error in login.php: " . $e->getMessage(), 3, __DIR__ . '/error.log');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    header('Content-Type: application/json');
    try {
        $fullname = trim($_POST['fullname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($fullname) || empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Semua kolom diperlukan']);
            exit;
        }
        if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || 
            !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || 
            !preg_match('/[@#$%^&*]/', $password)) {
            echo json_encode(['success' => false, 'message' => 'Password harus minimal 8 karakter dan mengandung huruf besar, huruf kecil, angka, dan simbol (@#$%^&*)']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Username sudah digunakan']);
            exit;
        }
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (fullname, username, password) VALUES (?, ?, ?)');
        $stmt->execute([$fullname, $username, $hashed_password]);

        session_regenerate_id(true);
        echo json_encode(['success' => true, 'message' => 'Registrasi berhasil']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
        error_log("Registration error in login.php: " . $e->getMessage(), 3, __DIR__ . '/error.log');
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuizVerse - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --primary: #8a4fff;
            --primary-dark: #6930c3;
            --secondary: #ff6ec7;
            --text-light: #f8f9fa;
            --bg-dark: #0c0620;
            --shadow-color: rgba(106, 48, 195, 0.3);
            --hover-color: #9e72ff;
            --error-color: #ff4444;
            --success-color: #44ff44;
            --invalid-password-color: #ff4444;
        }

        body, html {
            height: 100%;
            width: 100%;
            overflow: hidden;
            background-color: var(--bg-dark);
        }

        .container {
            position: fixed;
            width: 100%;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }

        .bg-wrapper {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
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

        .clouds {
            position: absolute;
            width: 100%;
            height: 100%;
            z-index: 3;
            pointer-events: none;
        }

        .cloud {
            position: absolute;
            background: radial-gradient(ellipse at center, rgba(138, 79, 255, 0.4) 0%, transparent 70%);
            border-radius: 50%;
            filter: blur(20px);
            transition: transform 0.3s ease;
        }

        .cloud-1 {
            width: 150px;
            height: 80px;
            top: 20%;
            left: -200px;
            animation: floatCloud 50s linear infinite;
        }

        .cloud-2 {
            width: 200px;
            height: 100px;
            top: 50%;
            left: -250px;
            animation: floatCloud 40s linear infinite;
            animation-delay: 10s;
        }

        .cloud-3 {
            width: 180px;
            height: 90px;
            top: 80%;
            left: -220px;
            animation: floatCloud 45s linear infinite;
            animation-delay: 5s;
        }

        @keyframes floatCloud {
            0% { transform: translateX(0); }
            100% { transform: translateX(calc(100vw + 400px)); }
        }

        .shooting-stars {
            position: absolute;
            width: 100%;
            height: 100%;
            z-index: 2;
            overflow: hidden;
        }

        .shooting-star {
            position: absolute;
            width: 150px;
            height: 2px;
            background: linear-gradient(to right, transparent, rgba(255, 255, 255, 0.8), transparent);
            transform: rotate(-45deg);
            opacity: 0;
        }

        @keyframes shootingStar {
            0% {
                opacity: 0;
                transform: translateX(0) translateY(0) rotate(-45deg);
            }
            5% { opacity: 1; }
            100% {
                opacity: 0;
                transform: translateX(-600px) translateY(600px) rotate(-45deg);
            }
        }

        .form-wrapper {
            position: relative;
            width: 100%;
            max-width: 420px;
            height: 600px;
            z-index: 10;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-container {
            position: absolute;
            width: 100%;
            padding: 30px;
            background: rgba(12, 6, 32, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(138, 79, 255, 0.2);
            opacity: 1;
            transform: translateY(0);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }

        .login-container:not(.active) {
            opacity: 0;
            transform: translateY(50px);
            pointer-events: none;
        }

        .register-container {
            opacity: 0;
            transform: translateY(50px);
            pointer-events: none;
        }

        .register-container.active {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo {
            position: relative;
            width: 100px;
            height: 100px;
            margin: 0 auto 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 0 10px rgba(138, 79, 255, 0.5));
            transition: all 0.3s ease;
        }

        .logo:hover .logo-img {
            transform: scale(1.05);
            filter: drop-shadow(0 0 15px rgba(138, 79, 255, 0.7));
        }

        .app-name {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-light);
            margin-bottom: 5px;
            letter-spacing: 1px;
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .tagline {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 20px;
        }

        .login-form {
            width: 100%;
        }

        .input-group {
            position: relative;
            margin-bottom: 15px;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            left: 15px;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.6);
            transition: all 0.3s ease;
            z-index: 1;
        }

        .input-group input {
            width: 100%;
            padding: 15px 45px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .input-group input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.12);
            border-color: var(--primary);
            box-shadow: 0 0 15px rgba(138, 79, 255, 0.3);
        }

        .input-group input:focus ~ .input-icon {
            color: var(--primary);
            transform: translateY(-50%) scale(1.1);
        }

        .input-highlight {
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 2px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            transition: all 0.3s ease;
            transform: translateX(-50%);
        }

        .input-group input:focus ~ .input-highlight {
            width: 100%;
        }

        .input-group input.invalid {
            color: var(--invalid-password-color);
            border-color: var(--invalid-password-color);
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.6);
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 1;
        }

        .toggle-password:hover {
            color: var(--primary);
        }

        .password-strength {
            margin-top: -10px;
            margin-bottom: 20px;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .password-strength.show {
            opacity: 1;
            transform: translateY(0);
        }

        .strength-bar-container {
            width: 100%;
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        #strengthText {
            margin-top: 5px;
            font-size: 12px;
            text-align: center;
        }

        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .remember {
            display: flex;
            align-items: center;
        }

        .remember input[type="checkbox"] {
            appearance: none;
            width: 18px;
            height: 18px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 3px;
            margin-right: 6px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }

        .remember input[type="checkbox"]:checked {
            background: var(--primary);
            border-color: var(--primary);
        }

        .remember input[type="checkbox"]:checked::before {
            content: "✓";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: var(--text-light);
            font-size: 12px;
        }

        .remember label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            cursor: pointer;
        }

        .forgot-password {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .forgot-password:hover {
            color: var(--secondary);
        }

        .login-btn {
            position: relative;
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            border-radius: 8px;
            color: var(--text-light);
            font-size: 16px;
            font-weight: 600;
            letter-spacing: 1px;
            cursor: pointer;
            overflow: hidden;
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }

        .login-btn span {
            position: relative;
            z-index: 1;
        }

        .btn-glow {
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.3) 0%, transparent 70%);
            opacity: 0;
            transition: all 0.5s ease;
            pointer-events: none;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(138, 79, 255, 0.4);
        }

        .login-btn:hover .btn-glow {
            opacity: 1;
            animation: glow 1.5s infinite;
        }

        @keyframes glow {
            0%, 100% { transform: translate(-50%, -50%) scale(0.8); }
            50% { transform: translate(-50%, -50%) scale(1.2); }
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .register-link {
            text-align: center;
        }

        .register-link p {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
        }

        .register-link a {
            color: var(--secondary);
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .register-link a:hover {
            color: var(--hover-color);
            text-decoration: underline;
        }

        .input-error {
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .error-message {
            font-size: 14px;
            text-align: center;
            margin-bottom: 15px;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .error-message.show {
            opacity: 1;
            transform: translateY(0);
        }

        .error-message.success {
            color: var(--success-color);
        }

        .error-message.error {
            color: var(--error-color);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="bg-wrapper">
            <div id="stars"></div>
            <div class="clouds">
                <div class="cloud cloud-1"></div>
                <div class="cloud cloud-2"></div>
                <div class="cloud cloud-3"></div>
            </div>
            <div class="shooting-stars"></div>
        </div>
        
        <div class="form-wrapper">
            <div class="login-container active" id="loginContainer">
                <div class="logo-container">
                    <div class="logo">
                        <img src="./logo.png" alt="QuizVerse Logo" class="logo-img" onerror="this.src='https://via.placeholder.com/100?text=Logo+Not+Found';">
                    </div>
                    <div class="app-name">QuizVerse</div>
                    <div class="tagline">Jelajahi Dunia Pengetahuan</div>
                </div>
                <div class="error-message" id="loginError"></div>
                <form class="login-form" id="loginForm" method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="input-group">
                        <div class="input-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <input type="text" id="username" name="username" placeholder="Username" required>
                        <div class="input-highlight"></div>
                    </div>
                    <div class="input-group">
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <input type="password" id="password" name="password" placeholder="Password" required>
                        <div class="toggle-password">
                            <i class="fas fa-eye"></i>
                        </div>
                        <div class="input-highlight"></div>
                    </div>
                    <div class="remember-forgot">
                        <div class="remember">
                            <input type="checkbox" id="remember" name="remember">
                            <label for="remember">Ingat Saya</label>
                        </div>
                        <a href="#" class="forgot-password">Lupa Password?</a>
                    </div>
                    <button type="submit" class="login-btn">
                        <span>MASUK</span>
                        <div class="btn-glow"></div>
                    </button>
                </form>
                <div class="register-link">
                    <p>Belum punya akun? <a href="#" id="showRegister">Daftar Sekarang</a></p>
                </div>
            </div>

            <div class="login-container register-container" id="registerContainer">
                <div class="logo-container">
                    <div class="logo">
                        <img src="./logo.png" alt="QuizVerse Logo" class="logo-img" onerror="this.src='https://via.placeholder.com/100?text=Logo+Not+Found';">
                    </div>
                    <div class="app-name">QuizVerse</div>
                    <div class="tagline">Bergabung Dengan Kami</div>
                </div>
                <div class="error-message" id="registerError"></div>
                <form class="login-form" id="registerForm" method="POST">
                    <input type="hidden" name="action" value="register">
                    <div class="input-group">
                        <div class="input-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <input type="text" id="fullname" name="fullname" placeholder="Full Name" required>
                        <div class="input-highlight"></div>
                    </div>
                    <div class="input-group">
                        <div class="input-icon">
                            <i class="fas fa-at"></i>
                        </div>
                        <input type="text" id="reg-username" name="username" placeholder="Username" required>
                        <div class="input-highlight"></div>
                    </div>
                    <div class="input-group">
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <input type="password" id="reg-password" name="password" placeholder="Password" required>
                        <div class="toggle-password">
                            <i class="fas fa-eye"></i>
                        </div>
                        <div class="input-highlight"></div>
                    </div>
                    <div id="password-strength" class="password-strength">
                        <div class="strength-bar-container">
                            <div class="strength-bar" id="strengthBar"></div>
                        </div>
                        <div id="strengthText"></div>
                    </div>
                    <button type="submit" class="login-btn register-btn">
                        <span>DAFTAR</span>
                        <div class="btn-glow"></div>
                    </button>
                </form>
                <div class="register-link">
                    <p>Sudah punya akun? <a href="#" id="showLogin">Masuk Sekarang</a></p>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const loginContainer = document.getElementById('loginContainer');
            const registerContainer = document.getElementById('registerContainer');
            const showRegisterLink = document.getElementById('showRegister');
            const showLoginLink = document.getElementById('showLogin');
            const loginForm = document.getElementById('loginForm');
            const registerForm = document.getElementById('registerForm');
            const regPasswordInput = document.getElementById('reg-password');
            const passwordStrengthContainer = document.getElementById('password-strength');
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            const loginError = document.getElementById('loginError');
            const registerError = document.getElementById('registerError');

            createStars();
            createShootingStars();
            initializeParallax();
            initializePasswordToggles();
            initializeFormValidation();
            initializeInputEffects();

            showRegisterLink.addEventListener('click', (e) => {
                e.preventDefault();
                switchToRegister();
            });

            showLoginLink.addEventListener('click', (e) => {
                e.preventDefault();
                switchToLogin();
            });

            function switchToRegister() {
                loginContainer.classList.remove('active');
                loginError.classList.remove('show', 'success', 'error');
                loginError.textContent = '';
                setTimeout(() => {
                    registerContainer.classList.add('active');
                }, 500);
            }

            function switchToLogin() {
                registerContainer.classList.remove('active');
                registerError.classList.remove('show', 'success', 'error');
                registerError.textContent = '';
                setTimeout(() => {
                    loginContainer.classList.add('active');
                }, 500);
            }

            function initializePasswordToggles() {
                document.querySelectorAll('.toggle-password').forEach(toggle => {
                    toggle.addEventListener('click', () => {
                        const input = toggle.previousElementSibling;
                        const icon = toggle.querySelector('i');
                        input.type = input.type === 'password' ? 'text' : 'password';
                        icon.classList.toggle('fa-eye');
                        icon.classList.toggle('fa-eye-slash');
                    });
                });
            }

            function initializeFormValidation() {
                loginForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const username = document.getElementById('username');
                    const password = document.getElementById('password');
                    const btn = loginForm.querySelector('.login-btn');
                    username.classList.remove('input-error');
                    password.classList.remove('input-error');
                    loginError.classList.remove('show', 'success', 'error');
                    loginError.textContent = '';

                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    const formData = new FormData(loginForm);

                    try {
                        const response = await fetch('login.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();
                        btn.innerHTML = '<span>MASUK</span><div class="btn-glow"></div>';

                        if (data.success) {
                            window.location.href = data.redirect || 'dashboard.php';
                        } else {
                            loginError.textContent = data.message;
                            loginError.classList.add('show', 'error');
                            if (data.message.includes('Username')) showError(username);
                            if (data.message.includes('password')) showError(password);
                        }
                    } catch (error) {
                        btn.innerHTML = '<span>MASUK</span><div class="btn-glow"></div>';
                        loginError.textContent = 'Terjadi kesalahan: ' + error.message;
                        loginError.classList.add('show', 'error');
                    }
                });

                registerForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const fullname = document.getElementById('fullname');
                    const username = document.getElementById('reg-username');
                    const password = document.getElementById('reg-password');
                    const btn = registerForm.querySelector('.login-btn');
                    fullname.classList.remove('input-error');
                    username.classList.remove('input-error');
                    password.classList.remove('input-error');
                    registerError.classList.remove('show', 'success', 'error');
                    registerError.textContent = '';

                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    const formData = new FormData(registerForm);

                    try {
                        const response = await fetch('login.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();
                        btn.innerHTML = '<span>DAFTAR</span><div class="btn-glow"></div>';

                        if (data.success) {
                            switchToLogin();
                            loginError.textContent = 'Registrasi berhasil! Silakan masuk.';
                            loginError.classList.add('show', 'success');
                        } else {
                            registerError.textContent = data.message;
                            registerError.classList.add('show', 'error');
                            if (data.message.includes('Nama')) showError(fullname);
                            if (data.message.includes('Username')) showError(username);
                            if (data.message.includes('Password')) showError(password);
                        }
                    } catch (error) {
                        btn.innerHTML = '<span>DAFTAR</span><div class="btn-glow"></div>';
                        registerError.textContent = 'Terjadi kesalahan: ' + error.message;
                        registerError.classList.add('show', 'error');
                    }
                });
            }

            function showError(input) {
                input.classList.add('input-error');
                setTimeout(() => input.classList.remove('input-error'), 500);
            }

            function initializeInputEffects() {
                document.querySelectorAll('.input-group input').forEach(input => {
                    input.addEventListener('focus', () => input.parentElement.classList.add('input-focus'));
                    input.addEventListener('blur', () => input.parentElement.classList.remove('input-focus'));
                });
            }

            regPasswordInput.addEventListener('input', () => {
                const password = regPasswordInput.value;
                if (!password) {
                    passwordStrengthContainer.classList.remove('show');
                    strengthBar.style.width = '0';
                    strengthText.textContent = '';
                    regPasswordInput.classList.remove('invalid');
                    return;
                }

                passwordStrengthContainer.classList.add('show');
                let strength = 0;
                const requirements = [
                    { regex: /.{8,}/, met: password.length >= 8 },
                    { regex: /[a-z]/, met: /[a-z]/.test(password) },
                    { regex: /[A-Z]/, met: /[A-Z]/.test(password) },
                    { regex: /[0-9]/, met: /[0-9]/.test(password) },
                    { regex: /[@#$%^&*]/, met: /[@#$%^&*]/.test(password) }
                ];

                requirements.forEach(req => {
                    if (req.met) strength++;
                });

                const strengthLevels = [
                    { width: '20%', color: '#ff4444', text: 'Sangat Lemah', valid: false },
                    { width: '40%', color: '#ff8844', text: 'Lemah', valid: false },
                    { width: '60%', color: '#ffaa44', text: 'Sedang', valid: false },
                    { width: '80%', color: '#88dd44', text: 'Kuat', valid: false },
                    { width: '100%', color: '#44ff44', text: 'Sangat Kuat', valid: true }
                ];

                const level = strengthLevels[strength - 1] || strengthLevels[0];
                strengthBar.style.width = level.width;
                strengthBar.style.backgroundColor = level.color;
                strengthText.textContent = level.text;

                if (!level.valid) {
                    regPasswordInput.classList.add('invalid');
                    strengthText.style.color = 'var(--invalid-password-color)';
                } else {
                    regPasswordInput.classList.remove('invalid');
                    strengthText.style.color = 'var(--success-color)';
                }
            });

            function createStars() {
                const starsContainer = document.getElementById('stars');
                for (let i = 0; i < 80; i++) {
                    const star = document.createElement('div');
                    star.classList.add('star');
                    const size = Math.random() * 3 + 1;
                    star.style.width = size + 'px';
                    star.style.height = size + 'px';
                    star.style.left = Math.random() * 100 + '%';
                    star.style.top = Math.random() * 100 + '%';
                    star.style.animationDelay = Math.random() * 5 + 's';
                    star.style.opacity = Math.random() * 0.5 + 0.5;
                    star.style.boxShadow = `0 0 ${size * 2}px rgba(255, 255, 255, ${star.style.opacity})`;
                    starsContainer.appendChild(star);
                }
            }

            function createShootingStars() {
                const container = document.querySelector('.shooting-stars');
                setInterval(() => {
                    const shootingStar = document.createElement('div');
                    shootingStar.classList.add('shooting-star');
                    shootingStar.style.left = (Math.random() * 30 + 70) + '%';
                    shootingStar.style.top = (Math.random() * 50) + '%';
                    shootingStar.style.animation = `shootingStar ${2 + Math.random() * 2}s linear`;
                    container.appendChild(shootingStar);
                    setTimeout(() => shootingStar.remove(), 4000);
                }, 3000);
            }

            function initializeParallax() {
                const clouds = document.querySelectorAll('.cloud');
                document.addEventListener('mousemove', (e) => {
                    const mouseX = e.clientX / window.innerWidth;
                    const mouseY = e.clientY / window.innerHeight;
                    clouds.forEach((cloud, index) => {
                        const factorX = (index + 1) * 10;
                        const factorY = (index + 1) * 5;
                        cloud.style.transform = `translate(${mouseX * factorX}px, ${mouseY * factorY}px)`;
                    });
                });
            }

            document.querySelectorAll('.logo').forEach(logo => {
                logo.addEventListener('mouseenter', () => {
                    const logoImg = logo.querySelector('.logo-img');
                    if (logoImg) {
                        logoImg.style.transform = 'scale(1.1) rotate(360deg)';
                        setTimeout(() => logoImg.style.transform = '', 500);
                    }
                });
            });

            document.querySelectorAll('.login-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    if (e.target.type !== 'submit') return;
                    const rect = btn.getBoundingClientRect();
                    createParticles(rect.left + rect.width / 2, rect.top + rect.height / 2);
                });
            });

            function createParticles(x, y) {
                for (let i = 0; i < 20; i++) {
                    const particle = document.createElement('div');
                    const size = Math.random() * 8 + 4;
                    const color = `hsl(${Math.random() * 60 + 260}, 80%, 60%)`;
                    particle.style.cssText = `
                        position: fixed;
                        width: ${size}px;
                        height: ${size}px;
                        background: ${color};
                        border-radius: 50%;
                        pointer-events: none;
                        z-index: 1000;
                        left: ${x}px;
                        top: ${y}px;
                    `;
                    document.body.appendChild(particle);

                    const angle = (Math.PI * 2 * i) / 20;
                    const velocity = 50 + Math.random() * 50;
                    const vx = Math.cos(angle) * velocity;
                    const vy = Math.sin(angle) * velocity;

                    particle.animate([
                        { transform: 'translate(0, 0) scale(1)', opacity: 1 },
                        { transform: `translate(${vx}px, ${vy}px) scale(0)`, opacity: 0 }
                    ], { duration: 1000, easing: 'cubic-bezier(0, .9, .57, 1)' });

                    setTimeout(() => particle.remove(), 1000);
                }
            }
        });
    </script>
</body>
</html>