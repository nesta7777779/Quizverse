const currentUsername = document.getElementById('userName').textContent;

const mobileToggle = document.getElementById('mobileToggle');
const sidebar = document.getElementById('sidebar');
const navbarOverlay = document.getElementById('navbarOverlay');
const quizModalOverlay = document.getElementById('quizModalOverlay');
const playQuizBtn = document.getElementById('playQuizBtn');
const quizExitBtn = document.getElementById('quizExitBtn');
const settingsLink = document.getElementById('settingsLink');
const settingsOverlay = document.getElementById('settingsOverlay');
const settingsClose = document.getElementById('settingsClose');
const passwordOverlay = document.getElementById('passwordOverlay');
const passwordClose = document.getElementById('passwordClose');
const deleteOverlay = document.getElementById('deleteOverlay');
const deleteClose = document.getElementById('deleteClose');
const changePasswordBtn = document.getElementById('changePasswordBtn');
const deleteAccountBtn = document.getElementById('deleteAccountBtn');
const changePasswordForm = document.getElementById('changePasswordForm');
const deleteAccountForm = document.getElementById('deleteAccountForm');
const cancelDelete = document.getElementById('cancelDelete');
const newPasswordInput = document.getElementById('newPassword');
const passwordStrengthContainer = document.getElementById('passwordStrength');
const strengthBar = document.getElementById('strengthBar');
const strengthText = document.getElementById('strengthText');
const passwordError = document.getElementById('passwordError');
const deleteError = document.getElementById('deleteError');
const createdQuizPanels = document.getElementById('createdQuizPanels');
const searchInput = document.getElementById('searchInput');
const searchBtn = document.getElementById('searchBtn');
const editProfileBtn = document.getElementById('editProfileBtn');
const modalOverlay = document.getElementById('modalOverlay');
const modalClose = document.getElementById('modalClose');
const btnCancel = document.getElementById('btnCancel');
const btnSave = document.getElementById('btnSave');
const characterOptions = document.querySelectorAll('.character-option');
const userName = document.getElementById('userName');
const characterImage = document.getElementById('characterImage');
const quizCodeDisplay = document.getElementById('quizCodeDisplay');
const quizCodeContainer = document.getElementById('quizCodeContainer');
const activityPanel = document.getElementById('activityPanel');
const clearActivityBtn = document.getElementById('clearActivityBtn');

let currentUserData = {
    name: currentUsername || 'Pengguna',
    gender: 'male',
    joinDate: new Date().toLocaleDateString('id-ID'),
    gamesPlayed: 0,
    totalScore: 0
};

/**
 * Switches the active content section in the dashboard
 * @param {string} sectionId - ID of the section to activate
 */
const switchSection = (sectionId) => {
    document.querySelectorAll('.content-section').forEach(section => {
        section.classList.remove('active');
    });
    const targetSection = document.getElementById(sectionId);
    if (targetSection) {
        targetSection.classList.add('active');
        if (sectionId === 'created-quiz') {
            loadCreatedQuizzes();
        } else if (sectionId === 'activity') {
            loadActivityData();
        }
    }
};

/**
 * Closes the sidebar on mobile view
 */
const closeSidebar = () => {
    sidebar.classList.remove('show');
    navbarOverlay.classList.remove('show');
    document.body.style.overflow = '';
};

/**
 * Shows an overlay and disables scrolling
 * @param {HTMLElement} overlay - Overlay element to show
 */
const showOverlay = (overlay) => {
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
};

/**
 * Adds shake animation to an input element
 * @param {HTMLElement} input - Input element to shake
 */
const showError = (input) => {
    input.classList.add('input-error');
    setTimeout(() => input.classList.remove('input-error'), 500);
};

/**
 * Loads quizzes created by the user
 */
const loadCreatedQuizzes = async () => {
    try {
        const response = await fetch('created_quiz.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'fetch_created_quizzes'
            })
        });
        const data = await response.json();
        if (data.success) {
            createdQuizPanels.innerHTML = '';
            if (data.quizzes.length === 0) {
                createdQuizPanels.innerHTML = '<p style="grid-column: 1 / -1; text-align: center; color: #666;">Belum ada quiz yang dibuat.</p>';
            } else {
                data.quizzes.forEach(quiz => {
                    const panel = document.createElement('div');
                    panel.className = 'created-quiz-panel';
                    panel.dataset.quizId = quiz.id;
                    panel.dataset.subject = quiz.title;
                    panel.dataset.image = quiz.thumbnail || 'default.jpg';
                    panel.innerHTML = `
                        <div class="quiz-thumbnail">
                            <img src="${quiz.thumbnail || 'default.jpg'}" alt="${quiz.title} Thumbnail">
                        </div>
                        <div class="quiz-info">
                            <h3>${quiz.title}</h3>
                            <p>${quiz.question_count} Soal</p>
                        </div>
                    `;
                    createdQuizPanels.appendChild(panel);
                    panel.addEventListener('click', () => showQuizModal(quiz));
                });
            }
            showNotification('Quiz yang telah dibuat ✨', 'success');
        } else {
            createdQuizPanels.innerHTML = '<p style="grid-column: 1 / -1; text-align: center; color: #666;">Gagal memuat quiz: ' + data.message + '</p>';
            showNotification(data.message, 'error');
        }
    } catch (error) {
        createdQuizPanels.innerHTML = '<p style="grid-column: 1 / -1; text-align: center; color: #666;">Terjadi kesalahan saat memuat quiz.</p>';
        showNotification('Terjadi kesalahan: ' + error.message, 'error');
    }
};

