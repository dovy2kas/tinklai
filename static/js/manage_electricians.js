(function () {
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const show = (el) => { if (!el) return; el.classList.remove('hidden'); };
  const hide = (el) => { if (!el) return; el.classList.add('hidden'); };

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

  async function sendUpdate(id, action, csrf) {
    const res = await fetch('api/update_electrician_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=UTF-8', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ id, action, csrf })
    });
    let data = null;
    try { data = await res.json(); } catch {}
    if (!res.ok || !data || data.ok === false) {
      const msg = (data && data.error) ? data.error : `Klaida (HTTP ${res.status})`;
      throw new Error(msg);
    }
    return data;
  }

  const modal = $('#adm-modal');
  const overlay = $('#adm-overlay');
  const btnClose = $('#adm-close');
  const btnCancel = $('#adm-cancel');
  const btnSubmit = $('#adm-submit');
  const txt = $('#adm-text');
  const err = $('#adm-error');

  let state = { id: null, action: null, row: null };

  function openModal(id, action, row) {
    state = { id, action, row };
    txt && (txt.textContent = action === 'approve'
      ? 'Patvirtinti elektriko profilį?'
      : 'Panaikinti patvirtinimą šiam profiliui?');
    if (err) { err.textContent = ''; err.classList.add('hidden'); }
    show(modal);
  }
  function closeModal(){ hide(modal); state = { id:null, action:null, row:null }; }

  overlay?.addEventListener('click', closeModal);
  btnClose?.addEventListener('click', closeModal);
  btnCancel?.addEventListener('click', closeModal);

  btnSubmit?.addEventListener('click', async () => {
    if (!state.id || !state.action) return;
    btnSubmit.disabled = true;
    btnCancel && (btnCancel.disabled = true);
    try {
      const out = await sendUpdate(state.id, state.action, window.__CSRFM__);
      const chip = state.row?.querySelector('.js-status');
      if (chip) {
        chip.textContent = out.statusas;
        chip.className = `status-chip chip-${out.statusas} js-status`;
      }
      const actionsCell = state.row?.querySelector('td:last-child');
      if (actionsCell) {
        if (out.statusas === 'PATVIRTINTAS') {
          actionsCell.innerHTML =
            `<button class="js-admin-action px-3 py-1.5 rounded bg-red-600 text-white hover:bg-red-700 transition" data-id="${out.id}" data-action="unapprove">Panaikinti patvirtinimą</button>`;
        } else {
          actionsCell.innerHTML =
            `<button class="js-admin-action px-3 py-1.5 rounded bg-green text-fg-font hover:bg-green/80 transition" data-id="${out.id}" data-action="approve">Patvirtinti</button>`;
        }
        bindRow(actionsCell.closest('tr'));
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

  function bindRow(row) {
    row?.querySelectorAll('.js-admin-action').forEach(btn => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const id = parseInt(btn.getAttribute('data-id'), 10);
        const action = btn.getAttribute('data-action');
        if (!id || !action) return;
        openModal(id, action, btn.closest('tr'));
      });
    });
  }

  function bindAll() {
    $$('.js-admin-action').forEach(btn => {
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
    document.addEventListener('DOMContentLoaded', bindAll, { once: true });
  } else {
    bindAll();
  }
})();
