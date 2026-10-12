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
/* WEB 뱃지: 모바일 웹앱만 지원, PC에서는 현재 사이트를 유지합니다. */
(function () {
    'use strict';
    function isMobileDevice() {
        var ua = navigator.userAgent || '';
        return (navigator.userAgentData && navigator.userAgentData.mobile === true)
            || /Android|iPhone|iPod|iPad|Mobile|Windows Phone/i.test(ua)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }
    document.addEventListener('click', function (event) {
        var element = event.target instanceof Element ? event.target : event.target.parentElement;
        var link = element && element.closest('a.web-status[href]');
        if (!link || isMobileDevice()) return;
        event.preventDefault();
        window.alert('모바일에서만 지원합니다.');
    }, true);
}());
</script>
</body>
</html>
