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

/* =========================================================
 * GAME CLICK COUNTER
 *
 * /games/{slug}/ 링크를 클릭할 때마다 서버에 +1.
 * 페이지 이동을 막지 않도록 sendBeacon/fetch keepalive 사용.
 * ========================================================= */
(() => {
    const endpoint = '/api/game_click.php';

    const slugFromHref = (href) => {
        try {
            const url = new URL(href, window.location.origin);

            if (url.origin !== window.location.origin) {
                return '';
            }

            const match = url.pathname.match(
                /^\/games\/([a-zA-Z0-9_-]+)\/?$/
            );

            return match ? match[1].toLowerCase() : '';

        } catch (_) {
            return '';
        }
    };

    const formatCount = (count) => {
        return Number(count || 0).toLocaleString('ko-KR');
    };

    const updateDisplays = (counts) => {
        document.querySelectorAll('[data-game-count]').forEach((el) => {
            const slug = String(el.dataset.gameCount || '').toLowerCase();

            if (!slug) {
                return;
            }

            const count = Number(counts[slug] || 0);
            el.textContent = '▶ 플레이 ' + formatCount(count) + '회';
        });
    };

    const loadCounts = async () => {
        try {
            const response = await fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();

            if (data && data.ok && data.counts) {
                updateDisplays(data.counts);
            }
        } catch (_) {
            // 카운트 조회 실패가 페이지 이용을 방해하면 안 된다.
        }
    };

    document.querySelectorAll('a[href]').forEach((link) => {
        const slug = slugFromHref(link.getAttribute('href') || '');

        if (!slug) {
            return;
        }

        link.dataset.gameClickSlug = slug;

        link.addEventListener('click', () => {
            const body = new FormData();
            body.append('slug', slug);

            if (navigator.sendBeacon) {
                navigator.sendBeacon(endpoint, body);
                return;
            }

            fetch(endpoint, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                keepalive: true
            }).catch(() => {});
        });
    });

    loadCounts();
})();
