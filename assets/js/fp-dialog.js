/* Shared in-page confirm/alert. Replaces window.confirm / window.alert on every
   admin screen (QA U3): one overlay at a time, focus trapped inside, Escape and
   a backdrop click cancel, focus goes back where it came from on close.
   window.fpDialog.confirm({ title, message, ok, cancel, danger }) -> Promise<true|false|null>  (null = dismissed)
   window.fpDialog.alert(message, { title, ok })                  -> Promise<void>
   window.fpDialog.prompt({ title, message, value, ok, cancel })  -> Promise<string|null>  (null = cancelled, like window.prompt) */
(function () {
    'use strict';

    var closeCurrent = null;
    var seq = 0;
    // Guarded in case a screen loads this without wp-i18n; literals so make-pot sees them. (QA L25)
    function i18n() { return window.wp && window.wp.i18n; }

    function open(opts, isAlert) {
        return new Promise(function (resolve) {
            if (closeCurrent) { closeCurrent(null); }   // one at a time: the older one is DISMISSED (null), never answered "no" [QA L23]

            var prev = document.activeElement;
            var overlay = document.createElement('div');
            overlay.className = 'fp-dialog' + (opts.danger ? ' is-danger' : '');
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');

            var box = document.createElement('div');
            box.className = 'fp-dialog__box';
            overlay.appendChild(box);

            var labelId = 'fp-dialog-' + (++seq);
            if (opts.title) {
                var title = document.createElement('h2');
                title.className = 'fp-dialog__title';
                title.id = labelId;
                title.textContent = opts.title;
                box.appendChild(title);
            }
            var body = document.createElement('div');
            body.className = 'fp-dialog__body';
            String(opts.message || '').split('\n').forEach(function (line) {
                if (!line.trim()) { return; }
                var p = document.createElement('p');
                p.textContent = line;
                body.appendChild(p);
            });
            if (!opts.title) { body.id = labelId; }
            overlay.setAttribute('aria-labelledby', labelId);
            box.appendChild(body);

            var input = null;   // prompt mode: one text field, Enter = OK
            if (typeof opts.value === 'string') {
                input = document.createElement('input');
                input.type = 'text';
                input.className = 'fp-dialog__input';
                input.value = opts.value;
                input.setAttribute('aria-labelledby', labelId);
                input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.repeat) { e.preventDefault(); close(input.value); } });
                box.appendChild(input);
            }

            var row = document.createElement('div');
            row.className = 'fp-dialog__btns';
            var cancelBtn = null;
            if (!isAlert) {
                cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'fp-dialog__btn fp-dialog__cancel';
                cancelBtn.textContent = opts.cancel || (i18n() ? wp.i18n.__('Cancel', 'fastpix-io') : 'Cancel');
                cancelBtn.addEventListener('click', function () { close(input ? null : false); });
                row.appendChild(cancelBtn);
            }
            var okBtn = document.createElement('button');
            okBtn.type = 'button';
            okBtn.className = 'fp-dialog__btn fp-dialog__ok';
            okBtn.textContent = opts.ok || (i18n() ? wp.i18n.__('OK', 'fastpix-io') : 'OK');
            okBtn.addEventListener('click', function () { close(input ? input.value : true); });
            row.appendChild(okBtn);
            box.appendChild(row);

            function close(value) {
                if (closeCurrent !== close) { return; }
                closeCurrent = null;
                document.removeEventListener('keydown', onKey, true);
                if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
                if (prev && prev.focus) { prev.focus(); }
                resolve(isAlert ? undefined : value);
            }
            closeCurrent = close;

            // Escape / backdrop = dismissed (null): callers that offer a two-way choice must not read it as either answer.
            overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { close(null); } });
            // Keys are caught on the document (capture), not the overlay: Escape must work wherever
            // focus is — verified 2026-09-20 that focus can still sit on <body> right after opening.
            function onKey(e) {
                if (e.key === 'Escape') { e.preventDefault(); close(null); return; }
                // A held Enter/Space must not answer a dialog that opened under it (a two-stage confirm chains in a microtask, before keyup). [QA L22]
                if (e.repeat && e.target !== input && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); return; }
                if (e.key !== 'Tab') { return; }
                var first = input || cancelBtn || okBtn, last = okBtn;
                if (!overlay.contains(document.activeElement)) { e.preventDefault(); first.focus(); return; }
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
            document.addEventListener('keydown', onKey, true);

            document.body.appendChild(overlay);
            var initial = input || (opts.danger && cancelBtn ? cancelBtn : okBtn);   // a destructive dialog never has Enter = destroy [QA L22]
            initial.focus();
            if (input) { input.select(); }
            setTimeout(function () { if (closeCurrent === close && !overlay.contains(document.activeElement)) { initial.focus(); } }, 0);
        });
    }

    window.fpDialog = {
        confirm: function (opts) { return open(opts || {}, false); },
        prompt: function (opts) { opts = opts || {}; opts.value = String(opts.value || ''); return open(opts, false); },
        alert: function (message, opts) {
            opts = opts || {};
            opts.message = message;
            return open(opts, true);
        }
    };
})();
