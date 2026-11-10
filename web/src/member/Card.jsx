import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { get } from '../lib/api.js';
import { useI18n } from '../lib/i18n.jsx';
import { date } from '../lib/format.js';
import { Loading } from '../components/ui.jsx';
import { BackHeader, useMember } from './MemberApp.jsx';

/** Rotating check-in QR. A new signed token is fetched every 30 seconds, so screenshots stop working. */
export default function Card() {
  const m = useMember();
  const { t } = useI18n();
  const [d, setD] = useState(null);
  const [left, setLeft] = useState(30);
  const canvas = useRef(null);
  const wake = useRef(null);

  useEffect(() => {
    let timer; let stop = false;
    const load = async () => {
      try {
        const r = await get('/m/card');
        if (stop) return;
        setD(r); setLeft(r.expires_in);
        timer = setTimeout(load, Math.max(3, r.expires_in) * 1000);
      } catch { timer = setTimeout(load, 5000); }
    };
    load();
    navigator.wakeLock?.request('screen').then((w) => { wake.current = w; }).catch(() => {});
    return () => { stop = true; clearTimeout(timer); wake.current?.release?.(); };
  }, []);
  useEffect(() => { const id = setInterval(() => setLeft((x) => Math.max(0, x - 1)), 1000); return () => clearInterval(id); }, []);
  useEffect(() => {
    if (!d || !canvas.current) return;
    import('qrcode').then((QR) => QR.toCanvas(canvas.current, d.token, { width: 220, margin: 1, errorCorrectionLevel: 'M', color: { dark: '#0B0C0E', light: '#FFFFFF' } }));
  }, [d]);

  return (
    <div className="phone-body">
      <BackHeader title={t('My card')} />
      {!d ? <Loading /> : <>
        <section className="card hero" style={{ borderRadius: 26, alignItems: 'center', gap: 14 }} aria-label="Membership card">
          <div className="row between" style={{ alignSelf: 'stretch', alignItems: 'flex-start' }}>
            <div className="col" style={{ gap: 2 }}><span className="num" style={{ fontSize: 20, fontWeight: 700 }}>{d.name}</span><span className="small" style={{ fontWeight: 600 }}>{d.member_code}{d.plan ? ` · ${d.plan.name}` : ''}</span></div>
            <span className="num small" style={{ fontWeight: 700 }}>{m.gym.name}</span>
          </div>
          <div className="qr"><canvas ref={canvas} role="img" aria-label="Check-in QR code" /></div>
          <span className="small" style={{ fontWeight: 600, textAlign: 'center' }}>{t('Hold this at the scanner to punch IN or OUT')}</span>
          <span className="small" style={{ textAlign: 'center' }}>{t('New code every 30 seconds. Screenshots will not work.')} ({left}s)</span>
        </section>
        {!d.access.allowed && <div className="banner">Entry is blocked: {d.access.reason}. <Link to={m.base + '/renew'}>{t('Renew')}</Link></div>}
        <section className="card" style={{ borderRadius: 20, padding: 16 }}>
          <div className="row between"><span className="muted">{t('Valid till')}</span><b>{d.plan ? date(d.plan.end_date) : '—'}</b></div>
          <div className="row between"><span className="muted">{t('Branches')}</span><b style={{ textAlign: 'right' }}>{d.plan?.all_branches ? d.branches.map((b) => b.name).join(', ') : d.branches[0]?.name}</b></div>
          <div className="row between"><span className="muted">{t('Other ways in')}</span><b>{d.methods.join(', ') || '—'}</b></div>
        </section>
        <Link to={m.base + '/visits'} className="btn block" style={{ height: 48 }}>{t('See my visit history')} →</Link>
      </>}
    </div>
  );
}
