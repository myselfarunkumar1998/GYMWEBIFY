import { useState } from 'react';
import { useI18n } from '../lib/i18n.jsx';
import { mins, time, dayDate, today, titleCase } from '../lib/format.js';
import { Loading, useApi, Empty } from '../components/ui.jsx';
import { BackHeader } from './MemberApp.jsx';

export default function Visits() {
  const { t } = useI18n();
  const [m, setM] = useState(today().slice(0, 7));
  const { data } = useApi('/m/visits', { month: m });
  return (
    <div className="phone-body">
      <BackHeader title={t('My visits')} right={<input type="month" className="input" style={{ width: 150 }} aria-label="Month" value={m} max={today().slice(0, 7)} onChange={(e) => e.target.value && setM(e.target.value)} />} />
      {!data ? <Loading /> : <>
        <section className="card hero" style={{ borderRadius: 22, display: 'grid', gridTemplateColumns: 'repeat(3,minmax(0,1fr))', gap: 8 }}>
          <div className="col" style={{ gap: 2 }}><span className="small" style={{ fontWeight: 600 }}>{t('visits')}</span><span className="num" style={{ fontSize: 26, fontWeight: 700 }}>{data.visits}</span></div>
          <div className="col" style={{ gap: 2 }}><span className="small" style={{ fontWeight: 600 }}>{t('Time in gym')}</span><span className="num" style={{ fontSize: 26, fontWeight: 700 }}>{mins(data.total_min)}</span></div>
          <div className="col" style={{ gap: 2 }}><span className="small" style={{ fontWeight: 600 }}>{t('Avg stay')}</span><span className="num" style={{ fontSize: 26, fontWeight: 700 }}>{mins(data.avg_min)}</span></div>
        </section>
        <section className="card" style={{ padding: '4px 16px', gap: 0, borderRadius: 20 }} aria-label="Visit list">
          {data.items.length === 0 ? <Empty>No visits this month.</Empty> : data.items.map((v, i) => (
            <div key={i} className="listrow" style={{ minHeight: 60 }}>
              <div className="grow col" style={{ gap: 2 }}><span style={{ fontWeight: 600 }}>{dayDate(v.in_at)}</span>
                <span className={'small ' + (v.auto_closed ? 'warn' : 'muted')}>{!v.out_at ? `In ${time(v.in_at)} · ${t('still inside')}` : v.auto_closed ? `In ${time(v.in_at)} · no punch out` : `${time(v.in_at)} – ${time(v.out_at)} · ${titleCase(v.method)}`}</span></div>
              <span className={'num ' + (!v.out_at ? 'lime' : v.auto_closed ? 'warn' : '')} style={{ fontSize: 16, fontWeight: 600 }}>{v.auto_closed ? t('Not counted') : !v.out_at ? '…' : mins(v.duration_min)}</span>
            </div>
          ))}
        </section>
        <span className="small muted">{t('Forgot to punch out? The session closes at 10:00 PM and is not counted in your time. Ask the front desk to fix it.')}</span>
      </>}
    </div>
  );
}
