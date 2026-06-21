'use strict';

(function () {
  const ICONS = {
    success: 'ti-circle-check',
    error: 'ti-alert-circle',
    warning: 'ti-alert-triangle',
    info: 'ti-info-circle'
  };

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, char => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[char]);
  }

  function getToastRegion() {
    let region = document.getElementById('pawToastRegion');
    if (!region) {
      region = document.createElement('div');
      region.id = 'pawToastRegion';
      region.className = 'paw-toast-region';
      region.setAttribute('aria-live', 'polite');
      region.setAttribute('aria-atomic', 'false');
      document.body.appendChild(region);
    }
    return region;
  }

  function toast(message, type = 'info', duration = 3600) {
    const region = getToastRegion();
    const item = document.createElement('div');
    item.className = `paw-toast ${type}`;
    item.setAttribute('role', type === 'error' ? 'alert' : 'status');
    item.innerHTML = `<i class="ti ${ICONS[type] || ICONS.info}"></i><span>${escapeHtml(message)}</span><button type="button" aria-label="Dismiss"><i class="ti ti-x"></i></button>`;
    const remove = () => {
      item.classList.add('leaving');
      setTimeout(() => item.remove(), 180);
    };
    item.querySelector('button').addEventListener('click', remove);
    region.appendChild(item);
    requestAnimationFrame(() => item.classList.add('visible'));
    setTimeout(remove, duration);
    return item;
  }

  function inferToastType(message) {
    const text = String(message).toLowerCase();
    if (/failed|error|invalid|unable|cannot|incorrect|required/.test(text)) return 'error';
    if (/warning|low stock|exceed|select/.test(text)) return 'warning';
    if (/success|saved|created|updated|deleted|archived|completed/.test(text)) return 'success';
    return 'info';
  }

  function getProgressBar() {
    let bar = document.getElementById('pawProgress');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'pawProgress';
      bar.className = 'paw-progress';
      bar.innerHTML = '<span></span>';
      document.body.appendChild(bar);
    }
    return bar;
  }

  let pendingRequests = 0;
  function setLoading(delta) {
    pendingRequests = Math.max(0, pendingRequests + delta);
    const bar = getProgressBar();
    bar.classList.toggle('active', pendingRequests > 0);
    if (!pendingRequests) {
      bar.classList.add('complete');
      setTimeout(() => bar.classList.remove('complete'), 260);
    }
    document.documentElement.classList.toggle('is-loading', pendingRequests > 0);
  }

  const originalFetch = window.fetch.bind(window);
  window.fetch = async (...args) => {
    setLoading(1);
    try {
      return await originalFetch(...args);
    } finally {
      setLoading(-1);
    }
  };

  const nativeAlert = window.alert.bind(window);
  window.alert = message => {
    if (!document.body) return nativeAlert(message);
    toast(String(message), inferToastType(message));
  };

  function validateField(field) {
    if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)) return;
    const invalid = !field.checkValidity();
    field.classList.toggle('field-invalid', invalid);
    field.setAttribute('aria-invalid', String(invalid));
  }

  document.addEventListener('invalid', event => {
    validateField(event.target);
    event.target.closest('.modal-body')?.scrollTo({ top: Math.max(0, event.target.offsetTop - 80), behavior: 'smooth' });
  }, true);
  document.addEventListener('input', event => {
    if (event.target.matches('input, select, textarea')) validateField(event.target);
  });
  document.addEventListener('change', event => {
    if (event.target.matches('input, select, textarea')) validateField(event.target);
  });

  window.PawUI = { toast, escapeHtml, setLoading };
})();