/**
 * Shows the quiz modal with details
 * @param {Object} quiz - Quiz data
 */
const showQuizModal = async (quiz) => {
    document.getElementById('quizModalTitle').textContent = quiz.title;
    document.getElementById('quizModalDescription').textContent = quiz.description || 'Tidak ada deskripsi tersedia.';
    document.getElementById('quizModalImage').src = quiz.thumbnail || 'default.jpg';
    playQuizBtn.dataset.quizId = quiz.id;

    quizCodeContainer.style.display = quiz.is_public ? 'block' : 'none';
    if (quiz.is_public) {
        try {
            const response = await fetch('dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'generate_quiz_code',
                    quiz_id: quiz.id
                })
            });
            const data = await response.json();
            if (data.success) {
                quizCodeDisplay.textContent = data.quiz_code;
            } else {
                quizCodeDisplay.textContent = 'Gagal memuat kode';
                showNotification(data.message, 'error');
            }
        } catch (error) {
            quizCodeDisplay.textContent = 'Gagal memuat kode';
            showNotification('Terjadi kesalahan: ' + error.message, 'error');
        }
    } else {
        quizCodeDisplay.textContent = '';
    }

    quizExitBtn.style.visibility = 'visible';
    quizExitBtn.style.opacity = '1';
    quizExitBtn.style.position = 'absolute';
    quizExitBtn.style.top = '0.5rem';
    quizExitBtn.style.right = '0.5rem';
    showOverlay(quizModalOverlay);
    showNotification('Memilih kuis ' + quiz.title + '!', 'info');
};

/**
 * Loads activity data for the activity panel
 */
const loadActivityData = async () => {
    try {
        const response = await fetch('activity.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'fetch_activity'
            })
        });

        if (!response.ok) {
            throw new Error(`HTTP error: ${response.status} ${response.statusText}`);
        }

        let data;
        try {
            data = await response.json();
        } catch (e) {
            throw new Error('Invalid JSON response: ' + e.message);
        }

        console.log('Activity data response:', data);

        if (data && typeof data === 'object' && data.success) {
            const myQuizzes = Array.isArray(data.my_quizzes) ? data.my_quizzes : [];
            const notifications = Array.isArray(data.notifications) ? data.notifications : [];

            // Create header with Clear Activity button
            const header = document.createElement('div');
            header.className = 'activity-panel-header';
            header.innerHTML = `
                <h2>Riwayat Aktivitas</h2>
                <button class="clear-activity-btn" id="clearActivityBtn">
                    <i class="fas fa-trash-alt"></i>
                    Clear Activity
                </button>
            `;

            // Render Notifications (Left Panel)
            const leftPanel = document.createElement('div');
            leftPanel.className = 'activity-panel-left';
            if (notifications.length === 0) {
                leftPanel.innerHTML = '<p class="activity-placeholder">Belum ada notifikasi.</p>';
            } else {
                notifications.forEach((notif, index) => {
                    const notifDiv = document.createElement('div');
                    notifDiv.className = 'activity-notification';
                    if (notif.type === 'quiz_played') {
                        const username = notif.username || 'Unknown User';
                        const score = notif.score !== null ? notif.score : 'N/A';
                        const totalQuestions = notif.total_questions !== null ? notif.total_questions : 'N/A';
                        notifDiv.innerHTML = `
                            <div class="notification-icon">
                                <i class="fas fa-gamepad"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title">${notif.quiz_title || 'Untitled Quiz'}</div>
                                <div class="notification-details">Dimainkan oleh: ${username} (Skor: ${score}/${totalQuestions})</div>
                                <div class="notification-date">${notif.created_at}</div>
                            </div>
                        `;
                    } else {
                        notifDiv.innerHTML = `
                            <div class="notification-icon">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title">${notif.type.replace('_', ' ').toUpperCase()}</div>
                                <div class="notification-details">${notif.details || 'No details available'}</div>
                                <div class="notification-date">${notif.created_at}</div>
                            </div>
                        `;
                    }
                    leftPanel.appendChild(notifDiv);
                    setTimeout(() => {
                        notifDiv.classList.add('show');
                    }, index * 100);
                });
            }

            // Render Quiz History (Right Panel)
            const rightPanel = document.createElement('div');
            rightPanel.className = 'activity-panel-right';
            if (myQuizzes.length === 0) {
                rightPanel.innerHTML = '<p class="activity-placeholder">Belum ada quiz yang dimainkan.</p>';
            } else {
                const quizGrid = document.createElement('div');
                quizGrid.className = 'quiz-grid';
                myQuizzes.forEach((item, index) => {
                    const quiz = document.createElement('div');
                    quiz.className = 'activity-quiz';
                    quiz.innerHTML = `
                        <div class="quiz-icon">
                            <i class="fas fa-puzzle-piece"></i>
                        </div>
                        <div class="quiz-content">
                            <div class="quiz-title">${item.quiz_title || 'Untitled Quiz'}</div>
                            <div class="quiz-score">Skor: ${item.score}/${item.total_questions}</div>
                            <div class="quiz-date">${item.completed_at}</div>
                        </div>
                    `;
                    quizGrid.appendChild(quiz);
                    setTimeout(() => {
                        quiz.classList.add('show');
                    }, index * 100);
                });
                rightPanel.appendChild(quizGrid);
            }

            // Assemble activity panel
            activityPanel.innerHTML = '';
            activityPanel.appendChild(header);
            const contentWrapper = document.createElement('div');
            contentWrapper.className = 'activity-panel-content';
            contentWrapper.appendChild(leftPanel);
            contentWrapper.appendChild(rightPanel);
            activityPanel.appendChild(contentWrapper);
            activityPanel.classList.add('show');

            // Attach event listener for the Clear Activity button
            const newClearActivityBtn = document.getElementById('clearActivityBtn');
            newClearActivityBtn.addEventListener('click', (e) => {
                e.preventDefault();
                clearActivity();
                // Add click animation
                newClearActivityBtn.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    newClearActivityBtn.style.transform = 'scale(1)';
                }, 100);
            });
        } else {
            const errorMessage = data && data.message ? data.message : 'Respon server tidak valid';
            activityPanel.innerHTML = '<p class="activity-placeholder">Gagal memuat aktivitas: ' + errorMessage + '</p>';
            showNotification(errorMessage, 'error');
        }
    } catch (error) {
        console.error('Load activity error:', error);
        activityPanel.innerHTML = '<p class="activity-placeholder">Terjadi kesalahan saat memuat aktivitas: ' + error.message + '</p>';
        showNotification('Terjadi kesalahan: ' + error.message, 'error');
    }
};

