{{--
    Reusable confirmation dialog — the single destructive-action guard for the whole app.
    Included once per layout (console shell + hosted portal). Accessible: aria-modal,
    focus-trapped, ESC cancels, Enter confirms, restores focus to the trigger on close.

    Two ways to consume it (waves 2-4 reuse BOTH for their delete/void/refund actions):

    1. Declarative — add `data-confirm="…"` to a <form>, <a>, or <button>. Optional:
         data-confirm-title   dialog heading            (default "Are you sure?")
         data-confirm-label    confirm-button text       (default "Confirm")
         data-confirm-variant  "destructive" | "primary" (default "destructive")
       A guarded form submits (native validation + loading state intact) only after
       the operator confirms; a guarded link navigates only after confirm.

    2. Programmatic — `window.cboxConfirm({title, body, confirmLabel, variant})`
       returns a Promise<boolean>. Use inside JS-driven flows (e.g. the portal).
--}}
<div class="cbx-confirm-backdrop" id="cbxConfirmBackdrop" hidden></div>
<div class="cbx-confirm" id="cbxConfirm" role="dialog" aria-modal="true" aria-labelledby="cbxConfirmTitle" aria-describedby="cbxConfirmBody" hidden>
    <h2 class="cbx-confirm-title" id="cbxConfirmTitle">Are you sure?</h2>
    <p class="cbx-confirm-body" id="cbxConfirmBody"></p>
    <div class="cbx-confirm-actions">
        <button type="button" class="cbx-btn cbx-btn--secondary cbx-btn--sm" id="cbxConfirmCancel">Cancel</button>
        <button type="button" class="cbx-btn cbx-btn--destructive cbx-btn--sm" id="cbxConfirmOk">Confirm</button>
    </div>
</div>
<script>
(function () {
    var backdrop = document.getElementById('cbxConfirmBackdrop');
    var dialog = document.getElementById('cbxConfirm');
    var titleEl = document.getElementById('cbxConfirmTitle');
    var bodyEl = document.getElementById('cbxConfirmBody');
    var okBtn = document.getElementById('cbxConfirmOk');
    var cancelBtn = document.getElementById('cbxConfirmCancel');
    if (!dialog) return;

    var lastFocus = null;
    var settle = null; // pending resolver

    function close(result) {
        dialog.setAttribute('hidden', '');
        backdrop.setAttribute('hidden', '');
        document.removeEventListener('keydown', onKeydown, true);
        var resolve = settle; settle = null;
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
        lastFocus = null;
        if (resolve) resolve(result === true);
    }

    function onKeydown(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(false); return; }
        if (e.key === 'Enter') { e.preventDefault(); close(true); return; }
        if (e.key === 'Tab') {
            // Trap focus between the two buttons.
            e.preventDefault();
            var next = (document.activeElement === okBtn) ? cancelBtn : okBtn;
            next.focus();
        }
    }

    function open(opts) {
        opts = opts || {};
        titleEl.textContent = opts.title || 'Are you sure?';
        bodyEl.textContent = opts.body || 'This action cannot be undone.';
        okBtn.textContent = opts.confirmLabel || 'Confirm';
        okBtn.className = 'cbx-btn cbx-btn--' + (opts.variant === 'primary' ? 'primary' : 'destructive') + ' cbx-btn--sm';
        lastFocus = document.activeElement;
        backdrop.removeAttribute('hidden');
        dialog.removeAttribute('hidden');
        document.addEventListener('keydown', onKeydown, true);
        okBtn.focus();
        return new Promise(function (resolve) { settle = resolve; });
    }

    okBtn.addEventListener('click', function () { close(true); });
    cancelBtn.addEventListener('click', function () { close(false); });
    backdrop.addEventListener('click', function () { close(false); });

    // Programmatic API.
    window.cboxConfirm = open;

    function optsFrom(el) {
        return {
            title: el.getAttribute('data-confirm-title') || 'Are you sure?',
            body: el.getAttribute('data-confirm'),
            confirmLabel: el.getAttribute('data-confirm-label') || 'Confirm',
            variant: el.getAttribute('data-confirm-variant') || 'destructive',
        };
    }

    // Declarative: guarded form submit. Capture phase so we run before other submit
    // handlers; re-submit through requestSubmit so native validation + loading state fire.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;
        if (form.dataset.cbxConfirmed === '1') { delete form.dataset.cbxConfirmed; return; }
        e.preventDefault();
        var submitter = e.submitter || form.querySelector('[type="submit"]');
        open(optsFrom(form)).then(function (ok) {
            if (!ok) return;
            form.dataset.cbxConfirmed = '1';
            if (typeof form.requestSubmit === 'function') form.requestSubmit(submitter || undefined);
            else form.submit();
        });
    }, true);

    // Declarative: guarded link / non-submit button.
    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-confirm]') : null;
        if (!el || el.tagName === 'FORM') return;
        if (el.closest('form')) return; // submit buttons are handled by the submit guard.
        if (el.dataset.cbxConfirmed === '1') { delete el.dataset.cbxConfirmed; return; }
        e.preventDefault();
        open(optsFrom(el)).then(function (ok) {
            if (!ok) return;
            if (el.tagName === 'A' && el.href) { window.location = el.href; return; }
            el.dataset.cbxConfirmed = '1';
            el.click();
        });
    }, true);
})();
</script>
