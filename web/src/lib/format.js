const inr = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 });
const inr2 = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const money = (v, decimals = false) => '₹' + (decimals ? inr2 : inr).format(Number(v || 0));
export const moneyShort = (v) => {
  const n = Number(v || 0);
  if (n >= 1e7) return '₹' + (n / 1e7).toFixed(2) + 'Cr';
  if (n >= 1e5) return '₹' + (n / 1e5).toFixed(2) + 'L';
  return money(n);
};
export const num = (v) => inr.format(Number(v || 0));

export const parseDate = (s) => (s ? new Date(String(s).replace(' ', 'T')) : null);
export const date = (s, opts = { day: 'numeric', month: 'short', year: 'numeric' }) => (s ? parseDate(s).toLocaleDateString('en-IN', opts) : '—');
export const dateShort = (s) => date(s, { day: 'numeric', month: 'short' });
export const dayDate = (s) => date(s, { weekday: 'short', day: 'numeric', month: 'short' });
export const time = (s) => (s ? parseDate(s).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }).toUpperCase() : '—');
export const dateTime = (s) => (s ? `${dateShort(s)}, ${time(s)}` : '—');
export const hm = (t) => {
  if (!t) return '—';
  const [h, m] = String(t).split(':');
  const d = new Date(); d.setHours(+h, +m, 0, 0);
  return d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }).toUpperCase();
};
export const mins = (m) => {
  if (m === null || m === undefined || m === '') return '—';
  m = Math.round(Number(m));
  return m >= 60 ? `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m` : `${m}m`;
};
export const sinceMins = (s) => Math.max(0, Math.round((Date.now() - parseDate(s).getTime()) / 60000));
export const today = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
export const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
export const addDays = (s, n) => { const d = parseDate(s + ' 12:00:00'); d.setDate(d.getDate() + n); return isoDate(d); };
export const month = () => today().slice(0, 7);
export const daysUntil = (s) => Math.round((parseDate(s + ' 00:00:00') - parseDate(today() + ' 00:00:00')) / 86400000);
export const initials = (name = '') => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
export const relDays = (s) => {
  if (!s) return 'Never';
  const d = -daysUntil(String(s).slice(0, 10));
  if (d <= 0) return 'Today';
  if (d === 1) return 'Yesterday';
  return `${d} days ago`;
};
export const titleCase = (s = '') => s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
export const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