/**
 * Clears all activity logs
 */
const clearActivity = async () => {
    if (!confirm('Apakah Anda yakin ingin menghapus semua log aktivitas? Tindakan ini tidak dapat dibatalkan.')) {
        return;
    }
    try {
        const response = await fetch('activity.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'clear_activity'
            })
        });
        const data = await response.json();
        if (data.success) {
            activityPanel.innerHTML = `
                <div class="activity-panel-header">
                    <h2>Riwayat Aktivitas</h2>
                    <button class="clear-activity-btn" id="clearActivityBtn">
                        <i class="fas fa-trash-alt"></i>
                        Clear Activity
                    </button>
                </div>
                <div class="activity-panel-content">
                    <div class="activity-panel-left">
                        <p class="activity-placeholder">Belum ada notifikasi.</p>
                    </div>
                    <div class="activity-panel-right">
                        <p class="activity-placeholder">Belum ada quiz yang dimainkan.</p>
                    </div>
                </div>
            `;
            activityPanel.classList.add('show');
            // Re-attach event listener after clearing
            const newClearActivityBtn = document.getElementById('clearActivityBtn');
            newClearActivityBtn.addEventListener('click', (e) => {
                e.preventDefault();
                clearActivity();
                newClearActivityBtn.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    newClearActivityBtn.style.transform = 'scale(1)';
                }, 100);
            });
            showNotification('Semua aktivitas berhasil dihapus', 'success');
        } else {
            showNotification(data.message || 'Gagal menghapus aktivitas', 'error');
        }
    } catch (error) {
        showNotification('Terjadi kesalahan: ' + error.message, 'error');
    }
};

// Sidebar toggle for mobile
mobileToggle.addEventListener('click', () => {
    sidebar.classList.toggle('show');
    navbarOverlay.classList.toggle('show');
    document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
});

navbarOverlay.addEventListener('click', closeSidebar);

// Navigation menu links
const menuLinks = document.querySelectorAll('.menu-link');
menuLinks.forEach(link => {
    link.addEventListener('click', (e) => {
        if (link.classList.contains('logout') || link.href.includes('create_quiz.php')) {
            return;
        }
        e.preventDefault();
        menuLinks.forEach(l => l.parentElement.classList.remove('active'));
        link.parentElement.classList.add('active');
        const section = link.dataset.section;
        if (section) {
            switchSection(section);
        } else if (link.id === 'settingsLink') {
            showOverlay(settingsOverlay);
            showNotification('Memuat pengaturan akun', 'info');
        }
        if (window.innerWidth <= 768) {
            closeSidebar();
        }
        const linkText = link.querySelector('span').textContent;
        showNotification('Navigasi ke ' + linkText, 'info');
    });
});

// Settings overlay handlers
settingsClose.addEventListener('click', () => {
    settingsOverlay.classList.remove('show');
    document.body.style.overflow = '';
    showNotification('Pengaturan ditutup', 'info');
});

