// Same-origin JSON API client. The custom header is the CSRF defence the server checks.
export class ApiError extends Error {
  constructor(status, body) {
    super(body?.error?.message || `Request failed (${status})`);
    this.status = status;
    this.code = body?.error?.code;
    this.fields = body?.error?.fields || {};
  }
}

let onUnauth = null;
export function setUnauthorizedHandler(fn) { onUnauth = fn; }

export async function api(path, { method = 'GET', body, query, raw } = {}) {
  let url = '/api' + path;
  if (query) {
    const q = new URLSearchParams();
    Object.entries(query).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.set(k, v); });
    const s = q.toString();
    if (s) url += (url.includes('?') ? '&' : '?') + s;
  }
  const headers = { 'X-Requested-With': 'gymapp', Accept: 'application/json' };
  const init = { method, headers, credentials: 'same-origin' };
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  const res = await fetch(url, init);
  if (raw) {
    if (!res.ok) throw new ApiError(res.status, await res.json().catch(() => null));
    return res;
  }
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    if (res.status === 401 && onUnauth && !path.includes('/auth/')) onUnauth(path);
    throw new ApiError(res.status, data);
  }
  return data?.data;
}

export const get = (p, query) => api(p, { query });
export const post = (p, body) => api(p, { method: 'POST', body: body ?? {} });
export const put = (p, body) => api(p, { method: 'PUT', body: body ?? {} });
export const del = (p) => api(p, { method: 'DELETE' });

/** Download a file endpoint (CSV, XML, JSON backup) using the session cookie. */
export async function download(path, query) {
  const res = await api(path, { query, raw: true });
  const blob = await res.blob();
  const cd = res.headers.get('Content-Disposition') || '';
  const name = (cd.match(/filename="([^"]+)"/) || [])[1] || 'download';
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

/** Open an HTML endpoint (printable invoice) in a new tab. */
export async function openHtml(path) {
  const w = window.open('', '_blank');
  const res = await api(path, { raw: true });
  const html = await res.text();
  if (w) { w.document.open(); w.document.write(html); w.document.close(); }
}

export function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(r.result);
    r.onerror = reject;
    r.readAsDataURL(file);
  });
}

/** Shrink camera photos before upload. */
export function resizeImage(file, max = 1280) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      const s = Math.min(1, max / Math.max(img.width, img.height));
      const c = document.createElement('canvas');
      c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      resolve(c.toDataURL('image/jpeg', 0.85));
      URL.revokeObjectURL(img.src);
    };
    img.onerror = reject;
    img.src = URL.createObjectURL(file);
  });
}
