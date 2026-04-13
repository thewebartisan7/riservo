(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) return;

    var slug = script.getAttribute('data-slug');
    if (!slug) {
        console.error('riservo embed: data-slug attribute is required');
        return;
    }

    var baseUrl = script.src.replace(/\/embed\.js(\?.*)?$/, '');
    var embedUrl = baseUrl + '/' + slug + '?embed=1';

    var overlay = null;
    var iframe = null;

    function createOverlay() {
        overlay = document.createElement('div');
        overlay.style.cssText =
            'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:999999;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 0.2s ease;';

        var container = document.createElement('div');
        container.style.cssText =
            'background:#fff;border-radius:12px;width:90%;max-width:500px;height:85vh;max-height:800px;overflow:hidden;position:relative;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);';

        var closeBtn = document.createElement('button');
        closeBtn.innerHTML = '&times;';
        closeBtn.style.cssText =
            'position:absolute;top:8px;right:12px;background:none;border:none;font-size:24px;cursor:pointer;color:#666;z-index:1;line-height:1;padding:4px;';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.addEventListener('click', close);

        iframe = document.createElement('iframe');
        iframe.src = embedUrl;
        iframe.style.cssText = 'width:100%;height:100%;border:none;';
        iframe.setAttribute('title', 'Book appointment');

        container.appendChild(closeBtn);
        container.appendChild(iframe);
        overlay.appendChild(container);

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });

        document.body.appendChild(overlay);

        requestAnimationFrame(function () {
            overlay.style.opacity = '1';
        });
    }

    function close() {
        if (!overlay) return;
        overlay.style.opacity = '0';
        setTimeout(function () {
            if (overlay && overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
            overlay = null;
            iframe = null;
        }, 200);
    }

    function handleKeydown(e) {
        if (e.key === 'Escape' && overlay) close();
    }

    document.addEventListener('keydown', handleKeydown);

    document.addEventListener('click', function (e) {
        var target = e.target.closest('[data-riservo-open]');
        if (target) {
            e.preventDefault();
            createOverlay();
        }
    });
})();
