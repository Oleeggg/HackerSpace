
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HackerSpace</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="reset.css">
    <link rel="stylesheet" type="text/css" href="css/developers.css">
</head>
<body>
    <header>
        <img class="img_logo" src="">
        <a class="for_developers" href="index.php">На Главную</a>
        <div class="buttons">
            <a href="HackerSpacePageBot.php" class="demo_button">Запросить демо</a>
        </div>
    </header>
    <div class="container_hello">
        <div class="cont1">
            <div><h1 class="title_for_developers_first">Разработчики, мы ждём Вас в команду!</h1></div>
        </div>
    </div>
    <div class="more_for_developers">
        <div class="text_for_developers">
            <p>Добро пожаловать в раздел "Для разработчиков"! Здесь вы найдете все необходимые ресурсы и инструменты для углубленного изучения программирования и разработки собственных проектов. Мы стремимся предоставить вам максимально полезную информацию и поддержку на каждом этапе вашего пути.</p>
                <p> Что вы найдете в этом разделе:
                    <p>Инструменты и ресурсы:</p>
                
                    <p>Ссылки на полезные библиотеки и фреймворки.</p>

                    <p>Сообщество и поддержка:</p>
                
                    <p>Форумы и чаты для общения с другими разработчиками, также имеется возможность задать вопросы и получить ответы от экспертов.</p>
                    
                    <div class="developers_container">
                        <img src="photo/hacker.jpg" class="how_to_start_img">
                        <div class="how_to_start_text">
                            <h1 class="title_for_developers">Как начать?</h1>
                            <p>Если вы новичок и не знаете, с чего начать, мы поможем вам сделать первые шаги!</p>
                            <h3>1. Выберите язык программирования, который Вам кажется легче и начните осваивать его.</h3>
                            <h3>2. Зарегистрируйтесь на курсы! Опытные специалисты помогут Вам точнее разобраться с языками и помочь на начальном этапе.</h3>
                            <h3>3. Практикуйтесь! Это самый основной ключ к успеху.</h3>
                            <h3>4. Присоединяйтесь к различным сообществам, который тесно связаны с программированием в выбранной Вами сфере.</h3>
                            <h3>5. Не бойтесь ошибаться.</h3>
                        </div>
                    </div>
                </p>
            </p>
            <p>
                Общайтесь с сообществом:
                
                Присоединяйтесь к форумам и чатам,
                задавайте вопросы и делитесь своими знаниями.
                <h1 class="title_for_developers2">Полезные ссылки:</h1>
                <p><a href="https://docs.python.org/3/">Документация по Python</a></p>
                <p><a href="https://developer.mozilla.org/ru/docs/Web/JavaScript">Руководство по JavaScript</a></p>
                <p><a href="https://git-scm.com/">Официальный сайт Git</a></p>
                <p><a href="https://stackoverflow.com/">Форум для разработчиков</a></p>
            </p>
            <p>
                Мы поможем Вам стать лучшим разработчиком и достичь новых высот в вашей карьере! Если у вас есть вопросы или предложения, не стесняйтесь обращаться к нам через форму обратной связи.
                <div class="respect_text">
                    <p>С уважением, Команда MisHACKERS!</p>
                </div>
            </p>
        </div>
    </div>
    <div id="modalBackground" class="modal-background">
        <div class="modal-content">
            <span class="close-btn">&times;</span>
            <h2>Вход</h2>
            <p>Пожалуйста, заполните данные для входа в аккаунт.</p>
            <form class="modal_form">
                <label class="email_reg">Введите почту</label>
                <input class="email_input" type="email" name="auth_email" placeholder="Введите Вашу почту" required >
                <label class="pass_reg">Введите пароль</label>
                <input class="pass_input" type="password" name="auth_pass" placeholder="Ваш пароль" required >
                <button class="model_button_reg" type="submit">Войти</button>
                <a class="Login" href="">Зарегистрироваться</a> 
            </form>
        </div>
    </div>

    <script src="js/regmodelwindow.js"></script>
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