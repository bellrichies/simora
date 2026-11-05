const menu = document.querySelector('[data-menu]');
const sidebar = document.querySelector('#sidebar');
const backdrop = document.querySelector('[data-close-menu]');
const workspace = document.querySelector('.workspace');
const sidebarClose = document.querySelector('[data-close-sidebar]');
function setMenu(open) {
  const mobile = window.matchMedia('(max-width: 1100px)').matches;
  const wasOpen = sidebar.classList.contains('open');
  open = mobile && open;
  sidebar.classList.toggle('open', open);
  sidebar.inert = mobile && !open;
  workspace.inert = open;
  document.body.classList.toggle('menu-open', open);
  backdrop.classList.toggle('open', open);
  menu.setAttribute('aria-expanded', String(open));
  menu.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  if (open && !wasOpen) sidebarClose.focus({ preventScroll: true });
  if (!open && wasOpen && mobile) menu.focus({ preventScroll: true });
  if (!mobile && wasOpen && document.activeElement === sidebarClose) {
    sidebar.querySelector('a[aria-current="page"]')?.focus({ preventScroll: true });
  }
}
menu.addEventListener('click', () => {
  closeDropdowns();
  setMenu(!sidebar.classList.contains('open'));
});
sidebarClose.addEventListener('click', () => setMenu(false));
backdrop.addEventListener('click', () => setMenu(false));
function closeDropdowns(except) {
  document.querySelectorAll('[data-dropdown]').forEach(button => {
    if (button === except) return;
    document.getElementById(button.dataset.dropdown).hidden = true;
    button.setAttribute('aria-expanded', 'false');
  });
}
document.querySelectorAll('[data-dropdown]').forEach(button => button.addEventListener('click', event => {
  event.stopPropagation();
  const panel = document.getElementById(button.dataset.dropdown);
  const opening = panel.hidden;
  closeDropdowns(button);
  panel.hidden = !opening;
  button.setAttribute('aria-expanded', String(opening));
}));
document.addEventListener('click', event => { if (!event.target.closest('.dropdown-wrap')) closeDropdowns(); });
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') { closeDropdowns(); setMenu(false); }
  if (event.key === 'Tab' && sidebar.classList.contains('open')) {
    const focusable = Array.from(sidebar.querySelectorAll('a[href], button:not([disabled])'))
      .filter(element => element.getClientRects().length > 0);
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault(); last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault(); first.focus();
    }
  }
});
let dialogTrigger;
document.addEventListener('click', event => {
  const button = event.target.closest('[data-dialog]');
  if (!button) return;
  dialogTrigger = button;
  closeDropdowns();
  if (sidebar.contains(button)) setMenu(false);
  const dialog = document.getElementById(button.dataset.dialog);
  dialog.dispatchEvent(new CustomEvent('dialog:prepare', { detail: { trigger: button } }));
  dialog.showModal();
});
document.querySelectorAll('dialog').forEach(dialog => {
  dialog.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', () => {
    if (sidebar.inert && sidebar.contains(dialogTrigger)) menu.focus();
    else dialogTrigger?.focus();
  });
});

const collapseButton = document.querySelector('[data-collapse]');
collapseButton.addEventListener('click', () => {
  const collapsed = document.body.classList.toggle('sidebar-collapsed');
  collapseButton.setAttribute('aria-expanded', String(!collapsed));
  collapseButton.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
  collapseButton.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
});

function updateCountdowns() {
  document.querySelectorAll('[data-countdown]').forEach(element => {
    const initialKickoff = Date.parse(element.dataset.countdown);
    const now = Date.now();
    const interval = Number(element.dataset.countdownInterval) * 1000;
    let target = initialKickoff;
    let closed = false;
    if (Number.isFinite(initialKickoff) && Number.isFinite(interval) && interval > 30000) {
      const cycle = Math.floor((now - initialKickoff) / interval);
      const lastKickoff = initialKickoff + cycle * interval;
      closed = now - lastKickoff < 30000;
      target = closed ? lastKickoff : lastKickoff + interval;
      const time = element.closest('.overview-platform-card')?.querySelector('[data-schedule-kickoff]');
      if (time && time.dataset.renderedKickoff !== String(target)) {
        time.dateTime = new Date(target).toISOString();
        time.textContent = new Intl.DateTimeFormat('en-GB', {
          timeZone: 'Africa/Lagos', day: '2-digit', month: 'short',
          hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
        }).format(new Date(target)) + ' WAT';
        time.dataset.renderedKickoff = String(target);
      }
    }
    const remaining = target - now;
    if (!Number.isFinite(remaining)) { element.textContent = 'Unavailable'; return; }
    if (closed || remaining <= 0) {
      element.textContent = element.dataset.countdownClosedLabel || 'Closed';
      element.setAttribute('data-countdown-closed', 'true');
      return;
    }
    element.removeAttribute('data-countdown-closed');
    const seconds = Math.ceil(remaining / 1000);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    element.textContent = element.dataset.countdownFormat === 'clock'
      ? `${hours ? `${String(hours).padStart(2, '0')}:` : ''}${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`
      : `${hours ? `${hours}h ` : ''}${minutes}m ${String(seconds % 60).padStart(2, '0')}s`;
  });
}
updateCountdowns();
if (document.querySelector('[data-countdown]')) setInterval(updateCountdowns, 1000);

function syncSidebar() {
  if (window.matchMedia('(max-width: 1100px)').matches) { setMenu(sidebar.classList.contains('open')); }
  else { setMenu(false); sidebar.inert = false; }
}
window.addEventListener('resize', syncSidebar);
syncSidebar();

