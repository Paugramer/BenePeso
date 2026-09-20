(function () {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.addEventListener('DOMContentLoaded', () => {
    const carousel = document.querySelector('[data-barangay-carousel]');
    if (carousel) initializeCarousel(carousel);
    initializeWorkloadModal();
  });

  function initializeCarousel(carousel) {
    const track = carousel.querySelector('.barangay-workload-list');
    const cards = Array.from(carousel.querySelectorAll('[data-barangay-card]'));
    const previous = carousel.querySelector('[data-carousel-previous]');
    const next = carousel.querySelector('[data-carousel-next]');
    const dots = carousel.querySelector('[data-carousel-dots]');
    if (!track || cards.length < 2) {
      carousel.classList.add('is-static');
      return;
    }

    let index = 0;
    let timer = null;
    const visibleCount = () => window.innerWidth <= 620 ? 1 : window.innerWidth <= 1080 ? 2 : 3;
    const pageCount = () => Math.max(1, cards.length - visibleCount() + 1);

    const renderDots = () => {
      dots.replaceChildren();
      for (let i = 0; i < pageCount(); i += 1) {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.setAttribute('aria-label', `Show barangay card ${i + 1}`);
        dot.addEventListener('click', () => goTo(i));
        dots.appendChild(dot);
      }
    };

    const update = () => {
      index = Math.min(index, pageCount() - 1);
      const cardWidth = cards[0].getBoundingClientRect().width;
      const gap = Number.parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap) || 0;
      track.style.transform = `translate3d(-${index * (cardWidth + gap)}px, 0, 0)`;
      Array.from(dots.children).forEach((dot, dotIndex) => dot.classList.toggle('is-active', dotIndex === index));
    };

    const goTo = (nextIndex) => {
      index = (nextIndex + pageCount()) % pageCount();
      update();
      restart();
    };

    const stop = () => {
      if (timer) window.clearInterval(timer);
      timer = null;
    };

    const start = () => {
      stop();
      if (!reduceMotion && !document.hidden) timer = window.setInterval(() => goTo(index + 1), 3800);
    };

    const restart = () => {
      stop();
      start();
    };

    previous.addEventListener('click', () => goTo(index - 1));
    next.addEventListener('click', () => goTo(index + 1));
    carousel.addEventListener('mouseenter', stop);
    carousel.addEventListener('mouseleave', start);
    carousel.addEventListener('focusin', stop);
    carousel.addEventListener('focusout', (event) => { if (!carousel.contains(event.relatedTarget)) start(); });
    document.addEventListener('visibilitychange', () => document.hidden ? stop() : start());
    window.addEventListener('resize', () => { renderDots(); update(); });

    renderDots();
    update();
    start();
  }

  function initializeWorkloadModal() {
    const modal = document.getElementById('barangayWorkloadModal');
    const dataNode = document.getElementById('barangayPendingData');
    if (!modal || !dataNode) return;

    let records = {};
    try { records = JSON.parse(dataNode.textContent || '{}'); } catch (error) { records = {}; }
    const title = modal.querySelector('#workloadModalTitle');
    const summary = modal.querySelector('[data-workload-summary]');
    const list = modal.querySelector('[data-workload-list]');
    const closeButtons = modal.querySelectorAll('[data-workload-close]');
    let returnFocus = null;

    const close = () => {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('workload-modal-open');
      returnFocus?.focus();
    };

    const open = (card) => {
      const barangay = card.dataset.barangay || 'Selected barangay';
      const total = Number.parseInt(card.dataset.pendingTotal || '0', 10);
      const items = Array.isArray(records[barangay]) ? records[barangay] : [];
      title.textContent = barangay;
      summary.textContent = `${total} pending application${total === 1 ? '' : 's'} • showing ${items.length} of up to 5`;
      list.replaceChildren();
      if (!items.length) {
        const empty = document.createElement('div');
        empty.className = 'workload-modal-empty';
        empty.innerHTML = '<i class="ph ph-check-circle"></i><strong>No pending applications</strong><span>This barangay currently has no records awaiting review.</span>';
        list.appendChild(empty);
      } else {
        items.forEach((item) => {
          const link = document.createElement('a');
          const params = new URLSearchParams({ program_name: item.program_name, program_id: item.program_id, approval: 'Pending', search: item.full_name || '' });
          link.href = `${modal.querySelector('.workload-modal-foot a').getAttribute('href')}?${params}`;
          link.className = 'workload-beneficiary-row';
          const icon = document.createElement('span');
          icon.className = 'workload-beneficiary-avatar';
          icon.innerHTML = '<i class="ph ph-user"></i>';
          const copy = document.createElement('span');
          copy.className = 'workload-beneficiary-copy';
          const name = document.createElement('strong');
          name.textContent = item.full_name || 'Unnamed beneficiary';
          const meta = document.createElement('small');
          meta.textContent = `${item.program_name} • ${item.program_code}`;
          copy.append(name, meta);
          const arrow = document.createElement('i');
          arrow.className = 'ph ph-arrow-right';
          link.append(icon, copy, arrow);
          list.appendChild(link);
        });
      }
      returnFocus = card;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('workload-modal-open');
      modal.querySelector('.workload-modal-close').focus();
    };

    document.querySelectorAll('[data-barangay-card]').forEach((card) => card.addEventListener('click', () => open(card)));
    closeButtons.forEach((button) => button.addEventListener('click', close));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modal.classList.contains('is-open')) close(); });
  }
})();
