import { useState } from 'react';
import { post, put, openHtml } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { money, dateTime, titleCase } from '../lib/format.js';
import { Check, ErrorBox, Input, Loading, Modal, Pill, Select, Stat, useAction, useApi, Empty } from '../components/ui.jsx';
import MemberSearch from '../components/MemberSearch.jsx';

export default function Store() {
  const s = useStaff();
  const { data, error, loading, reload } = useApi('/products');
  const [edit, setEdit] = useState(null);
  const [stock, setStock] = useState(null);
  const [sale, setSale] = useState(false);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  return (
    <>
      <header className="topbar">
        <div className="col gap4"><h1>Store &amp; inventory</h1><span className="muted">Supplements, merchandise, lockers and services · POS with GST</span></div>
        <div className="row wrap">
          {s.can('store.manage') && <button className="btn" onClick={() => setEdit({ name: '', category: 'supplement', price: '', cost: '', gst_rate: 18, reorder_level: 5, track_stock: true, sell_online: true, hsn: '', opening_stock: 0 })}>+ Product</button>}
          {s.can('store.sell') && <button className="btn primary" onClick={() => setSale(true)}>New sale</button>}
        </div>
      </header>
      <section className="grid4">
        <Stat label="Sales this month" value={money(data.month_sales)} sub={`${data.month_orders} orders`} />
        {s.can('store.manage') && <Stat label="Margin this month" value={money(data.month_margin)} />}
        <Stat tone={data.low_stock ? 'alert' : undefined} label="Low stock" value={data.low_stock} sub="At or below reorder level" />
        {s.can('store.manage') && <Stat label="Stock value (cost)" value={money(data.stock_value)} />}
      </section>
      <div className="split">
        <section className="card main">
          <div className="tablebox"><table className="t" style={{ minWidth: 640 }}>
            <thead><tr><th>Product</th><th>Category</th><th className="r">Price</th><th>GST</th><th className="r">Stock</th><th className="r">Sold 30d</th><th></th></tr></thead>
            <tbody>{data.items.map((p) => {
              const low = p.track_stock && p.stock <= p.reorder_level;
              return (
                <tr key={p.id} style={{ opacity: p.is_active ? 1 : 0.5 }}>
                  <td style={{ fontWeight: 500 }}>{p.name}{p.sell_online ? '' : <span className="small muted"> · desk only</span>}</td><td>{titleCase(p.category)}</td>
                  <td className="r num">{money(p.price)}</td><td>{+p.gst_rate}%</td>
                  <td className={'r num ' + (low ? 'warn' : '')}>{p.track_stock ? p.stock : '—'}{low ? ' · low' : ''}</td><td className="r num">{p.sold_30d}</td>
                  <td className="r" style={{ whiteSpace: 'nowrap' }}>{s.can('store.manage') && <>{p.track_stock ? <button className="btn sm ghost" onClick={() => setStock(p)}>Stock</button> : null}{' '}<button className="btn sm" onClick={() => setEdit({ ...p, track_stock: !!p.track_stock, sell_online: !!p.sell_online, is_active: !!p.is_active })}>Edit</button></>}</td>
                </tr>
              );
            })}</tbody>
          </table></div>
        </section>
        <section className="card side">
          <h2>Recent sales</h2>
          {data.recent.length === 0 && <span className="small muted">No sales yet.</span>}
          {data.recent.map((r) => (
            <button key={r.id} className="listrow" style={{ background: 'none', border: 0, borderTop: '1px solid var(--line)', color: 'inherit', textAlign: 'left', cursor: 'pointer', width: '100%' }} onClick={() => openHtml(`/invoices/${r.id}/print`)}>
              <div className="grow col" style={{ gap: 2 }}><span style={{ fontWeight: 500 }}>{r.items}</span><span className="small muted">{r.customer_name} · {dateTime(r.created_at)}</span></div>
              <span className="num">{money(r.total)}</span>{r.status !== 'paid' && <Pill tone="warn">{titleCase(r.status)}</Pill>}
            </button>
          ))}
        </section>
      </div>
      {edit && <ProductForm p={edit} onClose={() => setEdit(null)} onDone={() => { setEdit(null); reload(); }} />}
      {stock && <StockForm p={stock} onClose={() => setStock(null)} onDone={() => { setStock(null); reload(); }} />}
      {sale && <Sale products={data.items.filter((p) => p.is_active)} onClose={() => setSale(false)} onDone={() => { setSale(false); reload(); }} />}
    </>
  );
}