settingsOverlay.addEventListener('click', (e) => {
    if (e.target === settingsOverlay) {
        settingsOverlay.classList.remove('show');
        document.body.style.overflow = '';
        showNotification('Pengaturan ditutup', 'info');
    }
});

// Password overlay handlers
passwordClose.addEventListener('click', () => {
    passwordOverlay.classList.remove('show');
    passwordError.classList.remove('show', 'success', 'error');
    changePasswordForm.reset();
    passwordStrengthContainer.classList.remove('show');
    showNotification('Ubah password dibatalkan', 'info');
});

passwordOverlay.addEventListener('click', (e) => {
    if (e.target === passwordOverlay) {
        passwordOverlay.classList.remove('show');
        passwordError.classList.remove('show', 'success', 'error');
        changePasswordForm.reset();
        passwordStrengthContainer.classList.remove('show');
        showNotification('Ubah password dibatalkan', 'info');
    }
});

// Delete account overlay handlers
deleteClose.addEventListener('click', () => {
    deleteOverlay.classList.remove('show');
    deleteError.classList.remove('show', 'success', 'error');
    deleteAccountForm.reset();
    showNotification('Hapus akun dibatalkan', 'info');
});

cancelDelete.addEventListener('click', () => {
    deleteOverlay.classList.remove('show');
    deleteError.classList.remove('show', 'success', 'error');
    deleteAccountForm.reset();
    showNotification('Hapus akun dibatalkan', 'info');
});

deleteOverlay.addEventListener('click', (e) => {
    if (e.target === deleteOverlay) {
        deleteOverlay.classList.remove('show');
        deleteError.classList.remove('show', 'success', 'error');
        deleteAccountForm.reset();
        showNotification('Hapus akun dibatalkan', 'info');
    }
});

// Open password and delete account modals
changePasswordBtn.addEventListener('click', () => {
    showOverlay(passwordOverlay);
    showNotification('Membuka ubah password', 'info');
});

deleteAccountBtn.addEventListener('click', () => {
    showOverlay(deleteOverlay);
    showNotification('Membuka hapus akun', 'info');
});

// Password strength indicator
newPasswordInput.addEventListener('input', () => {
    const password = newPasswordInput.value.trim();
    if (!password) {
        passwordStrengthContainer.classList.remove('show');
        strengthBar.style.width = '0';
        strengthText.textContent = '';
        newPasswordInput.classList.remove('invalid');
        return;
    }

    passwordStrengthContainer.classList.add('show');
    let strength = 0;
    const requirements = [
        { regex: /.{8,}/, met: password.length >= 8 },
        { regex: /[a-z]/, met: /[a-z]/.test(password) },
        { regex: /[A-Z]/, met: /[A-Z]/.test(password) },
        { regex: /[0-9]/, met: /[0-9]/.test(password) },
        { regex: /[@#%^*]/, met: /[@#%^*]/.test(password) }
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
        newPasswordInput.classList.add('invalid');
        strengthText.style.color = '#ff4444';
    } else {
        newPasswordInput.classList.remove('invalid');
        strengthText.style.color = '#44ff44';
    }
});

// Change password form submission
changePasswordForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = changePasswordForm.querySelector('.btn-submit');
    const currentPassword = document.getElementById('currentPassword');
    const newPassword = document.getElementById('newPassword');
    passwordError.classList.remove('show', 'success', 'error');
    currentPassword.classList.remove('input-error');
    newPassword.classList.remove('input-error');

    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;

    const formData = new FormData(changePasswordForm);
    formData.append('action', 'change_password');

    try {
        const response = await fetch('dashboard.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        btn.innerHTML = '<span>Simpan</span>';
        btn.disabled = false;

        if (data.success) {
            passwordError.textContent = data.message;
            passwordError.classList.add('show', 'success');
            changePasswordForm.reset();
            passwordStrengthContainer.classList.remove('show');
            showNotification('Password berhasil diubah!', 'success');
            setTimeout(() => {
                passwordOverlay.classList.remove('show');
                settingsOverlay.classList.remove('show');
            }, 1500);
        } else {
            passwordError.textContent = data.message;
            passwordError.classList.add('show', 'error');
            if (data.message.includes('saat ini')) showError(currentPassword);
            if (data.message.includes('baru')) showError(newPassword);
            showNotification(data.message, 'error');
        }
    } catch (error) {
        btn.innerHTML = '<span>Simpan</span>';
        btn.disabled = false;
        passwordError.textContent = 'Terjadi kesalahan: ' + error.message;
        passwordError.classList.add('show', 'error');
        showNotification('Terjadi kesalahan saat mengubah password', 'error');
    }
});

// Delete account form submission
deleteAccountForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = deleteAccountForm.querySelector('.btn-confirm');
    const passwordInput = document.getElementById('deletePassword');
    deleteError.classList.remove('show', 'success', 'error');
    passwordInput.classList.remove('input-error');

    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;

    const formData = new FormData(deleteAccountForm);
    formData.append('action', 'delete_account');

    try {
        const response = await fetch('dashboard.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        btn.innerHTML = 'Hapus';
        btn.disabled = false;

        if (data.success) {
            deleteError.textContent = data.message;
            deleteError.classList.add('show', 'success');
            showNotification('Akun berhasil dihapus!', 'success');
            setTimeout(() => window.location.href = 'login.php', 1500);
        } else {
            deleteError.textContent = data.message;
            deleteError.classList.add('show', 'error');
            if (data.message.includes('Password')) showError(passwordInput);
            showNotification(data.message, 'error');
        }
    } catch (error) {
        btn.innerHTML = 'Hapus';
        btn.disabled = false;
        deleteError.textContent = 'Terjadi kesalahan: ' + error.message;
        deleteError.classList.add('show', 'error');
        showNotification('Terjadi kesalahan saat menghapus akun', 'error');
    }
});

