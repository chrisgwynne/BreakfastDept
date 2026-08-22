(() => {
  const stage = document.querySelector('[data-tt-p100-stage]');
  const holder = document.querySelector('[data-tt-p100-holder]');
  if (!stage || !holder) return;

  const greeting = document.querySelector('[data-tt-greeting]');
  const timeArt = document.querySelector('[data-tt-time-art]');
  if (greeting) {
    const hour = new Date().getHours();
    const mode = hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening';
    greeting.textContent = mode === 'morning' ? 'GOOD MORNING' : mode === 'afternoon' ? 'GOOD AFTERNOON' : 'GOOD EVENING';
    if (timeArt) timeArt.dataset.ttTimeArt = mode;
  }

  const scaleStage = () => {
    const scale = Math.min(window.innerWidth / 640, window.innerHeight / 480);
    stage.style.transform = `scale(${scale})`;
    holder.style.width = `${640 * scale}px`;
    holder.style.height = `${480 * scale}px`;
  };

  scaleStage();
  window.addEventListener('resize', scaleStage, { passive: true });
})();
