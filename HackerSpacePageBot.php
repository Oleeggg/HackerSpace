<?php

require_once('../phpmailer/src/PHPMailer.php');
require_once('../phpmailer/src/SMTP.php');
require_once('../phpmailer/src/Exception.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
$mail->CharSet = 'utf-8';
// Усиленная защита сессии
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure'   => true,
    'cookie_httponly' => true,
    'use_strict_mode' => true
]);

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

// Проверка авторизации
$logged_in = false;
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_ip'] === $_SERVER['REMOTE_ADDR'] && 
        $_SESSION['user_agent'] === $_SERVER['HTTP_USER_AGENT']) {
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] < 1800)) {
            $logged_in = true;
            $_SESSION['last_activity'] = time();
            $user_name = $_SESSION['user_name'];
            $user_email = $_SESSION['user_email'];
        }
    }
}

// Выход из системы
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    
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

// Удаление аккаунта
if (isset($_POST['delete_account']) && $logged_in) {
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
    
    session_unset();
    session_destroy();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HackerSpaceWorkPage</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="reset.css">
    <link rel="stylesheet" type="text/css" href="css/PageBot.css">
</head>
<body>
    <header>
        <img class="img_logo" src="">
        <a class="for_developers" href="HackerSpaceMain.php">На Главную</a>
        <a class="help" href="">Помощь по продукту</a>
        <div class="buttons">
            <?php if (!$logged_in): ?>
            <?php else: ?>
                <div class="user-profile-container">
                    <button class="user-profile-btn" id="profileBtn">
                        👤 <span class="profile-name"><?php echo htmlspecialchars($user_name); ?></span>
                    </button>
                    <div class="profile-dropdown" id="profileDropdown">
                        <a href="#" id="profileModalBtn">Профиль</a>
                        <a href="settings.html">Настройки</a>
                        <a href="contact.html">Контакт с нами</a>
                        <a href="?logout">Выйти из аккаунта</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </header>

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

    <!-- Подтверждение удаления -->
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

    <div class="main">
        <div class="container1">
            <button class="button_start" type="submit">Начать генерацию!</button>
        </div>
        <div class="container2">

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

        // Обработка модального окна профиля
        document.getElementById('profileModalBtn')?.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('profileDropdown').classList.remove('show');
            document.getElementById('profileModal').style.display = 'block';
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
            document.getElementById('confirmDeleteModal').style.display = 'block';
        });
        
        // Обработка кнопки отмены удаления
        document.getElementById('confirmNo')?.addEventListener('click', function() {
            document.getElementById('confirmDeleteModal').style.display = 'none';
        });
        
        // Обработка кнопки выхода
        document.querySelector('.logout-btn')?.addEventListener('click', function() {
            window.location.href = '?logout';
        });
    </script>
</body>
</html>