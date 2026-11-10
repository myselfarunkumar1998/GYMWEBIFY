import { useState } from 'react';
import { post } from '../lib/api.js';
import { useI18n } from '../lib/i18n.jsx';
import { hm, today, addDays, DOW, parseDate, dateTime, time } from '../lib/format.js';
import { Loading, Modal, Select, useAction, useApi, Empty, Tabs } from '../components/ui.jsx';
import { BackHeader } from './MemberApp.jsx';

export default function Book() {
  const { t } = useI18n();
  const [d, setD] = useState(today());
  const [tab, setTab] = useState('classes');
  const { data, reload } = useApi('/m/classes', { date: d });
  const res = useApi(tab === 'resources' ? '/m/resources' : null, { date: d });
  const [pt, setPt] = useState(false);
  const { run, busy } = useAction();
  const days = Array.from({ length: 7 }, (_, i) => addDays(today(), i));
  return (
    <div className="phone-body">
      <BackHeader title={t('Book a class')} />
      <div className="row" style={{ overflowX: 'auto', gap: 6 }} role="group" aria-label="Day">
        {days.map((x) => { const dt = parseDate(x + ' 12:00'); return (
          <button key={x} className={'chip' + (d === x ? ' on' : '')} style={{ height: 56, minWidth: 52, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', borderRadius: 14 }} aria-pressed={d === x} onClick={() => setD(x)}>
            <span style={{ fontSize: 11 }}>{DOW[dt.getDay()]}</span><span className="num" style={{ fontSize: 16 }}>{dt.getDate()}</span></button>); })}
      </div>
      <Tabs items={[{ value: 'classes', label: 'Classes' }, { value: 'pt', label: t('Personal training') }, { value: 'resources', label: 'Courts & rooms' }]} value={tab} onChange={setTab} />
      {!data ? <Loading /> : tab === 'classes' ? (
        data.classes.length === 0 ? <Empty>No classes on this day.</Empty> : data.classes.map((c) => {
          const full = c.booked >= c.capacity;
          const mine = c.my_status === 'booked' || c.my_status === 'waitlist' || c.my_status === 'attended';
          const past = d === today() && c.start_time < new Date().toTimeString().slice(0, 8);
          return (
            <section key={c.id} className="card" style={{ borderRadius: 18, padding: 16 }}>
              <div className="row between"><div className="col" style={{ gap: 2 }}><span className="small muted">{hm(c.start_time)} · {c.duration_min} min · {c.room}</span><b style={{ fontSize: 16 }}>{c.name}</b><span className="small t2">Coach {c.coach || '—'} · {c.branch}</span></div>
                <span className={'small ' + (full ? 'warn' : 'lime')} style={{ fontWeight: 600 }}>{full ? `${t('Waitlist')}` : `${c.capacity - c.booked} ${t('spots left')}`}</span></div>
              {mine ? (
                <div className="row between"><span className="pill">{c.my_status === 'waitlist' ? `${t('Waitlist')} #${c.my_waitlist_pos}` : t('Booked')}</span>
                  {c.my_status !== 'attended' && !past && <button className="btn sm danger" disabled={busy} onClick={async () => { const r = await run(() => post(`/m/bookings/${c.my_booking_id}/cancel`)); if (r) { if (r.late_fee) alert('Late cancellation: a fee was added to your dues.'); reload(); } }}>{t('Cancel booking')}</button>}</div>
              ) : !past && <button className="btn primary block" disabled={busy} onClick={async () => { const r = await run(() => post(`/m/classes/${c.id}/book`, { date: d })); if (r) { alert(r.status === 'waitlist' ? `You are #${r.waitlist_pos} on the waitlist. We will message you if a spot opens.` : 'Booked! See you there.'); reload(); } }}>{full ? t('Join waitlist') : t('Book')}</button>}
            </section>
          );
        })
      ) : tab === 'pt' ? (
        <>
          <section className="card" style={{ borderRadius: 18 }}>
            <div className="row between"><b>{t('PT sessions left')}</b><span className="num" style={{ fontSize: 22, fontWeight: 700 }}>{data.pt_left ?? 0}</span></div>
            {data.pt_left > 0 ? <button className="btn primary block" onClick={() => setPt(true)}>{t('Book PT session')}</button> : <span className="small muted">Buy a PT pack in Pay to book sessions with a trainer.</span>}
          </section>
          {data.pt.length === 0 ? <Empty>No upcoming PT sessions.</Empty> : data.pt.map((p) => (
            <div key={p.id} className="card tight" style={{ flexDirection: 'row', alignItems: 'center' }}><div className="grow col" style={{ gap: 2 }}><b>{dateTime(p.starts_at)}</b><span className="small muted">with {p.trainer}</span></div>
              <button className="btn sm danger" onClick={async () => { if (await run(() => post(`/m/pt/${p.id}/cancel`), 'Cancelled')) reload(); }}>Cancel</button></div>
          ))}
        </>
      ) : (
        !res.data ? <Loading /> : res.data.items.length === 0 ? <Empty>Nothing to book at this gym.</Empty> : res.data.items.map((r) => <ResourceCard key={r.id} r={r} date={d} onDone={res.reload} />)
      )}
      {pt && <PtBooking trainers={data.trainers} onClose={() => setPt(false)} onDone={() => { setPt(false); reload(); }} />}
    </div>
  );
}

function PtBooking({ trainers, onClose, onDone }) {
  const [tr, setTr] = useState(trainers[0]?.id || '');
  const [d, setD] = useState(addDays(today(), 1));
  const slots = useApi(tr ? '/m/pt/slots' : null, { trainer_id: tr, date: d });
  const { run, busy } = useAction();
  return (
    <Modal title="Book PT session" onClose={onClose}>
      <Select label="Trainer" value={tr} onChange={(e) => setTr(e.target.value)} options={trainers.map((x) => ({ value: x.id, label: `${x.name}${x.title ? ' · ' + x.title : ''}` }))} />
      <input type="date" className="input" aria-label="Date" value={d} min={today()} onChange={(e) => setD(e.target.value)} />
      {!slots.data ? <Loading /> : slots.data.length === 0 ? <Empty>No free slots that day.</Empty> : (
        <div className="grid3">{slots.data.map((s) => <button key={s} className="btn" disabled={busy} onClick={async () => { if (await run(() => post('/m/pt', { trainer_id: Number(tr), starts_at: s }), 'Session booked')) onDone(); }}>{time(s)}</button>)}</div>
      )}
    </Modal>
  );
}

function ResourceCard({ r, date, onDone }) {
  const { run, busy } = useAction();
  const slots = [];
  for (let m = toMin(r.open_time); m + r.slot_minutes <= toMin(r.close_time); m += r.slot_minutes) slots.push(m);
  const taken = new Set(r.taken.map((x) => toMin(x.slice(11))));
  const now = date === today() ? new Date().getHours() * 60 + new Date().getMinutes() : -1;
  return (
    <section className="card" style={{ borderRadius: 18 }}>
      <b>{r.name}</b><span className="small muted">{r.slot_minutes} min · {Number(r.price) ? `₹${r.price} added to your dues` : 'Free'}</span>
      <div className="grid3">{slots.map((m) => {
        const label = `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
        const off = taken.has(m) || m <= now;
        return <button key={m} className="btn sm" disabled={off || busy} onClick={async () => { if (await run(() => post(`/m/resources/${r.id}/book`, { starts_at: `${date} ${label}:00` }), 'Booked')) onDone(); }}>{hm(label)}</button>;
      })}</div>
    </section>
  );
}
const toMin = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
