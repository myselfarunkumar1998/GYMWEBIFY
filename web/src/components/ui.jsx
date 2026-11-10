import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { get } from '../lib/api.js';
import { initials } from '../lib/format.js';

// ---------- Icons (inline stroke SVG) ----------
const P = {
  home: 'M3 10l9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
  grid: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
  users: 'M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 14 0M16 4a4 4 0 0 1 0 8M22 21a7 7 0 0 0-5-6.7',
  clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
  card: 'M2 7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2zM2 10h20',
  chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  bell: 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0',
  calendar: 'M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 2v4M16 2v4M3 10h18',
  dumbbell: 'M6 7v10M18 7v10M3 10v4M21 10v4M6 12h12',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
  qr: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3',
  back: 'M15 5l-7 7 7 7',
  plus: 'M12 5v14M5 12h14',
  check: 'M5 12l5 5 9-10',
  menu: 'M4 6h16M4 12h16M4 18h16',
  x: 'M6 6l12 12M18 6L6 18',
  cart: 'M3 4h2l2.5 12h10L20 8H6.5M9 20h.01M17 20h.01',
  phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2',
  msg: 'M21 12a8 8 0 0 1-11.8 7L3 21l2-6.2A8 8 0 1 1 21 12z',
  spark: 'M12 3l1.9 5.8L20 11l-6.1 2.2L12 19l-1.9-5.8L4 11l6.1-2.2z',
  store: 'M4 9h16l-1-5H5zM5 9v11h14V9M9 20v-6h6v6',
  door: 'M6 3h12v18H6zM14 12h.01',
  gear: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
  trophy: 'M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0zM17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3',
  food: 'M7 2v9M4 2v5a3 3 0 0 0 6 0V2M7 11v11M17 2c-2 2-3 5-3 8h3v12',
  camera: 'M4 7h3l2-3h6l2 3h3a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1zM12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  download: 'M12 3v12M7 10l5 5 5-5M4 21h16',
  refresh: 'M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5',
  gift: 'M3 9h18v4H3zM5 13h14v8H5zM12 9v12M12 9c-2-4-6-4-6-1s6 1 6 1zm0 0c2-4 6-4 6-1s-6 1-6 1z',
  logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
};
export function Icon({ name, size = 20, ...rest }) {
  return (
    <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...rest}>
      <path d={P[name] || ''} />
    </svg>
  );
}

// ---------- Data loading ----------
export function useApi(path, query, deps = []) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const key = path + JSON.stringify(query || {});
  const seq = useRef(0);
  const load = useCallback(async (silent = false) => {
    if (!path) return;
    const n = ++seq.current;
    if (!silent) setState((s) => ({ ...s, loading: true }));
    try {
      const data = await get(path, query);
      if (n === seq.current) setState({ data, error: null, loading: false });
    } catch (error) {
      if (n === seq.current) setState({ data: null, error, loading: false });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, ...deps]);
  useEffect(() => { load(); }, [load]);
  return { ...state, reload: load };
}

export function usePoll(fn, ms) {
  useEffect(() => {
    const id = setInterval(() => { if (document.visibilityState === 'visible') fn(); }, ms);
    return () => clearInterval(id);
  }, [fn, ms]);
}

// ---------- Toasts ----------
const ToastCtx = createContext(() => {});
export function ToastProvider({ children }) {
  const [items, setItems] = useState([]);
  const push = useCallback((msg, kind = 'ok') => {
    const id = Math.random();
    setItems((x) => [...x, { id, msg, kind }]);
    setTimeout(() => setItems((x) => x.filter((i) => i.id !== id)), kind === 'err' ? 6000 : 3500);
  }, []);
  return (
    <ToastCtx.Provider value={push}>
      {children}
      <div className="toasts" role="status" aria-live="polite">
        {items.map((i) => <div key={i.id} className={'toast' + (i.kind === 'err' ? ' err' : '')}>{i.msg}</div>)}
      </div>
    </ToastCtx.Provider>
  );
}
export const useToast = () => useContext(ToastCtx);

