(function () {
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const show = (el) => el.classList.remove('hidden');
  const hide = (el) => el.classList.add('hidden');

  const isLoggedIn = document.body.getAttribute('data-logged-in') === '1';

  const svcModal = $('#svc-modal');
  const svcOverlay = svcModal?.firstElementChild;
  const svcClose = $('#svc-close');
  const svcOk = $('#svc-ok');
  const svcTitle = $('#svc-title');
  const svcLoading = $('#svc-loading');
  const svcContent = $('#svc-content');
  const svcError = $('#svc-error');
  const svcReserve = $('#svc-reserve');

  function openSvcModal(){ show(svcModal); }
  function closeSvcModal(){ hide(svcModal); }

  function renderServices(data) {
    svcTitle.textContent = `Paslaugos – ${data.n.vardas} ${data.n.pavarde} (${data.n.miestas})`;
    svcReserve.href = `reserve.php?elektrikas=${data.elektrikas}`;
    show(svcReserve);

    svcContent.innerHTML = '';
    if (!data.paslaugos || !data.paslaugos.length) {
      svcContent.innerHTML = '<div class="p-6 text-fg-font/70">Elektrikas kol kas neįtraukė paslaugų.</div>';
    } else {
      data.paslaugos.forEach((svc) => {
        const row = document.createElement('div');
        row.className = 'p-4';
        row.innerHTML = `
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-base font-semibold text-fg-font">${svc.pavadinimas}</div>
              ${svc.aprasas ? `<div class="text-sm text-fg-font/80 mt-1">${svc.aprasas}</div>` : ''}
            </div>
            <div class="text-right whitespace-nowrap text-sm">
              <div class="font-semibold">${Number(svc.kaina_bazine).toFixed(2)} €</div>
              <div class="text-fg-font/70">${parseInt(svc.tipine_trukme_min)} min</div>
            </div>
          </div>
        `;
        svcContent.appendChild(row);
      });
    }
  }

  async function loadServices(eid) {
    hide(svcError); hide(svcContent); show(svcLoading); hide(svcReserve);
    try {
      const res = await fetch(`services_api.php?elektrikas=${encodeURIComponent(eid)}`, { headers: { 'Accept': 'application/json' } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      renderServices(data);
      show(svcContent);
    } catch (e) {
      svcError.textContent = 'Nepavyko įkelti paslaugų. Pabandykite dar kartą.';
      show(svcError);
    } finally {
      hide(svcLoading);
    }
  }

  $$('.js-view-services').forEach((btn) => {
    btn.addEventListener('click', () => {
      const eid = btn.getAttribute('data-elektrikas');
      openSvcModal();
      loadServices(eid);
    });
  });
  [svcOverlay, svcClose, svcOk].forEach((el) => el?.addEventListener('click', closeSvcModal));

  const resvModal = $('#resv-modal');
  const resvOverlay = resvModal?.firstElementChild;
  const resvClose = $('#resv-close');
  const resvCancel = $('#resv-cancel');
  const resvTitle = $('#resv-title');
  const resvDate = $('#resv-date');
  const resvLoading = $('#resv-loading');
  const resvError = $('#resv-error');
  const resvSlots = $('#resv-slots');
  const resvForm = $('#resv-form');
  const resvNext = $('#resv-next');
  const resvElektrikas = $('#resv-elektrikas');
  const resvDateHidden = $('#resv-date-hidden');
  const resvStartHidden = $('#resv-start-hidden');

  let currentEid = null;
  let selectedSlot = null;

  function openResvModal(eid) {
    currentEid = eid;
    selectedSlot = null;
    resvElektrikas.value = eid;
    resvTitle.textContent = 'Pasirinkite laiką';
    resvDate.value = todayISO();
    resvSlots.innerHTML = '';
    hide(resvError);
    resvNext.disabled = true;
    show(resvModal);
    loadSlots();
  }
  function closeResvModal(){ hide(resvModal); }

  function todayISO(){
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function renderSlots(list){
    resvSlots.innerHTML = '';
    if (!list || !list.length) {
      resvSlots.innerHTML = '<div class="col-span-full text-sm text-fg-font/70">Šią dieną laisvų laikų nėra.</div>';
      return;
    }
    list.forEach((slot) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'px-3 py-2 rounded bg-fg-light hover:bg-comment text-sm text-fg-font transition';
      btn.textContent = slot;
      btn.addEventListener('click', () => {
        selectedSlot = slot;
        $$('#resv-slots button').forEach(b => b.classList.remove('ring','ring-2','ring-purple'));
        btn.classList.add('ring','ring-2','ring-purple');
        resvNext.disabled = false;
      });
      resvSlots.appendChild(btn);
    });
  }

  async function loadSlots(){
    resvNext.disabled = true;
    selectedSlot = null;
    const date = resvDate.value;
    resvTitle.textContent = `Pasirinkite laiką – ${date}`;
    hide(resvError);
    resvLoading.classList.remove('hidden');
    resvSlots.innerHTML = '';

    try {
      const url = `api/availability.php?elektrikas=${encodeURIComponent(currentEid)}&date=${encodeURIComponent(date)}`;
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      renderSlots(data.slots || []);
    } catch (e) {
      resvError.textContent = 'Nepavyko įkelti laikų. Pabandykite kitą datą arba vėliau.';
      resvError.classList.remove('hidden');
    } finally {
      resvLoading.classList.add('hidden');
    }
  }

  $$('.js-reserve-link').forEach((link) => {
    link.addEventListener('click', (ev) => {
      const eid = link.getAttribute('data-elektrikas');
      openResvModal(eid);
    });
  });

  resvDate?.addEventListener('change', loadSlots);
  [resvOverlay, resvClose, resvCancel].forEach((el) => el?.addEventListener('click', closeResvModal));

  resvNext?.addEventListener('click', () => {
    if (!selectedSlot) return;
    const date = resvDate.value;
    const start = selectedSlot;

    if (!isLoggedIn) {
      const target = `reserve.php?elektrikas=${encodeURIComponent(currentEid)}&date=${encodeURIComponent(date)}&start=${encodeURIComponent(start)}`;
      window.location.href = `login.php?redirect=${encodeURIComponent(target)}`;
      return;
    }

    resvDateHidden.value = date;
    resvStartHidden.value = start;
    resvForm.submit();
  });

})();
