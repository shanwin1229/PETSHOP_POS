'use strict';

const PawApi = (() => {
  let csrfToken = '';

  async function csrf() {
    if (csrfToken) return csrfToken;
    const res = await fetch('login.php?action=csrf', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const json = await res.json();
    csrfToken = json?.data?.csrf_token || '';
    return csrfToken;
  }

  async function request(url, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = { Accept: 'application/json', ...(options.headers || {}) };

    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      const body = options.body ? JSON.parse(options.body) : (options.data || {});
      body.csrf_token = await csrf();
      options.body = JSON.stringify(body);
      delete options.data;
    }

    const res = await fetch(url, { ...options, method, headers, credentials: 'same-origin' });
    const raw = await res.text();
    let json;
    try {
      json = raw ? JSON.parse(raw) : {};
    } catch {
      console.error('[PAWPOS] Invalid server response from', url, raw);
      throw new Error('Invalid server response. Check PHP error log or Network response.');
    }

    if (!res.ok || json.success === false) {
      throw new Error(json.message || 'Request failed.');
    }

    return json.data ?? json;
  }

  return {
    get: url => request(url),
    post: (url, data) => request(url, { method: 'POST', data }),
    put: (url, data) => request(url, { method: 'PUT', data }),
    patch: (url, data) => request(url, { method: 'PATCH', data }),
    delete: (url, data = {}) => request(url, { method: 'DELETE', data })
  };
})();