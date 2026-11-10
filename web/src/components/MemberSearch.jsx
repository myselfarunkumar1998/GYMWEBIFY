import { useEffect, useRef, useState } from 'react';
import { get } from '../lib/api.js';
import { Avatar } from './ui.jsx';

/** Type-ahead member search used across the admin. */
export default function MemberSearch({ onPick, placeholder = 'Search member by name, phone or ID', autoFocus, label = 'Search members' }) {
  const [q, setQ] = useState('');
  const [items, setItems] = useState([]);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const timer = useRef(null);
  useEffect(() => {
    clearTimeout(timer.current);
    if (q.trim().length < 2) { setItems([]); return; }
    timer.current = setTimeout(async () => {
      try { const r = await get('/members', { q, limit: 8 }); setItems(r.items); setOpen(true); setActive(0); } catch { setItems([]); }
    }, 220);
  }, [q]);
  const pick = (m) => { onPick(m); setQ(''); setItems([]); setOpen(false); };
  return (
    <div style={{ position: 'relative' }}>
      <label className="sr" htmlFor="member-search">{label}</label>
      <input id="member-search" className="input" type="search" placeholder={placeholder} value={q} autoFocus={autoFocus} autoComplete="off"
        role="combobox" aria-expanded={open && items.length > 0} aria-controls="member-search-list"
        onChange={(e) => setQ(e.target.value)} onFocus={() => setOpen(true)} onBlur={() => setTimeout(() => setOpen(false), 150)}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(items.length - 1, a + 1)); }
          if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(0, a - 1)); }
          if (e.key === 'Enter' && items[active]) { e.preventDefault(); pick(items[active]); }
        }} />
      {open && items.length > 0 && (
        <ul id="member-search-list" role="listbox" style={{ position: 'absolute', top: 48, left: 0, right: 0, zIndex: 30, background: 'var(--panel-2)', border: '1px solid var(--line-2)', borderRadius: 12, listStyle: 'none', margin: 0, padding: 6, maxHeight: 320, overflowY: 'auto' }}>
          {items.map((m, i) => (
            <li key={m.id} role="option" aria-selected={i === active} onMouseDown={() => pick(m)}
              style={{ padding: 8, borderRadius: 8, cursor: 'pointer', background: i === active ? 'var(--panel)' : 'transparent', display: 'flex', gap: 10, alignItems: 'center' }}>
              <Avatar name={m.name} />
              <div className="col" style={{ gap: 0 }}><span style={{ fontWeight: 500 }}>{m.name}</span><span className="small muted">{m.member_code} · {m.phone} · {m.plan_name || 'No plan'}</span></div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
