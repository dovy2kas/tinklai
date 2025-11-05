(function () {
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const show = (el) => { if (!el) return; el.classList.remove('hidden'); el.classList.remove('modal-hidden'); };
  const hide = (el) => { if (!el) return; el.classList.add('hidden'); el.classList.add('modal-hidden'); };

  function ensureToastContainer() {
    let c = $('#toast-container');
    if (!c) {
      c = document.createElement('div');
      c.id = 'toast-container';
      c.setAttribute('aria-live', 'polite');
      c.className = 'fixed top-4 right-4 z-[9999] space-y-2';
      document.body.appendChild(c);
    }
    return c;
  }
  function toast(message, type = 'info', timeoutMs = 3500) {
    const c = ensureToastContainer();
    const t = document.createElement('div');
    const palette = {
      success: 'bg-green text-fg-font',
      error:   'bg-red-600 text-white',
      info:    'bg-fg-light text-fg-font',
      warn:    'bg-pink text-fg-font',
    };
    t.className = `rounded-xl shadow-lg px-4 py-3 text-sm ${palette[type] || palette.info} transition-opacity`;
    t.textContent = message;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, timeoutMs);
  }

  async function sendUpdate(id, action, comment, csrf) {
    const res = await fetch('api/update_reservation_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=UTF-8', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ id, action, comment, csrf })
    });
    let data = null;
    try { data = await res.json(); } catch {}
    if (!res.ok || !data || data.ok === false) {
      const msg = (data && data.error) ? data.error : `Klaida (HTTP ${res.status})`;
      throw new Error(msg);
    }
    return data;
  }

  const modal = $('#manage-modal');
  const overlay = $('#manage-overlay');
  const btnClose = $('#manage-close');
  const btnCancel = $('#manage-cancel');
  const btnSubmit = $('#manage-submit');
  const txt = $('#manage-text');
  const textarea = $('#manage-comment');
  const err = $('#manage-error');

  let state = { id: null, action: null, row: null };

  function openModal(id, action, row) {
    state = { id, action, row };
    if (txt) txt.textContent = action === 'confirm' ? 'Patvirtinti šią rezervaciją?' : 'Atmesti šią rezervaciją?';
    if (textarea) textarea.value = '';
    if (err) { err.textContent = ''; err.classList.add('hidden'); }
    show(modal);
    textarea && textarea.focus();
  }
  function closeModal() { hide(modal); state = { id: null, action: null, row: null }; }

  overlay?.addEventListener('click', closeModal);
  btnClose?.addEventListener('click', closeModal);
  btnCancel?.addEventListener('click', closeModal);

  btnSubmit?.addEventListener('click', async () => {
    if (!state.id || !state.action) return;
    btnSubmit.disabled = true;
    btnCancel && (btnCancel.disabled = true);
    try {
      const out = await sendUpdate(state.id, state.action, textarea ? textarea.value.trim() : '', window.__CSRFM__);
      const chip = state.row?.querySelector('.js-status');
      if (chip) {
        chip.textContent = out.statusas;
        chip.className = `status-chip chip-${out.statusas} js-status`;
      }
      const notes = state.row?.querySelector('.js-notes');
      if (notes) {
        notes.innerHTML = (out.pastabos || '').replace(/\n/g, '<br>');
      }
      const actionsCell = state.row?.querySelector('td:last-child');
      if (actionsCell) {
        if (out.statusas === 'LAUKIA' || out.statusas === 'PATVIRTINTA') {
          actionsCell.innerHTML = actionsCell.innerHTML;
        } else {
          actionsCell.innerHTML = '<span class="text-fg-font/50 text-xs">—</span>';
        }
      }
      closeModal();
      toast('Būsena atnaujinta', 'success');
    } catch (e) {
      if (err) { err.textContent = e.message || 'Nepavyko atnaujinti'; err.classList.remove('hidden'); }
    } finally {
      btnSubmit.disabled = false;
      btnCancel && (btnCancel.disabled = false);
    }
  });

  function bindRows() {
    $$('.js-open-modal').forEach(btn => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const id = parseInt(btn.getAttribute('data-id'), 10);
        const action = btn.getAttribute('data-action');
        const row = btn.closest('tr');
        if (!id || !action || !row) return;
        openModal(id, action, row);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindRows, { once: true });
  } else {
    bindRows();
  }
})();
