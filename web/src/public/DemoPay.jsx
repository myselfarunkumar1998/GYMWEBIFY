import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { get, post } from '../lib/api.js';
import { money } from '../lib/format.js';
import { Loading, useAction } from '../components/ui.jsx';

/** Stand-in for the Razorpay payment page when the demo has no live keys. */
export default function DemoPay() {
  const { id } = useParams();
  const [d, setD] = useState(null);
  const { run, busy } = useAction();
  useEffect(() => { get('/public/demo-pay/' + id).then(setD).catch(() => setD(false)); }, [id]);
  if (d === null) return <Loading />;
  if (d === false) return <div className="content"><h1>Payment link not found</h1></div>;
  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}>
      <div className="card" style={{ maxWidth: 420, width: '100%', padding: 28 }}>
        <span className="pill grey" style={{ alignSelf: 'flex-start' }}>Demo payment page</span>
        <h1 style={{ fontSize: 22 }}>{d.gym}</h1>
        <span className="muted">{d.invoice_no} · {d.customer}</span>
        <span className="num" style={{ fontSize: 40, fontWeight: 700 }}>{money(d.balance, true)}</span>
        {d.status === 'paid' ? <div className="banner info">Paid. Thank you! A receipt was sent on WhatsApp.</div>
          : <button className="btn primary block" style={{ height: 52 }} disabled={busy} onClick={async () => { const r = await run(() => post('/public/demo-pay/' + id)); if (r) setD(r); }}>Pay by UPI (demo)</button>}
        <span className="small muted">In production this link opens Razorpay, where members pay by UPI, card or net banking.</span>
      </div>
    </div>
  );
}
