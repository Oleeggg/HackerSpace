document.addEventListener('DOMContentLoaded', function () {
    const openModalBtns = document.querySelectorAll('#openlogmodelbtn');
    const modalBackground = document.getElementById('modalBackground2');
    const closeBtn = document.querySelector('.close-btn1');
    const modalBackground1 = document.getElementById('modalBackground');

    openModalBtns.forEach(button => {
        button.addEventListener('click', function () {
            modalBackground.style.display = 'block';
            modalBackground1.style.display = 'none';
        });
    });

    closeBtn.addEventListener('click', function () {
        modalBackground.style.display = 'none';
    });

    window.addEventListener('click', function (event) {
        if (event.target === modalBackground) {
            modalBackground.style.display = 'none';
        }
    });
});