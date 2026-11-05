'use strict';

(() => {
  const form = document.getElementById('trade-draft-form');
  if (!form) return;
  const status = document.getElementById('trade-draft-status');
  const save = form.querySelector('button[type="submit"]');
  const fields = ['strategy', 'stake', 'stopLoss', 'profitTarget', 'session'];
  const lossControl = form.elements.namedItem('lossControl');
  const lossCount = form.elements.namedItem('lossCount');
  const lookback = form.elements.namedItem('lookback');
  const lossSettings = document.getElementById('trade-loss-settings');
  const lossValue = document.getElementById('trade-loss-count-value');
  const lookbackValue = document.getElementById('trade-lookback-value');
  const lossError = document.getElementById('trade-loss-error');
  function updateLossValues() {
    lossValue.textContent = lossCount.value;
    lookbackValue.textContent = lookback.value;
    const invalid = lossControl.checked && Number(lossCount.value) > Number(lookback.value);
    lossCount.setCustomValidity(invalid ? 'Losses cannot exceed the number of recent predictions.' : '');
    lossError.textContent = invalid ? 'Losses cannot exceed the number of recent predictions.' : '';
  }
  function syncLossControl() {
    lossSettings.hidden = !lossControl.checked;
    lossCount.disabled = lookback.disabled = !lossControl.checked;
    updateLossValues();
  }
  lossControl.addEventListener('change', syncLossControl);
  lossCount.addEventListener('input', updateLossValues);
  lookback.addEventListener('input', updateLossValues);
  const key = `simora.auto-trade.preview.v1.${form.dataset.platform}`;
  try {
    const draft = JSON.parse(sessionStorage.getItem(key) || 'null');
    if (draft && typeof draft === 'object') {
      fields.forEach(name => {
        const field = form.elements.namedItem(name);
        if (typeof draft[name] === 'string') field.value = draft[name];
      });
      lossControl.checked = draft.lossControl === true;
      if (typeof draft.lossCount === 'string') lossCount.value = draft.lossCount;
      if (typeof draft.lookback === 'string') lookback.value = draft.lookback;
      status.textContent = 'Preview draft restored for this platform in this tab.';
    }
    syncLossControl();
    save.disabled = false;
  } catch (error) {
    status.textContent = 'Browser storage is unavailable. This draft cannot be saved.';
  }
  form.addEventListener('submit', event => {
    event.preventDefault();
    updateLossValues();
    if (lossControl.checked && Number(lossCount.value) > Number(lookback.value)) {
      lossCount.focus();
      return;
    }
    if (!form.reportValidity()) {
      status.textContent = 'Check the highlighted fields before saving.';
      return;
    }
    const draft = {};
    fields.forEach(name => { draft[name] = form.elements.namedItem(name).value; });
    draft.platform = form.elements.namedItem('platform').value;
    draft.league = form.elements.namedItem('league').value;
    draft.market = form.elements.namedItem('market').value;
    draft.lossControl = lossControl.checked;
    draft.lossCount = lossControl.checked ? lossCount.value : '';
    draft.lookback = lossControl.checked ? lookback.value : '';
    try {
      sessionStorage.setItem(key, JSON.stringify(draft));
      status.textContent = 'Preview draft saved in this browser tab. Auto Trade is not active.';
    } catch (error) {
      status.textContent = 'Could not save the draft. Browser storage may be unavailable or full.';
    }
  });
})();