function ProductForm({ p, onClose, onDone }) {
  const [f, setF] = useState(p);
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const save = async () => {
    const body = { name: f.name, category: f.category, sku: f.sku || null, hsn: f.hsn || null, price: Number(f.price), cost: Number(f.cost || 0), gst_rate: String(f.gst_rate),
      reorder_level: Number(f.reorder_level || 0), track_stock: !!f.track_stock, sell_online: !!f.sell_online, is_active: f.is_active === undefined ? true : !!f.is_active, opening_stock: Number(f.opening_stock || 0) };
    if (await run(() => (p.id ? put('/products/' + p.id, body) : post('/products', body)), 'Saved')) onDone();
  };
  return (
    <Modal title={p.id ? 'Edit product' : 'New product'} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={busy} onClick={save}>Save</button></>}>
      <div className="grid2">
        <Input label="Name" value={f.name} onChange={set('name')} error={fields.name} />
        <Select label="Category" value={f.category} onChange={set('category')} options={['supplement', 'merchandise', 'locker', 'service', 'other'].map((x) => ({ value: x, label: titleCase(x) }))} />
        <Input label="Selling price (₹, before GST)" type="number" value={f.price} onChange={set('price')} error={fields.price} />
        <Input label="Cost price (₹)" type="number" value={f.cost} onChange={set('cost')} />
        <Select label="GST" value={String(+f.gst_rate)} onChange={set('gst_rate')} options={['0', '5', '12', '18', '28']} />
        <Input label="HSN/SAC" value={f.hsn || ''} onChange={set('hsn')} />
        <Input label="Reorder level" type="number" value={f.reorder_level} onChange={set('reorder_level')} />
        {!p.id && f.track_stock && <Input label="Opening stock" type="number" value={f.opening_stock} onChange={set('opening_stock')} />}
      </div>
      <Check label="Track stock" checked={f.track_stock} onChange={(v) => setF({ ...f, track_stock: v })} />
      <Check label="Sell in member app" checked={f.sell_online} onChange={(v) => setF({ ...f, sell_online: v })} />
      {p.id && <Check label="Active" checked={f.is_active} onChange={(v) => setF({ ...f, is_active: v })} />}
    </Modal>
  );
}

function StockForm({ p, onClose, onDone }) {
  const [f, setF] = useState({ kind: 'purchase', qty: '', cost: p.cost, ref: '' });
  const { run, busy } = useAction();
  return (
    <Modal title={`Stock · ${p.name}`} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy || !f.qty} onClick={async () => { if (await run(() => post(`/products/${p.id}/stock`, { kind: f.kind, qty: Number(f.qty), cost: f.kind === 'purchase' ? Number(f.cost || 0) : null, ref: f.ref || null }), 'Stock updated')) onDone(); }}>Save</button></>}>
      <span className="muted">Current stock: {p.stock}</span>
      <div className="seg"><button type="button" className={f.kind === 'purchase' ? 'on' : ''} onClick={() => setF({ ...f, kind: 'purchase' })}>Purchase</button><button type="button" className={f.kind === 'adjust' ? 'on' : ''} onClick={() => setF({ ...f, kind: 'adjust' })}>Adjust (+/−)</button></div>
      <div className="grid3">
        <Input label="Quantity" type="number" value={f.qty} onChange={(e) => setF({ ...f, qty: e.target.value })} />
        {f.kind === 'purchase' && <Input label="Cost each (₹)" type="number" value={f.cost} onChange={(e) => setF({ ...f, cost: e.target.value })} />}
        <Input label="Bill / reason" value={f.ref} onChange={(e) => setF({ ...f, ref: e.target.value })} />
      </div>
      {f.kind === 'purchase' && <span className="small muted">Purchases are added to expenses automatically.</span>}
    </Modal>
  );
}

function Sale({ products, onClose, onDone }) {
  const [member, setMember] = useState(null);
  const [cart, setCart] = useState({});
  const [mode, setMode] = useState('upi');
  const [payNow, setPayNow] = useState(true);
  const { run, busy } = useAction();
  const lines = Object.entries(cart).filter(([, q]) => q > 0).map(([id, qty]) => ({ p: products.find((x) => String(x.id) === id), qty }));
  const total = lines.reduce((a, l) => a + l.qty * Number(l.p.price) * (1 + Number(l.p.gst_rate) / 100), 0);
  const save = async () => {
    const r = await run(() => post('/store/sale', { items: lines.map((l) => ({ product_id: l.p.id, qty: l.qty })), member_id: member?.id, pay_mode: mode, pay_now: payNow }), 'Sale recorded');
    if (r) { openHtml(`/invoices/${r.invoice_id}/print`); onDone(); }
  };
  return (
    <Modal title="New sale" wide onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={busy || !lines.length} onClick={save}>{payNow ? `Collect ${money(total, true)}` : 'Add to member dues'}</button></>}>
      {member ? <div className="row"><span className="inner grow">{member.name} · {member.member_code}</span><button className="btn sm" onClick={() => setMember(null)}>Walk-in</button></div> : <MemberSearch onPick={setMember} placeholder="Member (optional, else walk-in)" />}
      <div className="grid3">{products.map((p) => {
        const q = cart[p.id] || 0;
        const out = p.track_stock && p.stock <= 0;
        return (
          <div key={p.id} className="inner col gap8" style={{ opacity: out ? 0.5 : 1 }}>
            <span style={{ fontWeight: 600 }}>{p.name}</span><span className="small muted">{money(p.price)} + {+p.gst_rate}% GST{p.track_stock ? ` · ${p.stock} in stock` : ''}</span>
            <div className="row"><button className="btn sm" aria-label={`Less ${p.name}`} disabled={!q} onClick={() => setCart({ ...cart, [p.id]: q - 1 })}>−</button><span className="num" style={{ minWidth: 24, textAlign: 'center' }}>{q}</span><button className="btn sm" aria-label={`More ${p.name}`} disabled={out || (p.track_stock && q >= p.stock)} onClick={() => setCart({ ...cart, [p.id]: q + 1 })}>+</button></div>
          </div>
        );
      })}</div>
      <div className="seg">{['upi', 'cash', 'card', 'credit'].map((m) => <button key={m} type="button" className={mode === m ? 'on' : ''} onClick={() => setMode(m)}>{m === 'credit' ? 'Referral credit' : m.toUpperCase()}</button>)}</div>
      {member && <Check label="Collect payment now" checked={payNow} onChange={setPayNow} />}
      {lines.length === 0 && <Empty>Add items with +</Empty>}
    </Modal>
  );
}
