<?php
session_start();
    
    // Настройки подключения к базе данных
    $db_host = '193.109.78.59';
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
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['login'];
                    $_SESSION['user_email'] = $email;
                } else {
                    $login_error = 'Неверный пароль!';
                }
            } else {
                $login_error = 'Пользователь с таким email не найден!';
            }
            $stmt->close();
        }
    }
    
    // Выход из системы
    if (isset($_GET['logout'])) {
        session_destroy();
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }
    
    $logged_in = isset($_SESSION['user_id']);
    $user_name = $logged_in ? $_SESSION['user_name'] : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>HackerSpace</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="reset.css">
    <link rel="stylesheet" type="text/css" href="css/main.css">
    <style>
        /* Стили для профиля */
        .user-profile-container {
            position: fixed;
            left: 20px;
            bottom: 20px;
            z-index: 1000;
        }
        
        .user-profile-btn {
            background: #2c3e50;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .profile-dropdown {
            display: none;
            position: absolute;
            left: 0;
            bottom: 100%;
            background: white;
            min-width: 200px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            overflow: hidden;
            margin-bottom: 10px;
        }
        
        .profile-dropdown a {
            display: block;
            padding: 12px 16px;
            color: #333;
            text-decoration: none;
            transition: background 0.2s;
        }
        
        .profile-dropdown a:hover {
            background: #f5f5f5;
        }
        
        .profile-dropdown.show {
            display: block;
        }
        
        .profile-name {
            margin-left: 8px;
        }
        
        /* Стили для сообщений */
        .error-message {
            color: red;
            margin: 10px 0;
        }
        
        .success-message {
            color: green;
            margin: 10px 0;
        }
    </style>
</head>
<body>


    <header>
        <img class="img_logo" src="">
        <a class="for_developers" href="HackerSpaceForDevelopers.html">Для разработчиков</a>
        <a class="help" href="">Помощь по продукту</a>
        <div class="buttons">
                <button id="openModalBtn" class="reg_button">Зарегистрироваться</button>
                <a class="demo_button" href="HackerSpacePageBot.html">Запросить демо</a>
        </div>
    </header>

    <div class="container_hello">
        <div class="cont">
            <div>
                <h1>Навыки говорят громче слов!</h1>
                <p>Мы помогаем компаниям развивать сильнейшие технические команды. Мы помогаем участникам оттачивать свои технические навыки!</p>
                <div class="buttons">
                    <button id="openModalBtn2" class="reg_button">Зарегистрироваться</button>
                    <a class="demo_button" href="HackerSpacePageBot.html">Запросить демо</a>
                </div>
            </div>
        </div>
    </div>
    <div class="had_container">
        <p>Более 35% разработчиков на всему миру и 3000 компаний используют HackerSpace</p>
        <div class="logos">
            <img src="" alt="YPK">
            <img src="" alt="ЮТУ">
        </div>
    </div>

    <!-- Профиль пользователя (только для авторизованных) -->
    <?php if ($logged_in): ?>
    <div class="user-profile-container">
        <button class="user-profile-btn" id="profileBtn">
            👤 <span class="profile-name"><?php echo htmlspecialchars($user_name); ?></span>
        </button>
        
        <div class="profile-dropdown" id="profileDropdown">
            <a href="my_profile.html">My Profile</a>
            <a href="settings.html">Settings</a>
            <a href="contact.html">Contact us</a>
            <a href="?logout">Log out</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Модальное окно регистрации -->
    <div id="modalBackground" class="modal-background">
        <div class="modal-content">
            <span class="close-btn">&times;</span>
            <h2>Регистрация</h2>
            <?php if (!empty($reg_error)): ?>
                <p class="error-message"><?php echo $reg_error; ?></p>
            <?php elseif (!empty($reg_success)): ?>
                <p class="success-message"><?php echo $reg_success; ?></p>
            <?php endif; ?>
            <form class="modal_form" method="POST" action="">
                <label class="surname_reg">Введите имя пользователя</label>
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
            <?php if (!empty($login_error)): ?>
                <p class="error-message"><?php echo $login_error; ?></p>
            <?php endif; ?>
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
        document.getElementById('openModalBtn')?.addEventListener('click', function() {
            document.getElementById('modalBackground').style.display = 'flex';
        });
        
        document.getElementById('openModalBtn2')?.addEventListener('click', function() {
            document.getElementById('modalBackground').style.display = 'flex';
        });
    </script>
    <script src="js/regmodelwindow.js"></script>
    <script src="js/logmodelwindow.js"></script>
</body>
</html>