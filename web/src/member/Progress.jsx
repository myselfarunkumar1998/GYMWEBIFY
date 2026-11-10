import { useState } from 'react';
import { get, post, resizeImage } from '../lib/api.js';
import { useI18n } from '../lib/i18n.jsx';
import { date, dateShort, parseDate } from '../lib/format.js';
import { Input, Loading, Modal, useAction, useApi, Empty } from '../components/ui.jsx';
import { BackHeader, useMember } from './MemberApp.jsx';

export default function Progress() {
  const m = useMember();
  const { t } = useI18n();
  const { data, reload } = useApi('/m/progress');
  const strava = useApi(m.member.strava_connected ? '/m/strava/activities' : null);
  const [logW, setLogW] = useState(false);
  const { run } = useAction();
  if (!data) return <div className="phone-body"><Loading /></div>;
  const a = data.assessments;
  const first = a[0]; const last = a[a.length - 1];
  const delta = (k, unit) => (first && last && first[k] && last[k] && first !== last ? `${+(last[k] - first[k]).toFixed(1) > 0 ? '+' : '−'}${Math.abs(+(last[k] - first[k]).toFixed(1))} ${unit}` : null);
  const weights = a.filter((x) => x.weight_kg).slice(-6);
  const wmin = Math.min(...weights.map((x) => +x.weight_kg)) - 2;
  const wmax = Math.max(...weights.map((x) => +x.weight_kg));
  return (
    <div className="phone-body">
      <BackHeader title={t('My progress')} sub={data.joined ? `Since ${new Date(data.joined).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })}` : null} />
      {a.length === 0 ? <Empty>No measurements yet. Log your weight or ask your trainer for an assessment.</Empty> : <>
        <section className="grid3">
          {[['weight_kg', t('Weight'), 'kg'], ['body_fat_pct', t('Body fat'), '%'], ['waist_in', t('Waist'), 'in']].map(([k, l, u]) => (
            <div key={k} className="card tight" style={{ borderRadius: 16 }}><span className="small muted">{l}</span><span className="num" style={{ fontSize: 20, fontWeight: 700 }}>{last[k] ? `${+last[k]} ${u}` : '—'}</span>{delta(k, u) && <span className={'small ' + (delta(k, u).startsWith('−') ? 'lime' : 'warn')}>{delta(k, u)}</span>}</div>
          ))}
        </section>
        {weights.length > 1 && (
          <section className="card" style={{ borderRadius: 20, padding: 16 }}>
            <div className="row between"><h2 style={{ fontSize: 16 }}>{t('Weight')}</h2><span className="small muted">Last {weights.length} entries</span></div>
            <div className="bars" style={{ height: 130, gap: 10 }} role="img" aria-label={`Weight from ${weights[0].weight_kg} to ${last.weight_kg} kg`}>
              {weights.map((w, i) => <div key={i} className="bar"><span style={{ color: i === weights.length - 1 ? 'var(--lime)' : 'var(--text-2)', fontWeight: 600 }}>{+w.weight_kg}</span><div className={i === weights.length - 1 ? 'hi' : ''} style={{ height: Math.max(6, ((w.weight_kg - wmin) / (wmax - wmin || 1)) * 80) }} /><span>{dateShort(w.assessed_on)}</span></div>)}
            </div>
          </section>
        )}
        {a.some((x) => +x.has_photo) && <section className="card" style={{ borderRadius: 20 }}><h2 style={{ fontSize: 16 }}>Progress photos</h2>
          <div className="row" style={{ overflowX: 'auto' }}>{a.filter((x) => +x.has_photo).map((x) => <figure key={x.id} style={{ margin: 0 }}><img src={`/api/m/progress/photo/${x.id}`} alt={`Progress photo ${date(x.assessed_on)}`} style={{ width: 110, height: 140, objectFit: 'cover', borderRadius: 12 }} /><figcaption className="small muted">{dateShort(x.assessed_on)}</figcaption></figure>)}</div></section>}
      </>}
      <section className="card" style={{ borderRadius: 20, padding: '14px 16px 6px', gap: 0 }}>
        <h2 style={{ fontSize: 16, marginBottom: 8 }}>{t('Personal records')}</h2>
        {data.records.length === 0 ? <span className="small muted" style={{ paddingBottom: 10 }}>Log your sets in Workouts to track records.</span> : data.records.map((r, i) => (
          <div key={i} className="listrow" style={{ minHeight: 46 }}><div className="grow col" style={{ gap: 2 }}><span>{r.name}</span><span className="small muted">{dateShort(r.achieved_on)}</span></div><span className="num" style={{ fontWeight: 600 }}>{r.value}</span></div>
        ))}
      </section>
      <section className="card" style={{ borderRadius: 20 }}>
        <div className="row between"><h2 style={{ fontSize: 16 }}>Strava</h2>
          {m.member.strava_connected ? <button className="btn sm" onClick={async () => { await run(() => post('/m/strava/disconnect')); m.reload(); }}>Disconnect</button>
            : <button className="btn sm ghost" onClick={async () => { const r = await run(() => get('/m/strava/connect')); if (r) window.location.href = r.url; }}>{t('Connect Strava')}</button>}</div>
        {strava.data?.items?.map((x, i) => <div key={i} className="row between small"><span>{x.name} · {x.type}</span><span className="muted">{x.distance_km} km · {x.moving_min} min</span></div>)}
        {!m.member.strava_connected && <span className="small muted">Bring in your runs and rides from Strava. Apple Health and Google Fit sync through Strava.</span>}
      </section>
      <div className="grid2">
        <label className="btn block" style={{ height: 48 }}>{t('Add photo')}<input type="file" accept="image/*" capture="environment" hidden onChange={async (e) => { const f = e.target.files[0]; if (!f) return; const w = window.prompt('Your weight today (kg)?', last?.weight_kg ? String(+last.weight_kg) : ''); if (!w) return; if (await run(async () => post('/m/progress/weight', { weight_kg: Number(w), photo: await resizeImage(f, 1000) }), 'Saved')) reload(); }} /></label>
        <button className="btn primary block" style={{ height: 48 }} onClick={() => setLogW(true)}>{t('Log weight')}</button>
      </div>
      {logW && <WeightModal onClose={() => setLogW(false)} onDone={() => { setLogW(false); reload(); }} last={last} />}
    </div>
  );
}

function WeightModal({ onClose, onDone, last }) {
  const [w, setW] = useState(last?.weight_kg ? String(+last.weight_kg) : '');
  const { run, busy, fields } = useAction();
  return (
    <Modal title="Log weight" onClose={onClose} footer={<button className="btn primary block" disabled={busy || !w} onClick={async () => { if (await run(() => post('/m/progress/weight', { weight_kg: Number(w) }), 'Saved')) onDone(); }}>Save</button>}>
      <Input label="Weight (kg)" type="number" inputMode="decimal" step="0.1" value={w} onChange={(e) => setW(e.target.value)} error={fields.weight_kg} autoFocus />
    </Modal>
  );
}
