(() => {
  const widget = document.querySelector('[data-community-chat]');
  if (!widget) return;
  const toggle = widget.querySelector('[data-chat-toggle]');
  const panel = widget.querySelector('#community-chat-panel');
  const close = widget.querySelector('[data-chat-close]');
  const heading = widget.querySelector('#community-chat-title');
  if (!toggle || !panel || !close || !heading) return;

  function setOpen(open, restoreFocus = true) {
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Collapse community chat' : 'Open community chat');
    if (open) heading.focus({ preventScroll: true });
    else if (restoreFocus) toggle.focus({ preventScroll: true });
  }
  toggle.hidden = false;
  toggle.addEventListener('click', () => setOpen(panel.hidden));
  close.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !panel.hidden && !document.querySelector('dialog[open]')) {
      setOpen(false);
    }
  });
  document.querySelectorAll('dialog').forEach(dialog => {
    dialog.addEventListener('dialog:prepare', () => {
      if (!panel.hidden) setOpen(false, false);
    });
  });
})();
