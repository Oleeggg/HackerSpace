<?php

require_once('phpmailer/src/PHPMailer.php');
require_once('phpmailer/src/SMTP.php');
require_once('phpmailer/src/Exception.php');

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

function call_devstral_api($prompt) {
    $api_url = 'https://api.devstral.ai/small/free'; // Уточните точный URL API
    
    $data = [
        'prompt' => $prompt,
        'max_tokens' => 500,
        'temperature' => 0.7
    ];
    
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
        ],
    ];
    
    $context  = stream_context_create($options);
    $result = file_get_contents($api_url, false, $context);
    
    if ($result === FALSE) {
        return false;
    }
    
    return json_decode($result, true);
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
        <a class="for_developers" href="index.php">На Главную</a>
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
        <div class="generation-controls">
        <textarea id="promptInput" placeholder="Введите ваш запрос для генерации..."></textarea>
        <div class="settings-panel">
            <label for="creativity">Креативность:</label>
            <input type="range" id="creativity" min="0.1" max="1.0" step="0.1" value="0.7">
            <span id="creativityValue">0.7</span>
            
            <label for="length">Длина ответа:</label>
            <select id="length">
                <option value="200">Короткий</option>
                <option value="500" selected>Средний</option>
                <option value="1000">Длинный</option>
            </select>
        </div>
        <button id="generateBtn" class="button_generate">Сгенерировать</button>
    </div>
    
    <div class="generation-results">
        <div class="loading-indicator" id="loadingIndicator" style="display: none;">
            <div class="spinner"></div>
            <p>Devstral Small обрабатывает ваш запрос...</p>
        </div>
        <div class="result-container" id="resultContainer"></div>
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
    <script> 
    document.addEventListener('DOMContentLoaded', function() {
    // Обработка кнопки "Начать генерацию!"
    document.querySelector('.button_start').addEventListener('click', function() {
        document.querySelector('.container1').style.display = 'none';
        document.querySelector('.container2').style.display = 'block';
    });
    
    // Обновление значения креативности
    document.getElementById('creativity').addEventListener('input', function() {
        document.getElementById('creativityValue').textContent = this.value;
    });
    
    // Обработка генерации
    document.getElementById('generateBtn').addEventListener('click', function() {
        const prompt = document.getElementById('promptInput').value.trim();
        if (!prompt) {
            alert('Пожалуйста, введите запрос для генерации');
            return;
        }
        
        const creativity = parseFloat(document.getElementById('creativity').value);
        const maxTokens = parseInt(document.getElementById('length').value);
        
        // Показываем индикатор загрузки
        document.getElementById('loadingIndicator').style.display = 'block';
        document.getElementById('resultContainer').innerHTML = '';
        
        // Отправка запроса на сервер
        fetch('generate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                prompt: prompt,
                creativity: creativity,
                max_tokens: maxTokens
            })
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById('loadingIndicator').style.display = 'none';
            if (data.success) {
                document.getElementById('resultContainer').innerHTML = 
                    `<div class="generated-content">${data.result}</div>
                     <div class="generation-actions">
                        <button class="copy-btn">Копировать</button>
                        <button class="regenerate-btn">Сгенерировать снова</button>
                     </div>`;
                
                // Добавляем обработчики для новых кнопок
                document.querySelector('.copy-btn').addEventListener('click', function() {
                    navigator.clipboard.writeText(data.result);
                    alert('Текст скопирован в буфер обмена!');
                });
                
                document.querySelector('.regenerate-btn').addEventListener('click', function() {
                    document.getElementById('generateBtn').click();
                });
            } else {
                document.getElementById('resultContainer').innerHTML = 
                    `<div class="error-message">Ошибка: ${data.error}</div>`;
            }
        })
        .catch(error => {
            document.getElementById('loadingIndicator').style.display = 'none';
            document.getElementById('resultContainer').innerHTML = 
                `<div class="error-message">Ошибка соединения: ${error.message}</div>`;
        });
    });
});   
    </script>
</body>
</html>