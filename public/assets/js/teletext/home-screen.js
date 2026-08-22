(() => {
  const stage = document.querySelector('[data-tt-p100-stage]');
  const holder = document.querySelector('[data-tt-p100-holder]');
  if (!stage || !holder) return;

  const scaleStage = () => {
    const scale = Math.min(window.innerWidth / 640, window.innerHeight / 480);
    stage.style.transform = `scale(${scale})`;
    holder.style.width = `${640 * scale}px`;
    holder.style.height = `${480 * scale}px`;
  };

  scaleStage();
  window.addEventListener('resize', scaleStage, { passive: true });
})();
