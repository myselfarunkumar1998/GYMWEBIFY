import { useState } from 'react';
import { post } from '../lib/api.js';
import { useI18n } from '../lib/i18n.jsx';
import { money, titleCase, dateTime } from '../lib/format.js';
import { Icon, Loading, useAction, useApi, Empty, Pill } from '../components/ui.jsx';
import { BackHeader, useMember, payOrder } from './MemberApp.jsx';

export default function Store() {
  const m = useMember();
  const { t } = useI18n();
  const { data, reload } = useApi('/m/store');
  const [cat, setCat] = useState('');
  const [cart, setCart] = useState({});
  const { run, busy } = useAction();
  if (!data) return <div className="phone-body"><Loading /></div>;
  const cats = [...new Set(data.items.map((p) => p.category))];
  const items = data.items.filter((p) => !cat || p.category === cat);
  const lines = Object.entries(cart).filter(([, q]) => q > 0).map(([id, q]) => ({ p: data.items.find((x) => String(x.id) === id), q }));
  const total = lines.reduce((a, l) => a + l.q * Number(l.p.price) * (1 + Number(l.p.gst_rate) / 100), 0);
  const count = lines.reduce((a, l) => a + l.q, 0);
  const checkout = async () => {
    const r = await run(() => post('/m/store/order', { items: lines.map((l) => ({ product_id: l.p.id, qty: l.q })) }));
    if (!r) return;
    setCart({});
    if (r.order) await run(() => payOrder(r, () => {}), 'Paid. Collect your order at the front desk.');
    else alert('Order placed. Pay and collect at the front desk.');
    reload();
  };
  return (
    <div className="phone-body">
      <BackHeader title={t('Store')} right={<span className="btn" aria-label={`Cart, ${count} items`} style={{ borderRadius: 14 }}><Icon name="cart" size={18} />{count}</span>} />
      <div className="chips"><button className={'chip' + (!cat ? ' on' : '')} onClick={() => setCat('')}>All</button>{cats.map((c) => <button key={c} className={'chip' + (cat === c ? ' on' : '')} onClick={() => setCat(c)}>{titleCase(c)}</button>)}</div>
      {items.length === 0 && <Empty>Nothing for sale yet.</Empty>}
      <section className="grid2" aria-label="Products">
        {items.map((p) => {
          const q = cart[p.id] || 0;
          const out = p.track_stock && p.stock <= 0;
          return (
            <div key={p.id} className="card tight" style={{ borderRadius: 18, gap: 8, opacity: out ? 0.5 : 1 }}>
              <div style={{ height: 70, borderRadius: 12, background: 'var(--panel-2)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><Icon name={p.category === 'locker' ? 'door' : p.category === 'supplement' ? 'food' : 'store'} size={28} /></div>
              <div className="col" style={{ gap: 2 }}><b style={{ fontSize: 13 }}>{p.name}</b><span className="small muted">{out ? 'Out of stock' : p.track_stock ? `${p.stock} in stock` : titleCase(p.category)}</span></div>
              <div className="row between"><span className="num" style={{ fontWeight: 700 }}>{money(Number(p.price) * (1 + Number(p.gst_rate) / 100))}</span>
                {q === 0 ? <button className="btn sm ghost" disabled={out} aria-label={`Add ${p.name}`} onClick={() => setCart({ ...cart, [p.id]: 1 })}>Add</button>
                  : <div className="row gap4"><button className="btn sm" aria-label="Less" onClick={() => setCart({ ...cart, [p.id]: q - 1 })}>−</button><span className="num">{q}</span><button className="btn sm" aria-label="More" disabled={p.track_stock && q >= p.stock} onClick={() => setCart({ ...cart, [p.id]: q + 1 })}>+</button></div>}</div>
            </div>
          );
        })}
      </section>
      {count > 0 && <button className="btn primary block" style={{ height: 52 }} disabled={busy} onClick={checkout}>Pay {money(total)} · collect at front desk</button>}
      {data.orders.length > 0 && <section className="card" style={{ borderRadius: 18 }}><h2 style={{ fontSize: 16 }}>My orders</h2>
        {data.orders.map((o) => <div key={o.id} className="row between small"><span>{o.items}<br /><span className="muted">{dateTime(o.created_at)}</span></span><span>{money(o.total)} <Pill tone={o.status === 'paid' ? undefined : 'warn'}>{titleCase(o.status)}</Pill></span></div>)}</section>}
    </div>
  );
}
