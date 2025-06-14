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
    <link rel="stylesheet" type="text/css" href="css/PageBot.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/dracula.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES); ?>">
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
            <div class="task-controls">
                <select id="taskDifficulty" class="task-select">
                    <option value="beginner">Начинающий</option>
                    <option value="intermediate">Средний</option>
                    <option value="advanced">Продвинутый</option>
                </select>
                <select id="taskLanguage" class="task-select">
                    <option value="javascript">JavaScript</option>
                    <option value="python">Python</option>
                    <option value="html">HTML/CSS</option>
                </select>
                <button id="requestTaskBtn" class="button_start">
                    <i class="fas fa-magic"></i> Сгенерировать задание
                </button>
            </div>
            <div id="taskDescription" class="task-description">
                <div class="placeholder-text">
                    <i class="fas fa-lightbulb"></i>
                    <p>Выберите сложность и язык программирования, затем нажмите "Сгенерировать задание"</p>
                </div>
            </div>
        </div>
        
        <div class="container2">
            <div class="code-editor-container">
                <div class="editor-header">
                    <h3><i class="fas fa-code"></i> Редактор кода</h3>
                    <div class="language-selector">
                        <span>Язык: </span>
                        <select id="editorLanguage">
                            <option value="javascript">JavaScript</option>
                            <option value="python">Python</option>
                            <option value="htmlmixed">HTML/CSS</option>
                        </select>
                    </div>
                </div>
                <textarea id="codeEditor"></textarea>
                <div class="editor-footer">
                    <button id="submitSolutionBtn" class="submit-btn">
                        <i class="fas fa-paper-plane"></i> Отправить решение
                    </button>
                    <div id="executionResult" class="execution-result">
                        <div class="placeholder-text">
                            <i class="fas fa-robot"></i>
                            <p>Здесь будет отображаться результат проверки вашего решения</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/php/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/python/python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/matchbrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/closebrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/comment/comment.min.js"></script>
   <script>
    // Получаем CSRF токен при загрузке страницы
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!csrfToken) {
        console.error('CSRF token is missing!');
    }

    // Инициализация редактора кода
    const codeEditor = CodeMirror.fromTextArea(document.getElementById('codeEditor'), {
        lineNumbers: true,
        mode: 'javascript',
        theme: 'dracula',
        matchBrackets: true,
        autoCloseBrackets: true,
        indentUnit: 4,
        tabSize: 4,
        extraKeys: {
            'Ctrl-Enter': submitSolution,
            'Cmd-Enter': submitSolution
        }
    });

    // Обработчик изменения языка
    document.getElementById('editorLanguage').addEventListener('change', function() {
        const modeMap = {
            'javascript': 'javascript',
            'php': 'php',
            'python': 'python',
            'html': 'htmlmixed',
            'css': 'css'
        };
        const selectedMode = modeMap[this.value] || 'javascript';
        codeEditor.setOption('mode', selectedMode);
    });

    // Текущее задание
    let currentTask = null;

    // Запрос задания у нейросети
    document.getElementById('requestTaskBtn').addEventListener('click', async function() {
        const difficulty = document.getElementById('taskDifficulty').value;
        const language = document.getElementById('taskLanguage').value;
        
        const taskDescription = document.getElementById('taskDescription');
        taskDescription.innerHTML = `
            <div class="loading-indicator">
                <div class="spinner"></div>
                <p>Генерация задания...</p>
            </div>
        `;
        
        try {
            const response = await fetch('api/get_task.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    difficulty: difficulty,
                    language: language
                })
            });
            
            // Обработка HTTP ошибок
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                const errorMsg = errorData.error || `HTTP error! status: ${response.status}`;
                throw new Error(errorMsg);
            }
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Не удалось получить задание');
            }
            
            currentTask = data.task;
            
            // Форматирование вывода задания
            taskDescription.innerHTML = `
                <div class="task-header">
                    <h4>${escapeHtml(currentTask.title)}</h4>
                    <span class="difficulty-badge ${currentTask.difficulty}">${
                        currentTask.difficulty.charAt(0).toUpperCase() + currentTask.difficulty.slice(1)
                    }</span>
                </div>
                <div class="task-content">
                    <p>${escapeHtml(currentTask.description)}</p>
                    ${
                        currentTask.example ? `
                        <div class="task-example">
                            <h5><i class="fas fa-lightbulb"></i> Пример:</h5>
                            <pre>${escapeHtml(currentTask.example)}</pre>
                        </div>` : ''
                    }
                </div>
            `;
            
            // Установка языка и начального кода
            document.getElementById('editorLanguage').value = language;
            const editorMode = language === 'html' ? 'htmlmixed' : language;
            codeEditor.setOption('mode', editorMode);
            codeEditor.setValue(currentTask.initialCode || '');
            
        } catch (error) {
            taskDescription.innerHTML = `
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Ошибка при получении задания: ${escapeHtml(error.message)}</p>
                    <button onclick="location.reload()">Попробовать снова</button>
                </div>
            `;
            console.error('Ошибка:', error);
        }
    });

    // Отправка решения на проверку
    document.getElementById('submitSolutionBtn').addEventListener('click', submitSolution);

    async function submitSolution() {
        if (!currentTask) {
            updateResult('Пожалуйста, сначала получите задание', 'error');
            return;
        }
        
        const solution = codeEditor.getValue();
        const resultDiv = document.getElementById('executionResult');
        resultDiv.innerHTML = `
            <div class="loading-indicator">
                <div class="spinner"></div>
                <p>Проверка решения...</p>
            </div>
        `;
        
        try {
            const response = await fetch('api/evaluate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    task_id: currentTask.id || 'current',
                    solution: solution,
                    language: document.getElementById('editorLanguage').value
                })
            });
            
            // Обработка HTTP ошибок
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                const errorMsg = errorData.error || `HTTP error! status: ${response.status}`;
                throw new Error(errorMsg);
            }
            
            const evaluation = await response.json();
            
            if (!evaluation.success) {
                throw new Error(evaluation.error || 'Ошибка при проверке решения');
            }
            
            // Форматирование результатов проверки
            let resultHTML = `
                <div class="evaluation-result">
                    <div class="result-header">
                        <h4><i class="fas fa-clipboard-check"></i> Результат проверки</h4>
                        <div class="progress-circle" style="--progress: ${evaluation.evaluation?.score || 0}">
                            <span>${evaluation.evaluation?.score || 0}%</span>
                        </div>
                    </div>
                    <div class="result-message ${
                        (evaluation.evaluation?.score || 0) > 70 ? 'success' : 
                        (evaluation.evaluation?.score || 0) > 40 ? 'warning' : 'error'
                    }">
                        <p>${escapeHtml(evaluation.evaluation?.message || 'Нет информации')}</p>
                    </div>
            `;
            
            // Добавление деталей если есть
            if (evaluation.evaluation?.details) {
                resultHTML += `
                    <div class="result-details">
                        <h5><i class="fas fa-info-circle"></i> Детали:</h5>
                        <p>${escapeHtml(evaluation.evaluation.details)}</p>
                    </div>
                `;
            }
            
            // Добавление рекомендаций если есть
            if (evaluation.evaluation?.suggestions?.length > 0) {
                resultHTML += `
                    <div class="result-suggestions">
                        <h5><i class="fas fa-lightbulb"></i> Рекомендации:</h5>
                        <ul>
                            ${evaluation.evaluation.suggestions.map(s => `<li>${escapeHtml(s)}</li>`).join('')}
                        </ul>
                    </div>
                `;
            }
            
            resultHTML += `</div>`;
            resultDiv.innerHTML = resultHTML;
            
        } catch (error) {
            resultDiv.innerHTML = `
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Ошибка при проверке решения: ${escapeHtml(error.message)}</p>
                    <button onclick="submitSolution()">Попробовать снова</button>
                </div>
            `;
            console.error('Ошибка проверки:', {
                error: error.message,
                stack: error.stack,
                task: currentTask
            });
        }
    }

    // Вспомогательные функции
    function updateResult(message, type = 'info') {
        const resultDiv = document.getElementById('executionResult');
        resultDiv.innerHTML = `
            <div class="${type}-message">
                <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'info-circle'}"></i>
                <p>${escapeHtml(message)}</p>
            </div>
        `;
    }

    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe.toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
</script>
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
    <!-- Подключение CodeMirror JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <!-- Подключение режима для JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
    <script>
        // Инициализация CodeMirror
        var editor = CodeMirror(document.getElementById('codeEditor'), {
            mode: 'javascript',
            theme: 'dracula',
            lineNumbers: true,
            indentUnit: 4,
            lineWrapping: true
        });
    </script>
</body>
</html>