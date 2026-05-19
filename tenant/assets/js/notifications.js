/* AcademixSuite — Notifications (toasts + modal)
 *
 * Exposes:
 *   Toast.success(msg, opts)
 *   Toast.error(msg, opts)
 *   Toast.warning(msg, opts)
 *   Toast.info(msg, opts)
 *   Modal.alert(msg | {title, message, type})  → Promise<void>
 *   Modal.confirm(message | options)            → Promise<boolean>
 *   Modal.prompt(message | options)             → Promise<string|null>
 *
 * Also overrides window.alert and window.confirm so legacy code keeps working
 * but renders the new UI. The native functions are preserved under
 *   window.__native_alert / window.__native_confirm
 * in case anything ever needs them.
 *
 * To bootstrap server-side flashes, render:
 *   <script>window.__AS_FLASHES__ = [{type:'success', message:'Saved.'}];</script>
 * before this file loads — they'll be shown automatically on DOMContentLoaded.
 */
(function (global) {
    'use strict';

    if (global.Toast && global.Modal) {
        return; // already loaded
    }

    // ---------------------------------------------------------------- utils
    function ensureRegion() {
        var region = document.querySelector('.as-toast-region');
        if (!region) {
            region = document.createElement('div');
            region.className = 'as-toast-region';
            region.setAttribute('role', 'region');
            region.setAttribute('aria-live', 'polite');
            region.setAttribute('aria-label', 'Notifications');
            document.body.appendChild(region);
        }
        return region;
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    var ICONS = {
        success: '✓',
        error:   '!',
        warning: '!',
        info:    'i',
        confirm: '?'
    };

    var TITLES = {
        success: 'Success',
        error:   'Something went wrong',
        warning: 'Heads up',
        info:    'Info',
        confirm: 'Please confirm'
    };

    // ---------------------------------------------------------------- toast
    function toast(message, opts) {
        opts = opts || {};
        var type = opts.type || 'info';
        if (!ICONS[type]) type = 'info';
        var duration = opts.duration === 0 ? 0 : (opts.duration || 4200);
        var region = ensureRegion();

        var el = document.createElement('div');
        el.className = 'as-toast as-toast--' + type;
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.innerHTML =
            '<div class="as-toast__icon" aria-hidden="true">' + escapeHtml(ICONS[type]) + '</div>' +
            '<div class="as-toast__body">' +
                (opts.title ? '<div class="as-toast__title">' + escapeHtml(opts.title) + '</div>' : '') +
                '<div class="as-toast__msg">' + (opts.html ? message : escapeHtml(message)) + '</div>' +
            '</div>' +
            '<button class="as-toast__close" aria-label="Close">&times;</button>';

        region.appendChild(el);
        requestAnimationFrame(function () { el.classList.add('as-toast--in'); });

        var timer = null;
        function dismiss() {
            if (!el.parentNode) return;
            el.classList.remove('as-toast--in');
            el.classList.add('as-toast--out');
            setTimeout(function () {
                if (el.parentNode) el.parentNode.removeChild(el);
            }, 220);
            if (timer) clearTimeout(timer);
        }
        el.querySelector('.as-toast__close').addEventListener('click', dismiss);
        if (duration > 0) timer = setTimeout(dismiss, duration);
        return { dismiss: dismiss };
    }

    var Toast = {
        success: function (m, o) { return toast(m, Object.assign({}, o || {}, { type: 'success' })); },
        error:   function (m, o) { return toast(m, Object.assign({}, o || {}, { type: 'error', duration: (o && o.duration) || 6500 })); },
        warning: function (m, o) { return toast(m, Object.assign({}, o || {}, { type: 'warning' })); },
        info:    function (m, o) { return toast(m, Object.assign({}, o || {}, { type: 'info' })); },
        show:    toast
    };

    // ---------------------------------------------------------------- modal
    function openModal(opts) {
        return new Promise(function (resolve) {
            var type = opts.type || 'info';
            if (!ICONS[type]) type = 'info';
            var title = opts.title || TITLES[type];
            var message = opts.message || '';
            var mode = opts.mode || 'alert';      // alert | confirm | prompt
            var confirmLabel = opts.confirmLabel || (mode === 'confirm' ? 'Yes' : 'OK');
            var cancelLabel  = opts.cancelLabel  || 'Cancel';

            var overlay = document.createElement('div');
            overlay.className = 'as-modal-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');

            var modal = document.createElement('div');
            modal.className = 'as-modal as-modal--' + type;

            var bodyHtml = '';
            if (opts.html) {
                bodyHtml = String(message);
            } else {
                // Preserve newlines but escape everything else
                bodyHtml = escapeHtml(message).replace(/\n/g, '<br>');
            }
            if (mode === 'prompt') {
                bodyHtml += '<input class="as-modal-input" type="text" value="' + escapeHtml(opts.defaultValue || '') + '">';
            }

            var footHtml =
                (mode === 'alert'
                    ? '<button type="button" class="as-modal__btn as-modal__btn--primary" data-action="ok">' + escapeHtml(confirmLabel) + '</button>'
                    : '<button type="button" class="as-modal__btn" data-action="cancel">' + escapeHtml(cancelLabel) + '</button>' +
                      '<button type="button" class="as-modal__btn as-modal__btn--primary" data-action="ok">' + escapeHtml(confirmLabel) + '</button>');

            modal.innerHTML =
                '<div class="as-modal__head">' +
                    '<div class="as-modal__icon" aria-hidden="true">' + escapeHtml(ICONS[type]) + '</div>' +
                    '<h3 class="as-modal__title">' + escapeHtml(title) + '</h3>' +
                '</div>' +
                '<div class="as-modal__body">' + bodyHtml + '</div>' +
                '<div class="as-modal__foot">' + footHtml + '</div>';

            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            // lock scroll
            var prevOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';

            requestAnimationFrame(function () { overlay.classList.add('as-modal--in'); });

            var input = modal.querySelector('.as-modal-input');
            if (input) setTimeout(function () { input.focus(); input.select(); }, 30);
            else setTimeout(function () {
                var b = modal.querySelector('[data-action="ok"]');
                if (b) b.focus();
            }, 30);

            function close(result) {
                overlay.classList.remove('as-modal--in');
                setTimeout(function () {
                    if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                    document.body.style.overflow = prevOverflow;
                }, 180);
                resolve(result);
            }

            modal.addEventListener('click', function (e) {
                var t = e.target.getAttribute && e.target.getAttribute('data-action');
                if (!t) return;
                if (t === 'ok') {
                    if (mode === 'prompt') close(input ? input.value : '');
                    else if (mode === 'confirm') close(true);
                    else close(undefined);
                } else if (t === 'cancel') {
                    if (mode === 'prompt') close(null);
                    else if (mode === 'confirm') close(false);
                    else close(undefined);
                }
            });

            // Escape = cancel, Enter = OK (when not in textarea)
            overlay.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    if (mode === 'prompt') close(null);
                    else if (mode === 'confirm') close(false);
                    else close(undefined);
                } else if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    if (mode === 'prompt') close(input ? input.value : '');
                    else if (mode === 'confirm') close(true);
                    else close(undefined);
                }
            });
            // Click outside dismisses non-confirm dialogs.
            overlay.addEventListener('mousedown', function (e) {
                if (e.target === overlay) {
                    if (mode === 'confirm' || mode === 'prompt') return;
                    close(undefined);
                }
            });
        });
    }

    var Modal = {
        alert: function (msg) {
            if (msg && typeof msg === 'object' && !msg.nodeType) return openModal(Object.assign({ mode: 'alert' }, msg));
            return openModal({ mode: 'alert', message: String(msg == null ? '' : msg) });
        },
        confirm: function (msg) {
            if (msg && typeof msg === 'object' && !msg.nodeType) return openModal(Object.assign({ mode: 'confirm', type: 'confirm' }, msg));
            return openModal({ mode: 'confirm', type: 'confirm', message: String(msg == null ? '' : msg) });
        },
        prompt: function (msg, def) {
            if (msg && typeof msg === 'object' && !msg.nodeType) return openModal(Object.assign({ mode: 'prompt' }, msg));
            return openModal({ mode: 'prompt', message: String(msg == null ? '' : msg), defaultValue: def });
        }
    };

    // --------------------------------- legacy window.alert / confirm shims
    // We keep the originals on window in case some code really needs them.
    if (!global.__native_alert)   global.__native_alert   = global.alert;
    if (!global.__native_confirm) global.__native_confirm = global.confirm;

    // `alert` is supposed to be synchronous; the modal is async. Almost no
    // real code branches on the return value of alert(), so this is fine.
    global.alert = function (message) {
        try { Modal.alert(message); }
        catch (e) { return global.__native_alert.call(global, message); }
    };

    // confirm() is genuinely synchronous in older code. We can't fully replicate
    // that without `window.showModalDialog` (removed) or a blocking shim. For
    // backwards-compat, we keep a synchronous native fallback when the call is
    // made from a context that needs a boolean return (e.g. onsubmit handlers).
    // The recommended migration path is: Modal.confirm(...).then(ok => ...).
    global.confirm = function (message) {
        // If called from a context that uses the return value (e.g. an
        // onsubmit attribute), we fall back to the native dialog. Otherwise
        // we render the styled modal.
        try {
            var stack = (new Error()).stack || '';
            if (/onsubmit|onclick|onreset/i.test(stack)) {
                return global.__native_confirm.call(global, message);
            }
        } catch (_) { /* ignore */ }
        Modal.confirm(message); // returns a Promise, ignored
        return true;
    };

    // --------------------------------- expose globals + bootstrap flashes
    global.Toast = Toast;
    global.Modal = Modal;

    function bootstrap() {
        var flashes = global.__AS_FLASHES__;
        if (Array.isArray(flashes)) {
            flashes.forEach(function (f) {
                if (!f || !f.message) return;
                var fn = Toast[f.type] || Toast.info;
                fn(f.message, { title: f.title });
            });
            global.__AS_FLASHES__ = [];
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})(window);
