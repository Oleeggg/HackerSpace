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
    <style>
        /* Добавленные стили */
        .code-editor {
            width: 100%;
            height: 300px;
            font-family: monospace;
            border: 1px solid #ddd;
            padding: 10px;
            background: #f8f8f8;
            margin-bottom: 15px;
        }
        .progress-container {
            margin-top: 20px;
            padding: 15px;
            background: #f0f0f0;
            border-radius: 5px;
        }
        .progress-bar {
            height: 20px;
            background: #e0e0e0;
            border-radius: 10px;
            margin-bottom: 10px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: #4CAF50;
            width: 0%;
            transition: width 0.3s;
        }
        .progress-message {
            font-family: monospace;
            white-space: pre-wrap;
        }
        .error-message {
            color: #f44336;
        }
        .success-message {
            color: #4CAF50;
        }
    </style>
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
            <h2>Получить задание от нейросети</h2>
            <div class="task-controls">
                <select id="taskDifficulty">
                    <option value="easy">Легкое</option>
                    <option value="medium" selected>Среднее</option>
                    <option value="hard">Сложное</option>
                </select>
                <select id="taskLanguage">
                    <option value="python">Python</option>
                    <option value="javascript">JavaScript</option>
                    <option value="php">PHP</option>
                </select>
                <button class="button_start" id="getTaskBtn">Получить задание</button>
            </div>
            <div class="task-description" id="taskDescription"></div>
        </div>
        <div class="container2" style="display:none;">
            <h2>Решите задание</h2>
            <div class="task-title" id="currentTaskTitle"></div>
            <textarea class="code-editor" id="codeEditor" placeholder="Напишите здесь ваш код..."></textarea>
            <button class="button_submit" id="submitCodeBtn">Отправить ответ</button>
            
            <div class="progress-container" id="progressContainer" style="display:none;">
                <h3>Прогресс проверки:</h3>
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <div class="progress-message" id="progressMessage"></div>
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
            let currentTask = null;
            
            // Получение задания от нейросети
            document.getElementById('getTaskBtn').addEventListener('click', function() {
                const difficulty = document.getElementById('taskDifficulty').value;
                const language = document.getElementById('taskLanguage').value;
                
                document.getElementById('taskDescription').innerHTML = '<p>Загрузка задания...</p>';
                
                // Эмуляция запроса к нейросети (в реальности будет fetch)
                setTimeout(() => {
                    // Здесь должен быть реальный запрос к API нейросети
                    // Для примера используем mock-данные
                    const tasks = {
                        easy: {
                            python: {
                                title: "Простая задача: Сумма чисел",
                                description: "Напишите функцию sum(a, b), которая возвращает сумму двух чисел."
                            },
                            javascript: {
                                title: "Простая задача: Конкатенация строк",
                                description: "Напишите функцию concat(str1, str2), которая объединяет две строки."
                            },
                            php: {
                                title: "Простая задача: Массив в строку",
                                description: "Напишите функцию arrayToString($arr), которая преобразует массив в строку через запятую."
                            }
                        },
                        medium: {
                            python: {
                                title: "Средняя задача: Фильтрация списка",
                                description: "Напишите функцию filter_list(lst), которая принимает список и возвращает новый список, содержащий только числа."
                            },
                            javascript: {
                                title: "Средняя задача: Уникальные элементы",
                                description: "Напишите функцию getUnique(arr), которая возвращает массив уникальных элементов."
                            },
                            php: {
                                title: "Средняя задача: Поиск простых чисел",
                                description: "Напишите функцию findPrimes($n), которая возвращает массив всех простых чисел до n."
                            }
                        },
                        hard: {
                            python: {
                                title: "Сложная задача: Бинарное дерево",
                                description: "Реализуйте класс BinaryTree с методами вставки, поиска и удаления узлов."
                            },
                            javascript: {
                                title: "Сложная задача: Promise.all",
                                description: "Реализуйте свою версию функции Promise.all()."
                            },
                            php: {
                                title: "Сложная задача: MVC роутер",
                                description: "Реализуйте простой MVC роутер, который обрабатывает URL и вызывает соответствующие контроллеры."
                            }
                        }
                    };
                    
                    currentTask = tasks[difficulty][language];
                    document.getElementById('taskDescription').innerHTML = `
                        <h3>${currentTask.title}</h3>
                        <p>${currentTask.description}</p>
                        <button id="startSolvingBtn">Начать решение</button>
                    `;
                    
                    document.getElementById('startSolvingBtn').addEventListener('click', function() {
                        document.querySelector('.container1').style.display = 'none';
                        document.querySelector('.container2').style.display = 'block';
                        document.getElementById('currentTaskTitle').textContent = currentTask.title;
                    });
                }, 1000);
            });
            
            // Отправка кода на проверку
            document.getElementById('submitCodeBtn').addEventListener('click', function() {
                const code = document.getElementById('codeEditor').value.trim();
                if (!code) {
                    alert('Пожалуйста, напишите код перед отправкой');
                    return;
                }
                
                const progressContainer = document.getElementById('progressContainer');
                const progressFill = document.getElementById('progressFill');
                const progressMessage = document.getElementById('progressMessage');
                
                progressContainer.style.display = 'block';
                progressFill.style.width = '0%';
                progressMessage.innerHTML = '';
                
                // Эмуляция процесса проверки нейросетью
                simulateCodeCheck(progressFill, progressMessage);
            });
            
            function simulateCodeCheck(progressFill, progressMessage) {
                const steps = [
                    {progress: 10, message: "🔍 Анализ синтаксиса..."},
                    {progress: 30, message: "✅ Синтаксис корректен\n🔍 Проверка структуры кода..."},
                    {progress: 50, message: "🔍 Запуск тестов..."},
                    {progress: 70, message: "✅ 3/5 тестов пройдено\n🔍 Анализ производительности..."},
                    {progress: 90, message: "🔍 Проверка стиля кода..."},
                    {progress: 100, message: "🎉 Задание выполнено!\n✅ Все тесты пройдены\n✔ Код соответствует стандартам"}
                ];
                
                steps.forEach((step, index) => {
                    setTimeout(() => {
                        progressFill.style.width = step.progress + '%';
                        progressMessage.innerHTML = step.message;
                        
                        if (step.progress === 100) {
                            progressMessage.classList.add('success-message');
                        }
                    }, index * 1500);
                });
            }
        });
    </script>
</body>
</html>