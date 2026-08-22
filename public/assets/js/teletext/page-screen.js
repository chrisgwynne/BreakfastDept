(() => {
  const stage = document.querySelector('[data-tt-page-stage]');
  const holder = document.querySelector('[data-tt-page-holder]');
  if (!stage || !holder) return;

  const scaleStage = () => {
    // Match P100's exact 640x480 scaling rule. The stage may be taller than
    // one transmitted screen, so its scaled height is measured dynamically.
    const scale = Math.min(window.innerWidth / 640, window.innerHeight / 480);
    stage.style.transform = `scale(${scale})`;
    holder.style.width = `${640 * scale}px`;
    holder.style.height = `${stage.scrollHeight * scale}px`;
  };

  scaleStage();
  window.addEventListener('load', scaleStage, { once: true });
  window.addEventListener('resize', scaleStage, { passive: true });
})();
