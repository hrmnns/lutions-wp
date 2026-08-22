(function () {
    function isAllowedYouTubeEmbedUrl(src) {
        try {
            var url = new URL(src, window.location.href);
            return url.protocol === 'https:'
                && url.hostname === 'www.youtube-nocookie.com'
                && /^\/embed\/[A-Za-z0-9_-]{11}$/.test(url.pathname);
        } catch (error) {
            return false;
        }
    }

    function loadYouTubeEmbed(container) {
        var src = container.getAttribute('data-embed-src');
        var title = container.getAttribute('data-embed-title') || 'YouTube video';
        if (!src || !isAllowedYouTubeEmbedUrl(src) || container.querySelector('iframe')) {
            return;
        }

        var iframe = document.createElement('iframe');
        iframe.setAttribute('src', src);
        iframe.setAttribute('title', title);
        iframe.setAttribute('loading', 'lazy');
        iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups');

        container.classList.remove('lutions-wp-youtube-embed-placeholder');
        container.textContent = '';
        container.appendChild(iframe);
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        var button = target.closest('[data-lutions-wp-youtube-load]');
        if (!button) {
            return;
        }

        var container = button.closest('[data-lutions-wp-youtube-embed]');
        if (!container) {
            return;
        }

        event.preventDefault();
        loadYouTubeEmbed(container);
    });
}());
