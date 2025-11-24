(function () {
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const show = (el) => el && el.classList.remove('hidden');
  const hide = (el) => el && el.classList.add('hidden');
  const isLoggedIn = () => document.body.getAttribute('data-logged-in') === '1';

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
  function createToast(message, type = 'info', timeoutMs = 4500) {
    const c = ensureToastContainer();
    const toast = document.createElement('div');
    const palette = {
      success: 'bg-green text-fg-font',
      error:   'bg-red-600 text-white',
      info:    'bg-fg-light text-fg-font',
      warn:    'bg-pink text-fg-font',
    };
    toast.className =
      `rounded-xl shadow-lg px-4 py-3 text-sm ${palette[type] || palette.info} transition-opacity`;
    toast.textContent = message;
    c.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = '0';
      setTimeout(() => toast.remove(), 300);
    }, timeoutMs);
  }

  function todayISO(){
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function pickServiceId(svcObj) {
    const candidates = ['id', 'paslauga', 'service_id', 'sid', 'pasiula_id'];
    for (const k of candidates) {
      const raw = svcObj && svcObj[k];
      if (raw === null || raw === undefined) continue;
      const n = Number.parseInt(String(raw).trim(), 10);
      if (Number.isInteger(n) && n > 0) return n;
    }
    return null;
  }

  function init() {
    const svcModal   = $('#svc-modal');
    const svcOverlay = svcModal?.firstElementChild;
    const svcClose   = $('#svc-close');
    const svcOk      = $('#svc-ok');
    const svcTitle   = $('#svc-title');
    const svcLoading = $('#svc-loading');
    const svcContent = $('#svc-content');
    const svcError   = $('#svc-error');

    function openSvcModal(){ show(svcModal); }
    function closeSvcModal(){ hide(svcModal); }

    function renderServices(data) {
      if (svcTitle) {
        const n = data.n || {};
        const parts = [n.vardas, n.pavarde].filter(Boolean).join(' ');
        svcTitle.textContent = "Paslaugos";
      }
      if (!svcContent) return;
      svcContent.innerHTML = '';
      const list = Array.isArray(data.paslaugos) ? data.paslaugos : [];
      if (!list.length) {
        svcContent.innerHTML = '<div class="p-6 text-fg-font/70">Elektrikas kol kas neįtraukė paslaugų.</div>';
        return;
      }
      list.forEach((svc) => {
        const row = document.createElement('div');
        row.className = 'p-4';
        row.innerHTML = `
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-base font-semibold text-fg-font">${svc.pavadinimas}</div>
              ${svc.aprasas ? `<div class="text-sm text-fg-font/80 mt-1">${svc.aprasas}</div>` : ''}
            </div>
            <div class="text-right whitespace-nowrap text-sm">
              <div class="text-base font-semibold text-fg-font">${Number(svc.kaina_bazine).toFixed(2)} €</div>
              <div class="text-fg-font/70">${parseInt(svc.tipine_trukme_min,10)} min</div>
            </div>
          </div>
        `;
        svcContent.appendChild(row);
      });
    }

    async function loadServices(eid) {
      hide(svcError); hide(svcContent); show(svcLoading);
      try {
        const res = await fetch(`api/services.php?elektrikas=${encodeURIComponent(eid)}`, {
          headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        renderServices(data);
        show(svcContent);
      } catch {
        if (svcError) { svcError.textContent = 'Nepavyko įkelti paslaugų. Pabandykite dar kartą.'; show(svcError); }
      } finally {
        hide(svcLoading);
      }
    }

    $$('.js-view-services').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const eid = btn.getAttribute('data-elektrikas');
        if (!eid) return;
        openSvcModal();
        loadServices(eid);
      });
    });
    [svcOverlay, svcClose, svcOk].forEach((el) => el?.addEventListener('click', (e) => {
      e.preventDefault(); closeSvcModal();
    }));

    const reviewsModal   = $('#reviews-modal');
    const reviewsOverlay = reviewsModal?.firstElementChild;
    const reviewsClose   = $('#reviews-close');
    const reviewsOk      = $('#reviews-ok');
    const reviewsTitle   = $('#reviews-title');
    const reviewsLoading = $('#reviews-loading');
    const reviewsError   = $('#reviews-error');
    const reviewsContent = $('#reviews-content');

    function openReviewsModal(eid, name) {
      if (reviewsTitle) {
        reviewsTitle.textContent = name
          ? `Atsiliepimai – ${name}`
          : 'Atsiliepimai';
      }
      if (reviewsContent) {
        reviewsContent.innerHTML = '';
        reviewsContent.classList.add('hidden');
      }
      reviewsError && (reviewsError.textContent = '');
      reviewsError && reviewsError.classList.add('hidden');
      reviewsLoading && reviewsLoading.classList.remove('hidden');
      show(reviewsModal);
      loadReviews(eid);
    }

    function closeReviewsModal() {
      hide(reviewsModal);
    }

    function renderReviews(data) {
      if (!reviewsContent) return;
      reviewsContent.innerHTML = '';

      const list = Array.isArray(data.reviews) ? data.reviews : [];

      if (!list.length) {
        reviewsContent.innerHTML =
          '<div class="p-6 text-sm text-fg-font/70">Šis elektrikas dar neturi atsiliepimų.</div>';
        reviewsContent.classList.remove('hidden');
        return;
      }

      list.forEach((rev) => {
        const wrap = document.createElement('div');
        wrap.className = 'p-4 space-y-1';

        const header = document.createElement('div');
        header.className = 'flex items-start justify-between gap-3';

        const left = document.createElement('div');
        left.className = 'space-y-1';

        const authorEl = document.createElement('div');
        authorEl.className = 'text-sm font-semibold text-fg-font';
        authorEl.textContent = rev.author || 'Anonimas';

        const metaEl = document.createElement('div');
        metaEl.className = 'text-xs text-fg-font/70';
        const dateTxt = rev.created ? rev.created.slice(0, 16).replace('T',' ') : '';
        const ratingTxt = `Įvertinimas: ${rev.rating}/5`;
        const serviceTxt = rev.service ? ` • Paslauga: ${rev.service}` : '';
        metaEl.textContent = `${ratingTxt}${serviceTxt}${dateTxt ? ' • ' + dateTxt : ''}`;

        left.appendChild(authorEl);
        left.appendChild(metaEl);

        header.appendChild(left);

        wrap.appendChild(header);

        if (rev.comment) {
          const body = document.createElement('div');
          body.className = 'text-sm text-fg-font/90 mt-1 whitespace-pre-line';
          body.textContent = rev.comment;
          wrap.appendChild(body);
        }

        reviewsContent.appendChild(wrap);
      });

      reviewsContent.classList.remove('hidden');
    }

    async function loadReviews(eid) {
      reviewsError && reviewsError.classList.add('hidden');
      try {
        const res = await fetch(`api/reviews.php?elektrikas=${encodeURIComponent(eid)}`, {
          headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (!data.ok) {
          if (reviewsError) {
            reviewsError.textContent = data.error || 'Nepavyko įkelti atsiliepimų.';
            reviewsError.classList.remove('hidden');
          }
          return;
        }
        renderReviews(data);
      } catch (e) {
        if (reviewsError) {
          reviewsError.textContent = 'Nepavyko įkelti atsiliepimų. Pabandykite dar kartą.';
          reviewsError.classList.remove('hidden');
        }
      } finally {
        reviewsLoading && reviewsLoading.classList.add('hidden');
      }
    }

    $$('.js-view-reviews').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const eid  = btn.getAttribute('data-elektrikas');
        const name = btn.getAttribute('data-elektrikas-name') || '';
        if (!eid) return;
        openReviewsModal(eid, name);
      });
    });

    [reviewsOverlay, reviewsClose, reviewsOk].forEach((el) =>
      el?.addEventListener('click', (e) => {
        e.preventDefault();
        closeReviewsModal();
      })
    );

    reviewsModal?.addEventListener('click', (e) => {
      if (e.target === reviewsModal) closeReviewsModal();
    });

    const resvModal   = $('#resv-modal');
    const resvOverlay = resvModal?.firstElementChild;
    const resvClose   = $('#resv-close');
    const resvCancel  = $('#resv-cancel');
    const resvTitle   = $('#resv-title');
    const resvDate    = $('#resv-date');
    const resvLoading = $('#resv-loading');
    const resvError   = $('#resv-error');
    const resvSlots   = $('#resv-slots');
    const resvNext    = $('#resv-next');
    const resvService = $('#resv-service');

    if (resvModal) {
      let currentEid = null;
      let selectedSlot = null;

      const getSelectedServiceId = () => {
        const raw = (resvService?.value ?? '').trim();
        const n = Number.parseInt(raw, 10);
        return Number.isInteger(n) && n > 0 ? n : null;
      };
      const canProceed = () => Boolean(selectedSlot && getSelectedServiceId());
      const syncNextState = () => { if (resvNext) resvNext.disabled = !canProceed(); };

      function openResvModal(eid) {
        currentEid = eid;
        selectedSlot = null;
        if (resvTitle) resvTitle.textContent = 'Pasirinkite laiką';
        if (resvDate) resvDate.value = todayISO();
        if (resvSlots) resvSlots.innerHTML = '';
        hide(resvError);
        syncNextState();
        show(resvModal);
        loadServicesForResv(eid);
        loadSlots();
      }

      function closeResvModal(){ hide(resvModal); }

      function renderSlots(list){
        if (!resvSlots) return;
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
          btn.addEventListener('click', (ev) => {
            ev.preventDefault();
            selectedSlot = slot;
            $$('#resv-slots button').forEach(b => b.classList.remove('ring','ring-2','ring-purple'));
            btn.classList.add('ring','ring-2','ring-purple');
            syncNextState();
          });
          resvSlots.appendChild(btn);
        });
      }

      async function loadSlots(){
        if (!resvDate) return;
        resvNext && (resvNext.disabled = true);
        selectedSlot = null;

        const date = resvDate.value;
        if (resvTitle) resvTitle.textContent = `Pasirinkite laiką – ${date}`;
        hide(resvError);
        show(resvLoading);
        if (resvSlots) resvSlots.innerHTML = '';

        try {
          const url = `api/availability.php?elektrikas=${encodeURIComponent(currentEid)}&date=${encodeURIComponent(date)}`;
          const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
          if (!res.ok) throw new Error(`HTTP ${res.status}`);
          const data = await res.json();
          renderSlots(data.slots || []);
        } catch {
          if (resvError) { resvError.textContent = 'Nepavyko įkelti laikų. Pabandykite kitą datą arba vėliau.'; show(resvError); }
        } finally {
          hide(resvLoading);
          syncNextState();
        }
      }

      async function loadServicesForResv(eid){
        if (!resvService) return;
        resvService.innerHTML = `<option value="" class="text-orange">— Kraunama… —</option>`;
        try {
          const res = await fetch(`api/services.php?elektrikas=${encodeURIComponent(eid)}`, {
            headers: { 'Accept': 'application/json' }
          });
          if (!res.ok) throw new Error(`HTTP ${res.status}`);
          const data = await res.json();

          const list = Array.isArray(data.paslaugos) ? data.paslaugos : [];
          const mapped = list
            .map((s) => {
              const id = pickServiceId(s);
              return id ? { id, ...s } : null;
            })
            .filter(Boolean);

          if (!mapped.length) {
            resvService.innerHTML = `<option value="" class="text-red">Šis elektrikas neturi paslaugų</option>`;
            syncNextState();
            return;
          }

          resvService.innerHTML =
            `<option value="">— Pasirink —</option>` +
            mapped.map(s =>
              `<option value="${String(s.id)}">
                 ${s.pavadinimas} — ${Number(s.kaina_bazine).toFixed(2)} € (${parseInt(s.tipine_trukme_min,10)} min)
               </option>`
            ).join('');

          if (mapped.length === 1) {
            resvService.value = String(mapped[0].id);
          }
        } catch {
          resvService.innerHTML = `<option value="" class="text-red">Nepavyko įkelti paslaugų</option>`;
        } finally {
          syncNextState();
        }
      }

      $$('.js-reserve-link').forEach((link) => {
        link.addEventListener('click', (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          const eid = link.getAttribute('data-elektrikas');
          if (!eid) return;
          openResvModal(eid);
        });
      });

      resvDate?.addEventListener('change', (e) => { e.preventDefault(); loadSlots(); });
      resvService?.addEventListener('change', () => { syncNextState(); });
      [resvOverlay, resvClose, resvCancel].forEach((el) => el?.addEventListener('click', (e) => {
        e.preventDefault(); closeResvModal();
      }));

      resvNext?.addEventListener('click', async (e) => {
        e.preventDefault();

        if (!selectedSlot) {
          if (resvError) { resvError.textContent = 'Pasirinkite laiką.'; show(resvError); }
          return;
        }
        const paslaugaId = getSelectedServiceId();
        if (!paslaugaId) {
          if (resvError) { resvError.textContent = 'Pasirinkite paslaugą.'; show(resvError); }
          return;
        }

        if (!isLoggedIn()) {
          if (resvError) { resvError.textContent = 'Sesija baigėsi. Prisijunkite ir bandykite dar kartą.'; show(resvError); }
          return;
        }

        const date = resvDate?.value;

        resvNext.disabled = true;
        resvNext.classList.add('opacity-60','pointer-events-none');

        try {
          const payload = {
            elektrikas: Number.parseInt(String(currentEid), 10),
            paslauga: paslaugaId,
            date,
            start: selectedSlot,
            pastabos: ''
          };

          const res = await fetch('api/reserve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=UTF-8', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
          });

          let data = null;
          try { data = await res.json(); } catch {}

          if (res.status === 401) {
            if (resvError) { resvError.textContent = 'Sesija baigėsi. Prisijunkite ir bandykite dar kartą.'; show(resvError); }
            return;
          }

          if (!res.ok || !data || data.ok === false) {
            const msg = (data && data.error) ? data.error : `Rezervacijos klaida (HTTP ${res.status})`;
            if (resvError) { resvError.textContent = msg; show(resvError); }
            return;
          }

          closeResvModal();
          const startTxt = data.pradzia ? data.pradzia.slice(0,16).replace('T',' ') : `${date} ${selectedSlot}`;
          const endTxt = data.pabaiga ? data.pabaiga.slice(11,16) : '';
          createToast(`Rezervacija sukurta! #${data.id} ${startTxt}${endTxt ? '–'+endTxt : ''}`, 'success');

        } catch {
          if (resvError) { resvError.textContent = 'Nepavyko sukurti rezervacijos. Pabandykite dar kartą.'; show(resvError); }
        } finally {
          resvNext.classList.remove('opacity-60','pointer-events-none');
          syncNextState();
        }
      });
    }

    const reviewModal   = $('#review-modal');
    const reviewName    = $('#review-electrician');
    const reviewRating  = $('#review-rating');
    const reviewComment = $('#review-comment');
    const reviewError   = $('#review-error');
    const reviewSuccess = $('#review-success');
    const reviewClose   = $('#review-close');
    const reviewCancel  = $('#review-cancel');
    const reviewSave    = $('#review-save');

    if (reviewModal) {
      let currentElektrikasId = null;

      function markButtonsAsReviewed(eid) {
        $$('.js-review-link[data-elektrikas="' + eid + '"]').forEach((btn) => {
          btn.disabled = true;
          btn.dataset.reviewed = '1';
          btn.classList.add('opacity-60', 'cursor-not-allowed');
          btn.classList.remove('hover:bg-comment');
          btn.textContent = 'Atsiliepimas pateiktas';
        });
      }

      function openReviewModal(eid, name) {
        currentElektrikasId = eid;
        if (reviewName) reviewName.textContent = name || ('Elektrikas #' + eid);
        if (reviewRating) reviewRating.value = '5';
        if (reviewComment) reviewComment.value = '';
        if (reviewError) { reviewError.classList.add('hidden'); reviewError.textContent = ''; }
        if (reviewSuccess) { reviewSuccess.classList.add('hidden'); reviewSuccess.textContent = ''; }
        show(reviewModal);
      }

      function closeReviewModal() {
        hide(reviewModal);
      }

      $$('.js-review-link').forEach((btn) => {
        const eid = parseInt(btn.getAttribute('data-elektrikas') || '0', 10);
        const already = btn.getAttribute('data-reviewed') === '1';

        if (already) {
          markButtonsAsReviewed(eid);
        }

        btn.addEventListener('click', () => {
          if (!isLoggedIn()) {
            window.location.href = 'login.php';
            return;
          }
          if (btn.disabled || btn.getAttribute('data-reviewed') === '1') {
            createToast('Jau esate palikę atsiliepimą šiam elektrikui.', 'info');
            return;
          }
          const id  = parseInt(btn.getAttribute('data-elektrikas') || '0', 10);
          const name = btn.getAttribute('data-elektrikas-name') || '';
          if (!id) return;
          openReviewModal(id, name);
        });
      });

      reviewClose?.addEventListener('click', closeReviewModal);
      reviewCancel?.addEventListener('click', closeReviewModal);

      reviewModal.addEventListener('click', (e) => {
        if (e.target === reviewModal) closeReviewModal();
      });

    reviewSave?.addEventListener('click', () => {
      if (!currentElektrikasId) return;

      const rating = parseInt(reviewRating?.value || '5', 10) || 5;
      const comment = (reviewComment?.value || '').trim();

      if (!comment) {
        if (reviewError) {
          reviewError.textContent = 'Prašome parašyti bent trumpą komentarą.';
          reviewError.classList.remove('hidden');
        }
        reviewSuccess && reviewSuccess.classList.add('hidden');
        return;
      }

      reviewError && reviewError.classList.add('hidden');
      reviewSuccess && reviewSuccess.classList.add('hidden');

      const csrf = window.__REVIEW_CSRF__ || '';

      const params = new URLSearchParams();
      params.set('elektrikas', String(currentElektrikasId));
      params.set('rating', String(rating));
      params.set('comment', comment);
      params.set('csrf', csrf);

      fetch('api/review_submit.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: params.toString()
      })
      .then((res) => res.json().catch(() => ({})))
      .then((data) => {
        if (!data || !data.ok) {
          if (data && (data.code === 'ALREADY_REVIEWED' || /jau esate palikę/i.test(data.error || ''))) {
            if (reviewError) {
              reviewError.textContent = data.error || 'Jau esate palikę atsiliepimą šiam elektrikui.';
              reviewError.classList.remove('hidden');
            }
            markButtonsAsReviewed(currentElektrikasId);
            return;
          }

          if (reviewError) {
            reviewError.textContent = data && data.error ? data.error : 'Nepavyko išsaugoti atsiliepimo.';
            reviewError.classList.remove('hidden');
          }
          reviewSuccess && reviewSuccess.classList.add('hidden');
          return;
        }

        closeReviewModal();
        markButtonsAsReviewed(currentElektrikasId);
        createToast('Ačiū! Tavo atsiliepimas išsaugotas.', 'success');
      })
      .catch(() => {
        if (reviewError) {
          reviewError.textContent = 'Įvyko klaida jungiantis prie serverio.';
          reviewError.classList.remove('hidden');
        }
        reviewSuccess && reviewSuccess.classList.add('hidden');
      });
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();