// Search input validation
searchInput.addEventListener('input', (e) => {
    e.target.value = e.target.value.replace(/[^0-9]/g, '');
    
    if (e.target.value.length > 0) {
        e.target.style.borderColor = '#10b981';
        e.target.style.boxShadow = '0 0 0 4px rgba(16, 185, 129, 0.1)';
    } else {
        e.target.style.borderColor = '';
        e.target.style.boxShadow = '';
    }
});

// Search button for joining quiz with code
searchBtn.addEventListener('click', async () => {
    const gameCode = searchInput.value.trim();
    
    if (gameCode && gameCode.length === 4 && /^\d{4}$/.test(gameCode)) {
        searchBtn.style.transform = 'scale(0.95)';
        searchInput.style.borderColor = '#10b981';
        
        setTimeout(() => {
            searchBtn.style.transform = '';
        }, 150);
        
        const originalText = searchBtn.innerHTML;
        searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Bergabung...';
        searchBtn.disabled = true;
        
        const formData = new FormData();
        formData.append('action', 'join_quiz');
        formData.append('quiz_code', gameCode);

        try {
            const response = await fetch('dashboard.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            searchBtn.innerHTML = originalText;
            searchBtn.disabled = false;
            
            if (data.success && data.quiz_id && !isNaN(parseInt(data.quiz_id)) && parseInt(data.quiz_id) > 0) {
                document.getElementById('quizModalTitle').textContent = data.quiz_title || 'Untitled Quiz';
                document.getElementById('quizModalDescription').textContent = data.quiz_description || 'Tidak ada deskripsi tersedia.';
                document.getElementById('quizModalImage').src = data.quiz_thumbnail || 'default.jpg';
                playQuizBtn.dataset.quizId = data.quiz_id;
                
                quizCodeContainer.style.display = data.is_public ? 'block' : 'none';
                quizCodeDisplay.textContent = data.is_public ? (data.quiz_code || 'N/A') : '';
                
                quizExitBtn.style.visibility = 'visible';
                quizExitBtn.style.opacity = '1';
                quizExitBtn.style.position = 'absolute';
                quizExitBtn.style.top = '0.5rem';
                quizExitBtn.style.right = '0.5rem';
                
                showOverlay(quizModalOverlay);
                showNotification(`Berhasil menemukan kuis ${data.quiz_title}!`, 'success');
            } else {
                searchBtn.innerHTML = '<i class="fas fa-times"></i> Gagal';
                searchBtn.style.background = 'linear-gradient(135deg, #ef4444, #dc2626)';
                
                showNotification(data.message || 'Kode kuis tidak valid', 'error');
                
                setTimeout(() => {
                    searchBtn.innerHTML = originalText;
                    searchBtn.style.background = '';
                }, 2000);
            }
        } catch (error) {
            searchBtn.innerHTML = '<i class="fas fa-times"></i> Gagal';
            searchBtn.style.background = 'linear-gradient(135deg, #ef4444, #dc2626)';
            
            showNotification('Terjadi kesalahan: ' + error.message, 'error');
            
            setTimeout(() => {
                searchBtn.innerHTML = originalText;
                searchBtn.style.background = '';
                searchBtn.disabled = false;
            }, 2000);
        }
    } else {
        searchInput.style.borderColor = '#ef4444';
        searchInput.style.boxShadow = '0 0 0 4px rgba(239, 68, 68, 0.1)';
        searchInput.placeholder = 'Masukkan 4 digit!';
        
        searchInput.style.animation = 'shake 0.5s ease-in-out';
        
        setTimeout(() => {
            searchInput.style.borderColor = '';
            searchInput.style.boxShadow = '';
            searchInput.style.animation = '';
            searchInput.placeholder = 'Masukkan kode game (4 angka)';
        }, 3000);
        
        showNotification('Kode game harus 4 digit angka!', 'warning');
    }
});

// Enter key triggers search
searchInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        searchBtn.click();
    }
});

// Edit profile modal
editProfileBtn.addEventListener('click', () => {
    characterOptions.forEach(option => {
        option.classList.remove('active');
        if (option.dataset.gender === currentUserData.gender) {
            option.classList.add('active');
        }
    });
    
    showOverlay(modalOverlay);
});

