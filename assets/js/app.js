(() => {
    const button = document.querySelector('.mobile-menu-button');
    const nav = document.querySelector('#main-nav');

    if (button && nav) {
        button.addEventListener('click', () => {
            const open = nav.classList.toggle('open');
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
})();
