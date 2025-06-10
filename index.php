<?php
require_once('phpmailer/src/PHPMailer.php');
require_once('phpmailer/src/SMTP.php');
require_once('phpmailer/src/Exception.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;


    // Усиленная защита сессии
    session_start([
        'cookie_lifetime' => 86400, // 1 день
        'cookie_secure'   => true,  // Только через HTTPS
        'cookie_httponly' => true,   // Защита от XSS
        'use_strict_mode' => true    // Защита от фиксации сессии
    ]);
    
    // Регенерация ID сессии при каждом входе
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id();
        $_SESSION['initiated'] = true;
    }
    
    // Настройки подключения к базе данных
    $db_host = 'mysql';
    $db_user = 'mysite'; 
    $db_pass = 'Ovmj1yvFil6QEl';     
    $db_name = 'mysite';
    
    // Создаем подключение
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    // Проверяем подключение
    if ($conn->connect_error) {
        die("Ошибка подключения: " . $conn->connect_error);
    }
    
    
    // Обработка регистрации
    $reg_error = '';
    $reg_success = '';
    if (isset($_POST['register'])) {
        $login = trim($_POST['auth_name']);
        $email = trim($_POST['auth_email']);
        $password = trim($_POST['auth_pass']);
        
        // Валидация данных
        if (empty($login) || empty($email) || empty($password)) {
            $reg_error = 'Все поля обязательны для заполнения!';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $reg_error = 'Некорректный email!';
        } else {
            // Проверка существования пользователя
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $reg_error = 'Пользователь с таким email уже существует!';
            } else {
                // Хеширование пароля
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Добавление пользователя
                $stmt = $conn->prepare("INSERT INTO users (login, email, pass) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $login, $email, $hashed_password);
                
                if ($stmt->execute()) {
                    $reg_success = 'Регистрация прошла успешно! Теперь вы можете войти.';
                } else {
                    $reg_error = 'Ошибка при регистрации: ' . $conn->error;
                }
            }
            $stmt->close();
        }
    }
    
    // Обработка авторизации
    $login_error = '';
    if (isset($_POST['login'])) {
        $email = trim($_POST['auth_email']);
        $password = trim($_POST['auth_pass']);
        
        if (empty($email) || empty($password)) {
            $login_error = 'Введите email и пароль!';
        } else {
            $stmt = $conn->prepare("SELECT id, login, pass FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                if (password_verify($password, $user['pass'])) {
                    // Успешная авторизация
                    session_regenerate_id(true); // Защита от фиксации сессии
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['login'];
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                    $_SESSION['last_activity'] = time();
                } else {
                    $login_error = 'Неверный пароль!';
                }
            } else {
                $login_error = 'Пользователь с таким email не найден!';
            }
            $stmt->close();
        }
    }

