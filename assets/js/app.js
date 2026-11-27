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
 * GAME PLAY ATTEMPT COUNTER (public / no sign-in required)
 *
 * Count a deliberate click on a registered game's launch URL. This works
 * for links on both the home page and the /games/ catalog, including
 * mixed-case folders such as /games/Baduk/.
 * A visit to the game listing itself is NOT a game launch.
 * ========================================================= */
(() => {
    const endpoint = '/api/game_click.php';

    const slugFromHref = (href) => {
        try {
            const url = new URL(href, window.location.href);
            if (url.origin !== window.location.origin) return '';

            const match = url.pathname.match(
                /^\/games\/([a-zA-Z0-9_-]{1,80})(?:\/(?:.*)?)?$/i
            );
            return match ? match[1].toLowerCase() : '';
        } catch (_) {
            return '';
        }
    };

    const formatCount = (count) => Number(count || 0).toLocaleString('ko-KR');

    const ensureCountBadge = (link, slug) => {
        const article = link.closest('article')
            || link.closest('.project-row')
            || link.closest('.home-game-card')
            || link.parentElement;
        if (!article) return null;

        const existing = article.querySelector('[data-game-count="' + slug + '"]');
        if (existing) return existing;

        const spans = Array.from(article.querySelectorAll('span'));
        const gameBadge = spans.find(el => String(el.textContent || '').trim() === '웹게임');
        const devBadge = spans.find(el => {
            const text = String(el.textContent || '').trim();
            return text === '개발중' || text === '운영중' || text === '공개';
        });
        const badgeRow = (gameBadge && gameBadge.parentElement)
            || (devBadge && devBadge.parentElement)
            || article.querySelector('.home-game-badges');
        if (!badgeRow) return null;

        const badge = document.createElement('span');
        badge.className = 'game-play-count-badge';
        badge.dataset.gameCount = slug;
        badge.textContent = '집계 확인 중';
        badgeRow.appendChild(badge);
        return badge;
    };

    const updateDisplays = (counts) => {
        document.querySelectorAll('a[data-game-click-slug]').forEach(link => {
            const slug = link.dataset.gameClickSlug || '';
            if (slug) ensureCountBadge(link, slug);
        });

        document.querySelectorAll('[data-game-count]').forEach(el => {
            const slug = String(el.dataset.gameCount || '').toLowerCase();
            if (slug) {
                el.textContent = '플레이 ' + formatCount(counts[slug]) + '회';
                el.removeAttribute('title');
            }
        });
    };

    const indicateError = () => {
        document.querySelectorAll('[data-game-count]').forEach(el => {
            el.textContent = '집계 확인 불가';
            el.title = '게임 플레이 집계 API 또는 DB 설정을 확인해 주세요.';
        });
    };

    const loadCounts = async () => {
        try {
            const response = await fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!response.ok) throw new Error('Counter API HTTP ' + response.status);
            const data = await response.json();
            if (!data || !data.ok || !data.counts) throw new Error('Invalid counter API response');
            updateDisplays(data.counts);
        } catch (error) {
            indicateError();
            console.warn('[GAME LAB PLAY COUNT]', error);
        }
    };

    // Delegation handles links rendered or updated after script startup.
    document.addEventListener('click', event => {
        const target = event.target instanceof Element ? event.target : event.target.parentElement;
        const link = target && target.closest('a[href]');
        if (!link || event.defaultPrevented) return;

        const slug = slugFromHref(link.href);
        if (!slug) return;

        const body = new FormData();
        body.append('slug', slug);

        // A beacon survives page navigation and does not delay launching a game.
        // If the browser rejects it, retry with keepalive fetch.
        let sent = false;
        if (typeof navigator.sendBeacon === 'function') {
            sent = navigator.sendBeacon(endpoint, body);
        }
        if (!sent) {
            fetch(endpoint, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                keepalive: true
            }).catch(() => {});
        }
    }, true);

    // Read current counts on entry and after a game is closed/back navigation.
    loadCounts();
    window.addEventListener('pageshow', event => {
        if (event.persisted) loadCounts();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') loadCounts();
    });
})();
