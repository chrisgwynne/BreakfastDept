(() => {
  const stage = document.querySelector('[data-tt-page-stage]');
  const holder = document.querySelector('[data-tt-page-holder]');
  const viewport = document.querySelector('.tt-page-viewport');
  const controls = document.querySelector('[data-tt-subpage-controls]');
  if (!stage || !holder || !viewport) return;

  const pageHeight = 480;
  const totalPages = Math.max(1, Math.ceil(stage.scrollHeight / pageHeight));
  const pageLabel = document.querySelector('[data-tt-subpage-label]');
  const mastheadPage = document.querySelector('.tt-masthead__page');
  const barSubs = document.querySelectorAll('.tt-bar__sub');
  const pageMatch = (mastheadPage?.textContent || '').match(/P?(\d{3})/);
  const pageNumber = pageMatch ? pageMatch[1] : '---';
  let currentPage = 0;
  let held = totalPages === 1 || document.forms.length > 0;
  let autoTimer = null;

  const scaleStage = () => {
    const scale = Math.min(window.innerWidth / 640, window.innerHeight / 480);
    stage.style.transform = `scale(${scale}) translateY(-${currentPage * pageHeight}px)`;
    holder.style.width = `${640 * scale}px`;
    holder.style.height = `${pageHeight * scale}px`;
    if (controls) controls.style.transform = `translateX(-50%) scale(${scale})`;
  };

  const renderLabel = () => {
    const label = `P${pageNumber}/${String(currentPage + 1).padStart(2, '0')}`;
    if (pageLabel) pageLabel.textContent = `${label} ${held ? 'HOLD' : 'ROTATING'}`;
    if (mastheadPage) mastheadPage.textContent = label;
    barSubs.forEach((sub) => { sub.textContent = `${currentPage + 1}/${totalPages}`; });
    if (controls) controls.toggleAttribute('data-held', held);
  };

  const turnTo = (page) => {
    currentPage = Math.max(0, Math.min(totalPages - 1, page));
    renderLabel();
    scaleStage();
  };

  const setHold = (nextHeld) => {
    held = nextHeld;
    if (autoTimer) clearInterval(autoTimer);
    autoTimer = null;
    if (!held && totalPages > 1) {
      autoTimer = setInterval(() => turnTo((currentPage + 1) % totalPages), 7000);
    }
    renderLabel();
  };

  if (controls) {
    controls.hidden = totalPages === 1;
    controls.querySelector('[data-tt-subpage-prev]')?.addEventListener('click', () => turnTo((currentPage - 1 + totalPages) % totalPages));
    controls.querySelector('[data-tt-subpage-next]')?.addEventListener('click', () => turnTo((currentPage + 1) % totalPages));
    controls.querySelector('[data-tt-subpage-hold]')?.addEventListener('click', () => setHold(!held));
  }

  document.addEventListener('keydown', (event) => {
    if (event.defaultPrevented || ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)) return;
    if (event.key === 'PageDown' || event.key === 'ArrowRight') { event.preventDefault(); turnTo((currentPage + 1) % totalPages); }
    if (event.key === 'PageUp' || event.key === 'ArrowLeft') { event.preventDefault(); turnTo((currentPage - 1 + totalPages) % totalPages); }
    if (event.key === 'h' || event.key === 'H') { event.preventDefault(); setHold(!held); }
  });

  renderLabel();
  scaleStage();
  window.addEventListener('load', scaleStage, { once: true });
  window.addEventListener('resize', scaleStage, { passive: true });
  if (!held && totalPages > 1) setHold(false);
})();