// Обработка формы обратной связи
$feedback_success = '';
$feedback_error = '';
if (isset($_POST['send_feedback'])) {
    // Проверяем авторизацию пользователя
    if (!isset($_SESSION['user_id'])) {
        $feedback_error = 'Для отправки сообщения необходимо авторизоваться';
    } else {
        $message = trim($_POST['feedback_message'] ?? '');
        
        if (empty($message)) {
            $feedback_error = 'Пожалуйста, введите ваше сообщение';
        } else {
            try {
                // Получаем данные пользователя из сессии
                $user_id = $_SESSION['user_id'] ?? null;
                $user_name = $_SESSION['user_name'] ?? 'Пользователь';
                $user_email = $_SESSION['user_email'] ?? 'unknown@example.com';
                
                // Настройка PHPMailer
                $mail = new PHPMailer(true);
                
                // Настройки сервера для Outlook
                $mail->isSMTP();
                $mail->Host = 'smtp-mail.outlook.com';
                $mail->SMTPAuth = true;
                $mail->Username = '21200172@live.preco.ru';
                $mail->Password = '7519356463';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->CharSet = 'UTF-8';
                
                // Отправитель должен совпадать с учётной записью
                $mail->setFrom('21200172@live.preco.ru', 'HackerSpace');
                $mail->addAddress('21200172@live.preco.ru', 'Admin');
                $mail->addReplyTo($user_email, $user_name);
                
                // Содержание письма
                $mail->isHTML(false);
                $mail->Subject = "Обратная связь от пользователя HackerSpace";
                $mail->Body = "Сообщение от пользователя: " . $user_name . "\n" .
                             "Email: " . $user_email . "\n\n" .
                             "Сообщение:\n" . $message;
                
                $mail->send();
                $feedback_success = 'Ваше сообщение успешно отправлено!';
                
            } catch (Exception $e) {
                $feedback_error = "Ошибка отправки сообщения. Mailer Error: {$mail->ErrorInfo}";
                error_log("Mail send failed: " . $e->getMessage());
            }
        }
    }
}
    
    // Проверка валидности сессии
    $logged_in = false;
    if (isset($_SESSION['user_id'])) {
        // Проверяем IP и User-Agent
        if ($_SESSION['user_ip'] === $_SERVER['REMOTE_ADDR'] && 
            $_SESSION['user_agent'] === $_SERVER['HTTP_USER_AGENT']) {
            // Проверяем время бездействия (30 минут)
            if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] < 1800)) {
                $logged_in = true;
                $_SESSION['last_activity'] = time(); // Обновляем время последней активности
            } else {
                // Время сессии истекло
                session_unset();
                session_destroy();
                header("Location: ".$_SERVER['PHP_SELF']);
                exit();
            }
        } else {
            // Подозрительная активность - IP или User-Agent изменились
            session_unset();
            session_destroy();
            header("Location: ".$_SERVER['PHP_SELF']);
                exit();
        }
    }
    
    $user_name = $logged_in ? $_SESSION['user_name'] : '';
    $user_email = $logged_in ? $_SESSION['user_email'] : '';
    
    // Выход из системы
    if (isset($_GET['logout'])) {
        // Уничтожаем все данные сессии
        session_unset();
        session_destroy();
        
        // Удаляем куки сессии
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>HackerSpace</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="reset.css">
    <link rel="stylesheet" type="text/css" href="css/main.css">
</head>
<body>
    <header>
        <img class="img_logo" src="">
        <a class="for_developers" href="HackerSpaceForDevelopers.php">Для разработчиков</a>
        <div class="buttons_up">
            <?php if (!$logged_in): ?>
                <button id="openLoginModalBtn" class="reg_button">Войти</button>
            <?php endif; ?>
            <a class="demo_button" href="HackerSpacePageBot.php">Перейти к генерации!</a>
        </div>
    </header>

    <div class="container_hello">
        <div class="cont">
            <div>
                <h1>Навыки говорят громче слов!</h1>
                <p>Мы помогаем компаниям развивать сильнейшие технические команды. Мы помогаем участникам оттачивать свои технические навыки!</p>
                <div class="buttons_center">
                    <?php if (!$logged_in): ?>
                        <button id="openModalBtn2" class="reg_button">Зарегистрироваться</button>
                    <?php endif; ?>
                    <a class="demo_button" href="HackerSpacePageBot.php">Перейти к генерации!</a>
                </div>
            </div>
        </div>
    </div>
    <div class="had_container">
        <p>Более 35% разработчиков на всему миру и 3000 компаний используют HackerSpace</p>
        <div class="logos">
            <img src="photo/YRK.png" alt="УРК">
            <img src="photo/YTU.png" alt="ЮУТУ">
        </div>
    </div>

    <!-- Профиль пользователя (только для авторизованных) -->
    <?php if ($logged_in): ?>
    <div class="user-profile-container">
        <button class="user-profile-btn" id="profileBtn">
            👤 <span class="profile-name"><?php echo htmlspecialchars($user_name); ?></span>
        </button>
        
        <div class="profile-dropdown" id="profileDropdown">
            <a href="#" id="profileModalBtn">Профиль</a>
            <a href="#" id="settingsModalBtn">Настройки</a>
            <a href="contact.html">Контакт с нами</a>
            <a href="?logout">Выйти из аккаунта</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Модальное окно регистрации -->
    <div id="modalBackground" class="modal-background">
        <div class="modal-content">
            <span class="close-btn">&times;</span>
            <h2>Регистрация</h2>
            <form class="modal_form" method="POST" action="">
                <label class="surname_reg">Введите имя</label>
                <input class="surname_input_reg" type="text" name="auth_name" placeholder="Введите ваше имя" required>
                <label class="email_reg">Введите почту</label>
                <input class="email_input_reg" type="email" name="auth_email" placeholder="Введите вашу почту" required>
                <label class="pass_reg">Введите пароль</label>
                <input class="pass_input_reg" type="password" name="auth_pass" placeholder="Ваш пароль" required>
                <button class="model_button_reg" type="submit" name="register">Зарегистрироваться</button>
                <a id="openlogmodelbtn" class="Login">Войти в существующий аккаунт</a> 
            </form>
        </div>
    </div>

    <!-- Модальное окно авторизации -->
    <div id="modalBackground2" class="modal-background">
        <div class="modal-content">
            <span class="close-btn1">&times;</span>
            <h2>Авторизация</h2>
            <form class="modal_form" method="POST" action="">
                <label class="email_log">Введите почту</label>
                <input class="email_input_log" type="email" name="auth_email" placeholder="Введите вашу почту" required>
                <label class="pass_log">Введите пароль</label>
                <input class="pass_input_log" type="password" name="auth_pass" placeholder="Ваш пароль" required>
                <button class="model_button_reg" type="submit" name="login">Войти</button>
                <a id="openregmodelbtn" class="Registr">Зарегистрируйтесь здесь!</a> 
            </form>
        </div>
    </div>

    <!-- Модальное окно профиля -->
    <div id="profileModal" class="profile-modal">
        <div class="profile-modal-content">
            <div class="profile-modal-header">
                <h2>Профиль</h2>
                <span class="profile-modal-close">&times;</span>
            </div>
            
            <div class="profile-section">
                <table class="profile-info">
                    <tr>
                        <td>Имя</td>
                        <td><?php echo htmlspecialchars($user_name); ?></td>
                    </tr>
                    <tr>
                        <td>Email</td>
                        <td><?php echo htmlspecialchars($user_email); ?></td>
                    </tr>
                </table>
            </div>
            <div class="profile-section">
                <div class="profile-actions">
                    <button class="logout-btn">Выйти</button>
                    <button class="delete-btn">Удалить аккаунт</button>
                </div>
            </div>
        </div>
    </div>

    <div id="confirmDeleteModal" class="confirm-modal">
        <div class="confirm-content">
            <h3>Подтверждение удаления</h3>
            <p>Вы уверены, что хотите удалить свой аккаунт? Это действие нельзя отменить.</p>
            <div class="confirm-buttons">
                <form method="POST" action="" style="display: inline;">
                    <button type="submit" name="delete_account" class="confirm-btn confirm-yes">Да, удалить</button>
                </form>
                <button id="confirmNo" class="confirm-btn confirm-no">Отмена</button>
            </div>
        </div>
    </div>

    <!-- Модальное окно обратной связи -->
    <div id="feedbackModal" class="feedback-modal">
        <div class="feedback-content">
            <div class="feedback-header">
                <h2>Обратная связь</h2>
                <span class="feedback-close">&times;</span>
            </div>
            <form class="feedback-form" method="POST" action="">
                <textarea name="feedback_message" placeholder="Возникли проблемы? Опишите их здесь!" required></textarea>
                <button type="submit" name="send_feedback">Отправить</button>
                <div class="feedback-success" <?php if (!empty($feedback_success)) echo 'style="display: block;"'; ?>>
                    <?php echo $feedback_success; ?>
                </div>
                <div class="feedback-error" <?php if (!empty($feedback_error)) echo 'style="display: block;"'; ?>>
                    <?php echo $feedback_error; ?>
                </div>
            </form>
        </div>
    </div>
    
    <div id="overlay" class="overlay"></div>

    <!-- Модальное окно ошибки регистрации -->
    <?php if (!empty($reg_error)): ?>
    <div id="errorModal" class="message-modal" style="display: flex;">
        <div class="message-content">
            <button class="close-message-btn">&times;</button>
            <p class="error-message"><?php echo $reg_error; ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Модальное окно успешной регистрации -->
    <?php if (!empty($reg_success)): ?>
    <div id="successModal" class="message-modal" style="display: flex;">
        <div class="message-content">
            <button class="close-message-btn">&times;</button>
            <p class="success-message"><?php echo $reg_success; ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Модальное окно ошибки авторизации -->
    <?php if (!empty($login_error)): ?>
    <div id="loginErrorModal" class="message-modal" style="display: flex;">
        <div class="message-content">
            <button class="close-message-btn">&times;</button>
            <p class="error-message"><?php echo $login_error; ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Модальное окно настроек -->
    <div id="settingsModal" class="settings-modal">
        <div class="settings-content">
            <div class="settings-header">
                <h2>Настройки</h2>
                <span class="settings-close">&times;</span>
            </div>
            <div class="settings-body">
                <div class="setting-option">
                    <span>Язык</span>
                    <div class="language-option">
                        <span>Русский</span>
                        <span class="language-check">✔</span>
                    </div>
                </div>
                <div class="setting-option">
                    <h3>Тема</h3>
                    <div class="theme-options">
                        <button id="lightTheme" class="theme-btn active">Светлая</button>
                        <button id="darkTheme" class="theme-btn">Тёмная</button>
                    </div>
                </div>
            </div>
            <div class="settings-footer">
                <button id="saveSettings" class="save-btn">Сохранить</button>
            </div>
        </div>
    </div>

    <script>
        // Обработка клика по профилю
        document.getElementById('profileBtn')?.addEventListener('click', function() {
            document.getElementById('profileDropdown').classList.toggle('show');
        });
        
        // Закрытие меню при клике вне его
        window.addEventListener('click', function(event) {
            if (!event.target.matches('#profileBtn') && !event.target.closest('.profile-dropdown')) {
                var dropdown = document.getElementById('profileDropdown');
                if (dropdown?.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }
        });
        
        // Переключение между модальными окнами
        document.getElementById('openlogmodelbtn')?.addEventListener('click', function() {
            document.getElementById('modalBackground').style.display = 'none';
            document.getElementById('modalBackground2').style.display = 'flex';
        });
        
        document.getElementById('openregmodelbtn')?.addEventListener('click', function() {
            document.getElementById('modalBackground2').style.display = 'none';
            document.getElementById('modalBackground').style.display = 'flex';
        });
        
        // Закрытие модальных окон
        document.querySelector('.close-btn')?.addEventListener('click', function() {
            document.getElementById('modalBackground').style.display = 'none';
        });
        
        document.querySelector('.close-btn1')?.addEventListener('click', function() {
            document.getElementById('modalBackground2').style.display = 'none';
        });
        
        // Открытие модальных окон по кнопкам
        document.getElementById('openLoginModalBtn')?.addEventListener('click', function() {
            document.getElementById('modalBackground2').style.display = 'flex';
        });
        
        document.getElementById('openModalBtn2')?.addEventListener('click', function() {
            document.getElementById('modalBackground').style.display = 'flex';
        });
        
        // Закрытие сообщений
        document.querySelectorAll('.close-message-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                this.closest('.message-modal').style.display = 'none';
            });
        });
        
        // Автоматическое закрытие сообщений через 5 секунд
        setTimeout(() => {
            document.querySelectorAll('.message-modal').forEach(modal => {
                modal.style.display = 'none';
            });
        }, 5000);
        
        // Обработка модального окна профиля
        document.getElementById('profileModalBtn')?.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('profileDropdown').classList.remove('show');
            document.getElementById('profileModal').style.display = 'flex';
        });
        
        document.querySelector('.profile-modal-close')?.addEventListener('click', function() {
            document.getElementById('profileModal').style.display = 'none';
        });
        
        // Закрытие модального окна профиля при клике вне его
        window.addEventListener('click', function(event) {
            if (event.target === document.getElementById('profileModal')) {
                document.getElementById('profileModal').style.display = 'none';
            }
        });

        // Обработка кнопки удаления аккаунта
        document.querySelector('.delete-btn')?.addEventListener('click', function() {
            document.getElementById('confirmDeleteModal').style.display = 'flex';
        });
        
        // Обработка кнопки отмены удаления
        document.getElementById('confirmNo')?.addEventListener('click', function() {
            document.getElementById('confirmDeleteModal').style.display = 'none';
        });
        
        // Обработка кнопки выхода
        document.querySelector('.logout-btn')?.addEventListener('click', function() {
            window.location.href = '?logout';
        });
        
        // Обработка кнопки "Контакт с нами"
        document.querySelector('a[href="contact.html"]')?.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('profileDropdown').classList.remove('show');
            document.getElementById('feedbackModal').classList.add('show');
            document.getElementById('overlay').classList.add('show');
        });
        
        // Закрытие модального окна обратной связи
        document.querySelector('.feedback-close')?.addEventListener('click', function() {
            document.getElementById('feedbackModal').classList.remove('show');
            document.getElementById('overlay').classList.remove('show');
        });
        
        // Закрытие при клике на overlay
        document.getElementById('overlay')?.addEventListener('click', function() {
            document.getElementById('feedbackModal').classList.remove('show');
            this.classList.remove('show');
        });
        
        // Обработка модального окна настроек
        document.getElementById('settingsModalBtn')?.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('profileDropdown').classList.remove('show');
            document.getElementById('settingsModal').style.display = 'flex';
            
            // Установка текущей темы
            const currentTheme = localStorage.getItem('theme') || 'light';
            if (currentTheme === 'dark') {
                document.getElementById('darkTheme').classList.add('active');
                document.getElementById('lightTheme').classList.remove('active');
            } else {
                document.getElementById('lightTheme').classList.add('active');
                document.getElementById('darkTheme').classList.remove('active');
            }
        });
        
        // Закрытие модального окна настроек
        document.querySelector('.settings-close')?.addEventListener('click', function() {
            document.getElementById('settingsModal').style.display = 'none';
        });
        
        // Обработка выбора темы
        document.getElementById('lightTheme')?.addEventListener('click', function() {
            document.getElementById('lightTheme').classList.add('active');
            document.getElementById('darkTheme').classList.remove('active');
        });
        
        document.getElementById('darkTheme')?.addEventListener('click', function() {
            document.getElementById('darkTheme').classList.add('active');
            document.getElementById('lightTheme').classList.remove('active');
        });
        
        // Сохранение настроек
        document.getElementById('saveSettings')?.addEventListener('click', function() {
            const selectedTheme = document.getElementById('lightTheme').classList.contains('active') ? 'light' : 'dark';
            localStorage.setItem('theme', selectedTheme);
            applyTheme();
            document.getElementById('settingsModal').style.display = 'none';
        });
        
        // Применение темы
        function applyTheme() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-theme');
            } else {
                document.body.classList.remove('dark-theme');
            }
        }
        
        // Инициализация темы при загрузке
        window.addEventListener('DOMContentLoaded', function() {
            applyTheme();
        });
        
        // Закрытие при клике вне модального окна
        window.addEventListener('click', function(event) {
            if (event.target === document.getElementById('settingsModal')) {
                document.getElementById('settingsModal').style.display = 'none';
            }
        });
        
        // Автоматическое закрытие сообщений через 5 секунд
        setTimeout(() => {
            document.querySelectorAll('.feedback-success, .feedback-error').forEach(el => {
                el.style.display = 'none';
            });
        }, 5000);
    </script>
    <script src="js/regmodelwindow.js"></script>
    <script src="js/logmodelwindow.js"></script>
</body>
</html>