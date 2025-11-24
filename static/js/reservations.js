(function () {
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

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
  function toast(msg, type='info', timeout=3500) {
    const c = ensureToastContainer();
    const t = document.createElement('div');
    const palette = {
      success: 'bg-green text-fg-font',
      error:   'bg-red-600 text-white',
      info:    'bg-fg-light text-fg-font',
      warn:    'bg-pink text-fg-font',
    };
    t.className = `rounded-xl shadow-lg px-4 py-3 text-sm ${palette[type]||palette.info} transition-opacity`;
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; setTimeout(() => t.remove(), 300); }, timeout);
  }

  async function cancelReservation(id, csrf) {
    const res = await fetch('api/cancel_reservation.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=UTF-8', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ id, csrf })
    });
    let data = null;
    try { data = await res.json(); } catch {}
    if (!res.ok || !data || data.ok === false) {
      const msg = (data && data.error) ? data.error : `Klaida (HTTP ${res.status})`;
      throw new Error(msg);
    }
    return data;
  }

  const cancelModal   = $('#cancel-modal');
  const cancelOverlay = $('#cancel-overlay');
  const cancelClose   = $('#cancel-close');
  const cancelNo      = $('#cancel-no');
  const cancelYes     = $('#cancel-yes');
  const cancelText    = $('#cancel-text');
  const cancelDetails = $('#cancel-details');
  const cancelError   = $('#cancel-error');

  const show = (el) => el && el.classList.remove('hidden');
  const hide = (el) => el && el.classList.add('hidden');

  let pending = { id: null, csrf: null, row: null, button: null };

  function openCancelModal({ id, csrf, row, button }) {
    pending = { id, csrf, row, button };
    if (cancelText) cancelText.textContent = 'Ar tikrai nori atšaukti šią rezervaciją?';
    if (cancelDetails) {
      const cells = row ? row.querySelectorAll('td') : null;
      const who   = cells && cells[1] ? cells[1].textContent.trim() : '';
      const whenS = cells && cells[4] ? cells[4].textContent.trim() : '';
      const whenE = cells && cells[5] ? cells[5].textContent.trim() : '';
      cancelDetails.textContent = `#${id} — ${who} • ${whenS}${whenE ? '–'+whenE : ''}`;
    }
    hide(cancelError);
    show(cancelModal);
    cancelYes?.focus();
  }

  function closeCancelModal() {
    hide(cancelModal);
    pending = { id: null, csrf: null, row: null, button: null };
  }

  cancelOverlay?.addEventListener('click', closeCancelModal);
  cancelClose?.addEventListener('click', closeCancelModal);
  cancelNo?.addEventListener('click', closeCancelModal);

  cancelYes?.addEventListener('click', async () => {
    if (!pending.id || !pending.csrf) return;
    cancelYes.disabled = true;
    cancelNo && (cancelNo.disabled = true);
    try {
      const out = await cancelReservation(pending.id, pending.csrf);
      toast(`Rezervacija #${out.id} atšaukta`, 'success');

      if (pending.row) {
        const statusChip = pending.row.querySelector('td:nth-child(7) .status-chip');
        if (statusChip) {
          statusChip.textContent = out.statusas;
          statusChip.className = 'status-chip chip-ATMESTA';
        }
        const actions = pending.row.querySelector('td:last-child');
        if (actions) actions.innerHTML = '<span class="text-fg-font/50 text-xs">—</span>';
      }

      closeCancelModal();
    } catch (err) {
      if (cancelError) { cancelError.textContent = err.message || 'Nepavyko atšaukti'; show(cancelError); }
    } finally {
      cancelYes.disabled = false;
      cancelNo && (cancelNo.disabled = false);
    }
  });

  function bindCancelButtons(){
    $$('.js-cancel').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const id = parseInt(btn.getAttribute('data-id'), 10);
        const csrf = btn.getAttribute('data-csrf') || window.__RESV_CSRF__;
        if (!Number.isInteger(id) || id <= 0) return;
        openCancelModal({ id, csrf, row: btn.closest('tr'), button: btn });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindCancelButtons, { once: true });
  } else {
    bindCancelButtons();
  }
})();
