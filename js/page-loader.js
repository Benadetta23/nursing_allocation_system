document.addEventListener('DOMContentLoaded', () => {
    const loader = document.createElement('div');
    loader.id = 'pageLoader';
    loader.className = 'page-loader';
    loader.innerHTML = `
        <div class="loader">
            <div class="spinner"></div>
            <p>Loading page...</p>
        </div>
    `;
    document.body.appendChild(loader);

    const showLoader = () => loader.classList.add('visible');
    const hideLoader = () => loader.classList.remove('visible');

    const isInternalLink = (href) => {
        if (!href || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
            return false;
        }

        try {
            const url = new URL(href, window.location.href);
            return url.origin === window.location.origin;
        } catch (error) {
            return false;
        }
    };

    document.querySelectorAll('a[href]').forEach((link) => {
        const href = link.getAttribute('href');
        if (!isInternalLink(href) || link.target && link.target !== '_self') {
            return;
        }

        link.addEventListener('click', (event) => {
            if (!href || href === '#') {
                return;
            }

            event.preventDefault();
            showLoader();

            setTimeout(() => {
                if (href.startsWith('#')) {
                    const target = document.querySelector(href);
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth' });
                    }
                    hideLoader();
                    history.pushState(null, '', href);
                } else {
                    window.location.href = href;
                }
            }, 1200);
        });
    });
});