/** Wrap an async action: shows errors as toasts, returns field errors for forms. */
export function useAction() {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const [fields, setFields] = useState({});
  const run = async (fn, okMsg) => {
    setBusy(true); setFields({});
    try {
      const r = await fn();
      if (okMsg) toast(okMsg);
      return r;
    } catch (e) {
      setFields(e.fields || {});
      toast(e.message || 'Something went wrong', 'err');
      return undefined;
    } finally { setBusy(false); }
  };
  return { run, busy, fields, setFields };
}

// ---------- Primitives ----------
export function Loading() { return <div className="loading" aria-label="Loading"><div className="spinner" /></div>; }
export function ErrorBox({ error, onRetry }) {
  return (
    <div className="card alert" role="alert">
      <span>{error?.message || 'Could not load this page.'}</span>
      {onRetry && <button className="btn sm" onClick={() => onRetry()}>Try again</button>}
    </div>
  );
}
export function Empty({ children }) { return <div className="empty">{children}</div>; }

export function Stat({ label, value, sub, tone }) {
  return (
    <div className={'card' + (tone === 'hero' ? ' hero' : tone === 'alert' ? ' alert' : '')}>
      <span className="stat-label">{label}</span>
      <span className="stat-value">{value}</span>
      {sub && <span className="stat-sub">{sub}</span>}
    </div>
  );
}

export function Pill({ tone, children }) { return <span className={'pill' + (tone ? ' ' + tone : '')}>{children}</span>; }

export function Avatar({ name, src, size }) {
  const [ok, setOk] = useState(true);
  return (
    <span className={'avatar' + (size === 'lg' ? ' lg' : '')}>
      {src && ok ? <img src={src} alt="" onError={() => setOk(false)} /> : initials(name)}
    </span>
  );
}

export function Field({ label, error, children, hint, className = '' }) {
  return (
    <div className={'field ' + className + (error ? ' has-err' : '')}>
      {label && <label>{label}</label>}
      {children}
      {hint && !error && <span className="small muted">{hint}</span>}
      {error && <span className="err">{error}</span>}
    </div>
  );
}

let uid = 0;
export function Input({ label, error, hint, className, ...props }) {
  const [id] = useState(() => 'f' + ++uid);
  return (
    <div className={'field ' + (className || '') + (error ? ' has-err' : '')}>
      {label && <label htmlFor={id}>{label}</label>}
      {props.as === 'textarea'
        ? <textarea id={id} className="input" rows={props.rows || 3} {...{ ...props, as: undefined }} />
        : <input id={id} className="input" {...props} />}
      {hint && !error && <span className="small muted">{hint}</span>}
      {error && <span className="err">{error}</span>}
    </div>
  );
}

export function Select({ label, error, options, className, placeholder, ...props }) {
  const [id] = useState(() => 'f' + ++uid);
  return (
    <div className={'field ' + (className || '') + (error ? ' has-err' : '')}>
      {label && <label htmlFor={id}>{label}</label>}
      <select id={id} className="input" {...props}>
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {options.map((o) => (typeof o === 'object' ? <option key={o.value} value={o.value}>{o.label}</option> : <option key={o} value={o}>{o}</option>))}
      </select>
      {error && <span className="err">{error}</span>}
    </div>
  );
}

export function Check({ label, checked, onChange }) {
  return <label className="check"><input type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} />{label}</label>;
}

export function Chips({ items, value, onChange, label }) {
  return (
    <div className="chips" role="group" aria-label={label}>
      {items.map((i) => (
        <button key={i.value} type="button" className={'chip' + (value === i.value ? ' on' : '')} aria-pressed={value === i.value} onClick={() => onChange(i.value)}>
          {i.label}{i.count !== undefined && i.count !== null ? ` · ${i.count}` : ''}
        </button>
      ))}
    </div>
  );
}

export function Tabs({ items, value, onChange }) {
  return (
    <div className="tabs" role="tablist">
      {items.map((i) => (
        <button key={i.value} role="tab" aria-selected={value === i.value} className={value === i.value ? 'on' : ''} onClick={() => onChange(i.value)}>{i.label}</button>
      ))}
    </div>
  );
}