// Close modals
const closeModal = () => {
    modalOverlay.classList.remove('show');
    quizModalOverlay.classList.remove('show');
    settingsOverlay.classList.remove('show');
    passwordOverlay.classList.remove('show');
    deleteOverlay.classList.remove('show');
    passwordError.classList.remove('show', 'success', 'error');
    deleteError.classList.remove('show', 'success', 'error');
    changePasswordForm.reset();
    deleteAccountForm.reset();
    passwordStrengthContainer.classList.remove('show');
    document.body.style.overflow = '';
};

modalClose.addEventListener('click', closeModal);
btnCancel.addEventListener('click', closeModal);
quizExitBtn.addEventListener('click', closeModal);

modalOverlay.addEventListener('click', (e) => {
    if (e.target === modalOverlay) {
        closeModal();
    }
});

quizModalOverlay.addEventListener('click', (e) => {
    if (e.target === quizModalOverlay) {
        closeModal();
    }
});

// Escape key closes modals
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && (modalOverlay.classList.contains('show') || quizModalOverlay.classList.contains('show') || settingsOverlay.classList.contains('show') || passwordOverlay.classList.contains('show') || deleteOverlay.classList.contains('show'))) {
        closeModal();
    }
    
    if (e.key === 'Tab' && modalOverlay.classList.contains('show')) {
        const focusableElements = modalOverlay.querySelectorAll(
            'button, [tabindex]:not([tabindex="-1"])'
        );
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];
        
        if (e.shiftKey && document.activeElement === firstElement) {
            e.preventDefault();
            lastElement.focus();
        } else if (!e.shiftKey && document.activeElement === lastElement) {
            e.preventDefault();
            firstElement.focus();
        }
    }
});

// Character selection
characterOptions.forEach(option => {
    option.addEventListener('click', () => {
        characterOptions.forEach(opt => opt.classList.remove('active'));
        option.classList.add('active');
        
        const preview = option.querySelector('.character-preview img');
        preview.style.transform = 'scale(1.1)';
        setTimeout(() => {
            preview.style.transform = '';
        }, 200);
    });
    
    option.addEventListener('mouseenter', () => {
        if (!option.classList.contains('active')) {
            option.style.transform = 'translateY(-3px)';
        }
    });
    
    option.addEventListener('mouseleave', () => {
        option.style.transform = '';
    });
});

// Save profile changes
btnSave.addEventListener('click', () => {
    const originalText = btnSave.innerHTML;
    btnSave.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
    btnSave.disabled = true;
    
    characterOptions.forEach(option => {
        option.style.pointerEvents = 'none';
    });
    
    let progress = 0;
    const progressInterval = setInterval(() => {
        progress += 20;
        btnSave.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan... ' + progress + '%';
        
        if (progress >= 100) {
            clearInterval(progressInterval);
            
            const selectedCharacter = document.querySelector('.character-option.active');
            const selectedGender = selectedCharacter.dataset.gender;
            
            currentUserData = {
                ...currentUserData,
                gender: selectedGender
            };
            
            saveUserData();
            fetch('dashboard.php', {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'log_activity',
                    activity_type: 'Profile Updated',
                    activity_details: 'Mengubah karakter ke ' + selectedGender
                })
            });
            
            updateUserDisplay();
            
            btnSave.innerHTML = '<i class="fas fa-check"></i> Tersimpan!';
            btnSave.style.background = 'linear-gradient(135deg, #10b981, #059669)';
            
            showNotification('Profil berhasil diperbarui!', 'success');
            
            setTimeout(() => {
                closeModal();
                btnSave.innerHTML = originalText;
                btnSave.style.background = '';
                btnSave.disabled = false;
                
                characterOptions.forEach(option => {
                    option.style.pointerEvents = '';
                });
            }, 1500);
        }
    }, 300);
});

// Load user data
const loadUserData = () => {
    currentUserData.name = currentUsername || 'Pengguna';
    updateUserDisplay();
};

// Save user data
const saveUserData = () => {
    console.log('User data saved:', currentUserData);
};

// Update user display
const updateUserDisplay = () => {
    const currentName = userName.textContent;
    if (currentName !== currentUserData.name) {
        userName.style.opacity = '0';
        userName.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
            userName.textContent = currentUserData.name;
            userName.style.opacity = '1';
            userName.style.transform = 'translateY(0)';
        }, 200);
    }
    
    const imageSrc = {
        'male': 'men.png',
        'female': 'girl.png',
        'robot': 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTcdlrKt18Rv8uDr71onD2EX0vQ5Ac0e0DMoQ&s',
        'alien': 'https://img.pikbest.com/origin/09/25/32/52rpIkbEsTuQ5.png!sw800',
        'astronaut': 'https://encrypted-tbn0.gstatic.com/images?q=tbngcShMuYcqzhqJk3uLLh3aSkZhLx8pFcW-lSkUOhD6Fac3adV_M5nK1T7ktai&s=10'
    }[currentUserData.gender] || 'men.png';
    
    if (characterImage.src !== imageSrc) {
        characterImage.style.opacity = '0';
        characterImage.style.transform = 'scale(0.8)';
        
        setTimeout(() => {
            characterImage.src = imageSrc;
            characterImage.alt = ({
                'male': 'Male',
                'female': 'Female',
                'robot': 'Robot',
                'alien': 'Alien',
                'astronaut': 'Astronaut'
            }[currentUserData.gender] || 'Male') + ' Character';
            characterImage.style.opacity = '1';
            characterImage.style.transform = 'scale(1)';
        }, 200);
    }
};

