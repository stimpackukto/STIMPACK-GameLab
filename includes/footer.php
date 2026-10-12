</main>
<footer class="site-footer">
    <div class="container footer-inner">
        <div>
            <a class="footer-brand" href="/">🎮 <span>STIMPACK</span> GAME <b>LAB</b></a>
            <p>14년의 STIMPACK, 이제 GAME LAB으로 이어갑니다.</p>
        </div>
        <nav class="footer-links" aria-label="하단 메뉴">
            <a href="/privacy.php">개인정보처리방침</a>
            <a href="/terms.php">이용약관</a>
            <a href="/contact.php">문의</a>
        </nav>
    </div>
    <div class="container copyright">
        © <?= date('Y') ?> STIMPACK GAME LAB. All rights reserved.
    </div>
</footer>
<script>
/* 부산버스 WEB 배지만 iPhone 전용 (다른 WEB 배지는 기존 동작 유지) */
(function () {
    'use strict';
    function isIPhone() {
        return /iPhone/i.test(navigator.userAgent || '');
    }
    document.addEventListener('click', function (event) {
        var element = event.target instanceof Element
            ? event.target : event.target.parentElement;
        var link = element && element.closest('a.web-status[href]');
        if (!link) return;
        var destination = new URL(link.href, window.location.href);
        if (destination.pathname.replace(/\/+$/, '') !== '/busanbus/web') return;
        if (isIPhone()) return;
        event.preventDefault();
        window.alert('iPhone에서만 지원합니다.');
    }, true);
}());
</script>
</body>
</html>
