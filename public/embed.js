(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) return;

    var slug = script.getAttribute('data-slug');
    if (!slug) {
        console.error('riservo embed: data-slug attribute is required');
        return;
    }

    var defaultService = script.getAttribute('data-service') || null;
    var ariaLabel = script.getAttribute('data-label') || 'Book appointment';
    var baseUrl = script.src.replace(/\/embed\.js(\?.*)?$/, '');

    var overlay = null;
    var iframe = null;
    var closeBtn = null;
    var lastTrigger = null;
    var originalBodyOverflow = '';
    var mousedownOnOverlay = false;

    function resolveService(triggerEl) {
        if (triggerEl) {
            var perButton = triggerEl.getAttribute('data-riservo-service');
            if (perButton) return perButton;
        }
        return defaultService;
    }

    function buildUrl(service) {
        return baseUrl + '/' + slug + (service ? '/' + service : '') + '?embed=1';
    }

    function createOverlay(triggerEl) {
        // Duplicate guard: module-level and DOM-level (covers duplicate <script> tags).
        if (overlay || document.querySelector('[data-riservo-overlay]')) return;

        lastTrigger =
            triggerEl ||
            (document.activeElement instanceof HTMLElement ? document.activeElement : null);

        overlay = document.createElement('div');
        overlay.setAttribute('data-riservo-overlay', '');
        overlay.style.cssText =
            'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:999999;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 0.2s ease;';

        var container = document.createElement('div');
        container.setAttribute('role', 'dialog');
        container.setAttribute('aria-modal', 'true');
        container.setAttribute('aria-label', ariaLabel);
        container.style.cssText =
            'background:#fff;border-radius:12px;width:90%;max-width:500px;height:85vh;max-height:800px;overflow:hidden;position:relative;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);';

        closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.innerHTML = '&times;';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.style.cssText =
            'position:absolute;top:8px;right:12px;background:none;border:none;font-size:24px;cursor:pointer;color:#666;z-index:1;line-height:1;padding:4px;';
        closeBtn.addEventListener('click', close);

        iframe = document.createElement('iframe');
        iframe.src = buildUrl(resolveService(triggerEl));
        iframe.setAttribute('title', 'Book appointment');
        iframe.style.cssText = 'width:100%;height:100%;border:none;';

        container.appendChild(closeBtn);
        container.appendChild(iframe);
        overlay.appendChild(container);

        // Backdrop close only when both mousedown and mouseup land on the overlay itself.
        // Prevents accidental close when dragging a selection from iframe to backdrop.
        overlay.addEventListener('mousedown', function (e) {
            mousedownOnOverlay = e.target === overlay;
        });
        overlay.addEventListener('mouseup', function (e) {
            if (mousedownOnOverlay && e.target === overlay) close();
            mousedownOnOverlay = false;
        });

        originalBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        document.body.appendChild(overlay);
        document.addEventListener('focusin', trapFocus);

        requestAnimationFrame(function () {
            overlay.style.opacity = '1';
            closeBtn.focus();
        });
    }

    function close() {
        if (!overlay) return;

        var overlayToRemove = overlay;
        var trigger = lastTrigger;

        overlay.style.opacity = '0';
        document.body.style.overflow = originalBodyOverflow;
        document.removeEventListener('focusin', trapFocus);

        overlay = null;
        iframe = null;
        closeBtn = null;
        lastTrigger = null;
        mousedownOnOverlay = false;

        setTimeout(function () {
            if (overlayToRemove.parentNode) {
                overlayToRemove.parentNode.removeChild(overlayToRemove);
            }
            if (trigger && document.contains(trigger) && typeof trigger.focus === 'function') {
                trigger.focus();
            }
        }, 200);
    }

    function trapFocus(e) {
        if (!overlay || overlay.contains(e.target)) return;
        if (closeBtn) closeBtn.focus();
    }

    function handleKeydown(e) {
        if (e.key === 'Escape' && overlay) close();
    }

    document.addEventListener('keydown', handleKeydown);

    document.addEventListener('click', function (e) {
        var target = e.target.closest('[data-riservo-open]');
        if (target) {
            e.preventDefault();
            createOverlay(target);
        }
    });
})();
