document.addEventListener('DOMContentLoaded', function () {
    const openModalBtns = document.querySelectorAll('#openModalBtn, #openModalBtn2, #openregmodelbtn');
    const modalBackground = document.getElementById('modalBackground');
    const closeBtn = document.querySelector('.close-btn');
    const modalBackground2 = document.getElementById('modalBackground2');

    openModalBtns.forEach(button => {
        button.addEventListener('click', function () {
            modalBackground.style.display = 'block';
            modalBackground2.style.display = 'none';
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