export function Modal({ title, onClose, children, wide, footer }) {
  const ref = useRef(null);
  useEffect(() => {
    const prev = document.activeElement;
    ref.current?.querySelector('input,select,textarea,button')?.focus();
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('keydown', onKey); prev?.focus?.(); };
  }, [onClose]);
  return (
    <div className="overlay" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div className={'modal' + (wide ? ' wide' : '')} role="dialog" aria-modal="true" aria-label={title} ref={ref}>
        <div className="row between"><h2>{title}</h2><button className="iconbtn" aria-label="Close" onClick={onClose}><Icon name="x" /></button></div>
        {children}
        {footer && <div className="row wrap" style={{ justifyContent: 'flex-end' }}>{footer}</div>}
      </div>
    </div>
  );
}

export function Bars({ data, height = 160, label, hiAt }) {
  const max = Math.max(1, ...data.map((d) => d.value));
  return (
    <div className="bars" style={{ height }} role="img" aria-label={label}>
      {data.map((d, i) => (
        <div className="bar" key={i} title={`${d.label}: ${d.title ?? d.value}`}>
          <div className={hiAt !== undefined ? (d.value >= hiAt ? 'hi' : '') : (d.hi ? 'hi' : '')} style={{ height: Math.max(2, Math.round((d.value / max) * (height - 26))) }} />
          <span>{d.label}</span>
        </div>
      ))}
    </div>
  );
}

export function Progress({ pct }) {
  return <div className="progress" role="img" aria-label={`${Math.round(pct)}%`}><div style={{ width: `${Math.max(0, Math.min(100, pct))}%` }} /></div>;
}

/** Signature pad for waivers. Returns a PNG data URL through onChange. */
export function SignaturePad({ onChange }) {
  const ref = useRef(null);
  const drawing = useRef(false);
  useEffect(() => {
    const c = ref.current;
    const ratio = window.devicePixelRatio || 1;
    c.width = c.offsetWidth * ratio; c.height = c.offsetHeight * ratio;
    const ctx = c.getContext('2d');
    ctx.scale(ratio, ratio); ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
  }, []);
  const pos = (e) => { const r = ref.current.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
  const down = (e) => { drawing.current = true; const ctx = ref.current.getContext('2d'); ctx.beginPath(); ctx.moveTo(...pos(e)); ref.current.setPointerCapture(e.pointerId); };
  const move = (e) => { if (!drawing.current) return; const ctx = ref.current.getContext('2d'); ctx.lineTo(...pos(e)); ctx.stroke(); };
  const up = () => { if (!drawing.current) return; drawing.current = false; onChange(ref.current.toDataURL('image/png')); };
  const clear = () => { const c = ref.current; c.getContext('2d').clearRect(0, 0, c.width, c.height); onChange(null); };
  return (
    <div className="col gap8">
      <canvas ref={ref} className="sig" aria-label="Signature pad: draw your signature" onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerLeave={up} />
      <button type="button" className="btn sm" onClick={clear} style={{ alignSelf: 'flex-start' }}>Clear signature</button>
    </div>
  );
}

/** Load an image from an authenticated endpoint (cookies are sent automatically). */
export function AuthImg({ src, alt = '', ...rest }) {
  return <img src={src} alt={alt} loading="lazy" {...rest} />;
}

export function Confirm({ title, message, confirmLabel = 'Confirm', danger, onConfirm, onClose, children }) {
  const [busy, setBusy] = useState(false);
  return (
    <Modal title={title} onClose={onClose} footer={<>
      <button className="btn" onClick={onClose}>Cancel</button>
      <button className={'btn ' + (danger ? 'danger' : 'primary')} disabled={busy} onClick={async () => { setBusy(true); try { await onConfirm(); } finally { setBusy(false); } }}>{confirmLabel}</button>
    </>}>
      {message && <p className="t2" style={{ margin: 0 }}>{message}</p>}
      {children}
    </Modal>
  );
}