/**
 * Shows a notification with a message and type
 * @param {string} message - Message to display
 * @param {string} type - Type of notification (success, error, warning, info)
 */
const showNotification = (message, type = 'info') => {
    const existingNotifications = document.querySelectorAll('.notification');
    existingNotifications.forEach(notif => notif.remove());
    
    const notification = document.createElement('div');
    notification.className = 'notification notification-' + type;
    
    const icons = {
        success: 'fas fa-check-circle',
        error: 'fas fa-exclamation-circle',
        warning: 'fas fa-exclamation-triangle',
        info: 'fas fa-info-circle'
    };
    
    notification.innerHTML = '<i class="' + icons[type] + '"></i><span>' + message + '</span><button class="notification-close"><i class="fas fa-times"></i></button>';
    
    Object.assign(notification.style, {
        position: 'fixed',
        top: '20px',
        right: '20px',
        background: getNotificationColor(type),
        color: 'white',
        padding: '12px 16px',
        borderRadius: '8px',
        boxShadow: '0 4px 12px rgba(0, 0, 0, 0.15)',
        zIndex: '9999',
        display: 'flex',
        alignItems: 'center',
        gap: '8px',
        fontSize: '14px',
        fontWeight: '500',
        maxWidth: '400px',
        animation: 'slideIn 0.3s ease-out',
        backdropFilter: 'blur(10px)'
    });
    
    const closeBtn = notification.querySelector('.notification-close');
    Object.assign(closeBtn.style, {
        background: 'none',
        border: 'none',
        color: 'white',
        cursor: 'pointer',
        padding: '4px',
        borderRadius: '4px',
        opacity: '0.8',
        transition: 'opacity 0.2s'
    });
    
    closeBtn.addEventListener('mouseenter', () => {
        closeBtn.style.opacity = '1';
        closeBtn.style.background = 'rgba(255, 255, 255, 0.1)';
    });
    
    closeBtn.addEventListener('mouseleave', () => {
        closeBtn.style.opacity = '0.8';
        closeBtn.style.background = 'none';
    });
    
    closeBtn.addEventListener('click', () => {
        removeNotification(notification);
    });
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        if (notification.parentElement) {
            removeNotification(notification);
        }
    }, 5000);
};

/**
 * Gets the background color for notifications
 * @param {string} type - Notification type
 * @returns {string} - CSS background style
 */
const getNotificationColor = (type) => {
    const colors = {
        success: 'linear-gradient(135deg, #10b981, #059669)',
        error: 'linear-gradient(135deg, #ef4444, #dc2626)',
        warning: 'linear-gradient(135deg, #f59e0b, #d97706)',
        info: 'linear-gradient(135deg, #3b82f6, #2563eb)'
    };
    return colors[type] || colors.info;
};

/**
 * Removes a notification with animation
 * @param {HTMLElement} notification - Notification element
 */
const removeNotification = (notification) => {
    notification.style.animation = 'slideOut 0.3s ease-in forwards';
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 300);
};

/**
 * Adds notification and shake animation styles
 */
