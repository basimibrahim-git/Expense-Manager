/**
 * CSP-safe event wiring.
 *
 * The Content-Security-Policy only allows nonce'd scripts, so inline handlers
 * (onclick="...", onchange="...", onsubmit="...") are blocked by the browser.
 * Pages declare behaviour with data-* attributes instead, and this file wires
 * them up through delegated listeners.
 *
 *   data-onclick="fnName"         call window.fnName on click
 *   data-onchange="fnName"        call window.fnName on change
 *   data-oninput="fnName"         call window.fnName on input
 *   data-args='["a", 1, "$this"]' JSON argument list (optional). Tokens:
 *                                 "$this" -> the element, "$value" -> element.value,
 *                                 "$event" -> the DOM event
 *   data-autosubmit               submit the parent form when the field changes
 *   data-confirm="message"        on a <form> or a submit button: ask before submitting
 *   data-confirm-btn="Label"      optional label for the confirm button (default "Delete")
 *   data-print                    window.print() on click
 *   data-history-back             history.back() on click
 *
 * Functions are called with `this` bound to the element. If a click handler
 * returns false, the default action is prevented.
 */
(function () {
    'use strict';

    function resolveArgs(el, event) {
        var raw = el.getAttribute('data-args');
        if (!raw) {
            return [];
        }
        var args;
        try {
            args = JSON.parse(raw);
        } catch (e) {
            console.error('Invalid data-args JSON', raw, el);
            return [];
        }
        if (!Array.isArray(args)) {
            args = [args];
        }
        return args.map(function (a) {
            if (a === '$this') return el;
            if (a === '$value') return el.value;
            if (a === '$event') return event;
            return a;
        });
    }

    function invoke(el, attr, event) {
        var name = el.getAttribute(attr);
        var fn = name ? window[name] : null;
        if (typeof fn !== 'function') {
            console.error('Handler not found: ' + name);
            return undefined;
        }
        return fn.apply(el, resolveArgs(el, event));
    }

    function delegate(type, attr) {
        document.addEventListener(type, function (event) {
            var el = event.target.closest('[' + attr + ']');
            if (!el) {
                return;
            }
            var result = invoke(el, attr, event);
            if (result === false) {
                event.preventDefault();
            }
        });
    }

    delegate('click', 'data-onclick');
    delegate('change', 'data-onchange');
    delegate('input', 'data-oninput');

    document.addEventListener('change', function (event) {
        var el = event.target.closest('[data-autosubmit]');
        if (el && el.form) {
            el.form.requestSubmit ? el.form.requestSubmit() : el.form.submit();
        }
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-print]')) {
            event.preventDefault();
            window.print();
        } else if (event.target.closest('[data-history-back]')) {
            event.preventDefault();
            window.history.back();
        }
    });

    // ── Confirmation before submit ──────────────────────────────────────────
    // Works on <form data-confirm> and on <button type="submit" data-confirm>.
    // The real submission is re-issued with requestSubmit(submitter) so the
    // clicked button's name/value is still sent.
    var confirmedForms = new WeakSet();

    function askConfirm(message, buttonLabel, onConfirm) {
        var modalEl = document.getElementById('globalConfirmModal');
        if (!modalEl || typeof bootstrap === 'undefined') {
            if (window.confirm(message)) onConfirm();
            return;
        }
        document.getElementById('globalConfirmModalBody').textContent = message;
        var btn = document.getElementById('globalConfirmBtn');
        btn.textContent = buttonLabel || 'Delete';
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        btn.onclick = function () {
            modal.hide();
            onConfirm();
        };
        modal.show();
    }
    window.askConfirm = askConfirm;

    // ── Password requirement hints (see Layout::passwordRequirements) ─────────
    var pwLabels = { pwLen: '8+ characters', pwLet: 'letter', pwNum: 'number' };
    window.checkPasswordStrength = function (val) {
        var box = document.getElementById('pwRequirements');
        if (!box) return;
        box.style.display = val.length > 0 ? 'block' : 'none';
        var checks = {
            pwLen: val.length >= 8,
            pwLet: /[A-Za-z]/.test(val),
            pwNum: /[0-9]/.test(val)
        };
        Object.keys(checks).forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.style.color = checks[id] ? 'green' : 'red';
            el.textContent = (checks[id] ? '✓' : '✗') + ' ' + pwLabels[id];
        });
    };

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (confirmedForms.has(form)) {
            confirmedForms.delete(form);
            return;
        }
        var submitter = event.submitter || null;
        var source = (submitter && submitter.hasAttribute('data-confirm')) ? submitter
            : (form.hasAttribute('data-confirm') ? form : null);
        if (!source) {
            return;
        }
        event.preventDefault();
        askConfirm(source.getAttribute('data-confirm'), source.getAttribute('data-confirm-btn'), function () {
            confirmedForms.add(form);
            if (form.requestSubmit) {
                form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            } else {
                if (submitter && submitter.name) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = submitter.name;
                    hidden.value = submitter.value;
                    form.appendChild(hidden);
                }
                form.submit();
            }
        });
    }, true);
})();