const addNotificationStyles = () => {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); } 20%, 40%, 60%, 80% { transform: translateX(5px); } }
        @keyframes ripple { to { transform: scale(2); opacity: 0; } }
        @keyframes clickWave { to { width: 40px; height: 40px; opacity: 0; margin-left: -20px; margin-top: -20px; } }
        .notification { transition: all 0.3s ease; }
        .notification:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2); }
        .invalid { border-color: #ef4444 !important; }
    `;
    document.head.appendChild(style);
};

// Menu link ripple effect
menuLinks.forEach((link) => {
    link.addEventListener('mouseenter', () => {
        const ripple = document.createElement('div');
        ripple.style.cssText = 'position: absolute; width: 100px; height: 100px; background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, transparent 70%); border-radius: 50%; pointer-events: none; transform: scale(0); animation: ripple 0.6s ease-out; left: 50%; top: 50%; margin-left: -50px; margin-top: -50px;';
        
        link.appendChild(ripple);
        
        setTimeout(() => {
            if (ripple.parentElement) {
                ripple.remove();
            }
        }, 600);
    });
});

// Handle window resize
let resizeTimeout;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(() => {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('show');
            navbarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        }
    }, 250);
});

// Disable recommended quizzes
const quizPanels = document.querySelectorAll('.quiz-panel');
quizPanels.forEach(panel => {
    panel.dataset.isRecommended = 'true';
    if (panel.dataset.isRecommended === 'true') {
        panel.style.cursor = 'not-allowed';
        panel.addEventListener('click', () => {
            showNotification('Kuis rekomendasi belum tersedia. Silakan buat atau gabung kuis dengan kode.', 'warning');
        });
    } else {
        panel.addEventListener('click', () => {
            const quizId = panel.dataset.quizId || '';
            const subject = panel.dataset.subject;
            const image = panel.dataset.image;
            document.getElementById('quizModalTitle').textContent = subject;
            document.getElementById('quizModalDescription').textContent = 'Mainkan kuis ' + subject + ' dengan ' + panel.querySelector('.quiz-info p').textContent + '!';
            document.getElementById('quizModalImage').src = image;
            playQuizBtn.dataset.quizId = quizId;
            quizExitBtn.style.visibility = 'visible';
            quizExitBtn.style.opacity = '1';
            quizExitBtn.style.position = 'absolute';
            quizExitBtn.style.top = '0.5rem';
            quizExitBtn.style.right = '0.5rem';
            quizCodeContainer.style.display = 'none';
            showOverlay(quizModalOverlay);
            showNotification('Memilih kuis ' + subject + '!', 'info');
        });
    }
});

// Play quiz button with validation
playQuizBtn.addEventListener('click', () => {
    const subject = document.getElementById('quizModalTitle').textContent;
    const quizId = playQuizBtn.dataset.quizId;
    const isRecommended = document.querySelector('.quiz-panel[data-subject="' + subject + '"]')?.dataset.isRecommended === 'true';
    
    if (!quizId || isNaN(parseInt(quizId)) || parseInt(quizId) <= 0 || isRecommended) {
        showNotification(isRecommended ? 'Kuis rekomendasi belum tersedia. Silakan buat atau gabung kuis dengan kode.' : 'ID kuis tidak valid!', 'error');
        closeModal();
        return;
    }

    playQuizBtn.style.transform = 'scale(0.98)';
    setTimeout(() => {
        playQuizBtn.style.transform = '';
    }, 100);

    fetch('dashboard.php', {
        method: 'POST',
        body: new URLSearchParams({
            action: 'log_activity',
            activity_type: 'Quiz Started',
            activity_details: 'Mulai kuis ' + subject
        })
    }).then(response => response.json())
      .then(data => {
          if (data.success) {
              showNotification('Memulai kuis ' + subject + '!', 'success');
              window.location.href = `quiz.php?quiz_id=${encodeURIComponent(quizId)}`;
          } else {
              showNotification('Gagal mencatat aktivitas!', 'error');
              closeModal();
          }
      }).catch(error => {
          showNotification('Terjadi kesalahan: ' + error.message, 'error');
          closeModal();
      });
});

// Intersection observer for animations
const observeElements = () => {
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '50px'
        });
        
        document.querySelectorAll('.welcome-panel, .search-panel, .quiz-panel, .quiz-recommendation-header, .panel, .activity-panel').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'all 0.6s ease-out';
            observer.observe(el);
        });
    }
};

// Button click effects
const addButtonEnhancements = () => {
    const buttons = document.querySelectorAll('button:not(.clear-activity-btn)');
    
    buttons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (!this.disabled && !this.classList.contains('loading')) {
                const rect = this.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                const clickEffect = document.createElement('div');
                clickEffect.style.cssText = 'position: absolute; width: 4px; height: 4px; background: rgba(255, 255, 255, 0.8); border-radius: 50%; pointer-events: none; left: ' + x + 'px; top: ' + y + 'px; animation: clickWave 0.6s ease-out';
                this.style.position = 'relative';
                this.appendChild(clickEffect);
                
                setTimeout(() => {
                    if (clickEffect.parentElement) {
                        clickEffect.remove();
                    }
                }, 600);
                
                this.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    this.style.transform = '';
                }, 100);
            }
        });
    });
};

// Initialize app
const initializeApp = async () => {
    console.log('🚀 Initializing QuizVerse...');
    
    loadUserData();
    
    addNotificationStyles();
    
    observeElements();
    addButtonEnhancements();
    
    const urlParams = new URLSearchParams(window.location.search);
    const error = urlParams.get('error');
    if (error) {
        showNotification(decodeURIComponent(error), 'error');
    }
    
    try {
        const response = await fetch('dashboard.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'check_welcome_shown'
            })
        });
        const data = await response.json();
        if (data.welcome_shown === false) {
            setTimeout(() => {
                showNotification('Selamat datang, ' + currentUserData.name + '!', 'success');
                fetch('dashboard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        action: 'set_welcome_shown'
                    })
                });
            }, 1000);
        }
    } catch (error) {
        console.error('Error checking welcome status:', error);
    }
    
    console.log('✨ QuizVerse initialized successfully!');
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeApp);
} else {
    initializeApp();
};

window.QuizVerse = {
    showNotification,
    updateUserData: (data) => {
        currentUserData = { ...currentUserData, ...data };
        saveUserData();
        updateUserDisplay();
    },
    getCurrentUser: () => ({ ...currentUserData })
};