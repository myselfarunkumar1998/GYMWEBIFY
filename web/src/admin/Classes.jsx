import { useState } from 'react';
import { Link } from 'react-router-dom';
import { post, put, del } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { hm, time, today, dayDate, DOW, titleCase, addDays } from '../lib/format.js';
import { ErrorBox, Input, Loading, Modal, Pill, Progress, Select, Tabs, useAction, useApi, Empty } from '../components/ui.jsx';
import MemberSearch from '../components/MemberSearch.jsx';

export default function Classes() {
  const s = useStaff();
  const { t } = useI18n();
  const [d, setD] = useState(today());
  const [tab, setTab] = useState('today');
  const { data, error, loading, reload } = useApi('/classes', { date: d });
  const [cls, setCls] = useState(null);
  const [bookings, setBookings] = useState(null);
  const [pt, setPt] = useState(false);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  return (
    <>
      <header className="topbar">
        <div className="col gap4"><h1>Classes &amp; personal training</h1><span className="muted">{dayDate(d)} · members book from the app</span></div>
        <div className="row wrap">
          <button className="btn sm" aria-label="Previous day" onClick={() => setD(addDays(d, -1))}>‹</button>
          <input type="date" className="input" style={{ width: 160 }} aria-label="Date" value={d} onChange={(e) => setD(e.target.value)} />
          <button className="btn sm" aria-label="Next day" onClick={() => setD(addDays(d, 1))}>›</button>
          {s.can('workouts.manage') && <Link className="btn" to="/admin/members">Workout &amp; diet builder</Link>}
          {s.can('pt.manage') && <button className="btn" onClick={() => setPt(true)}>Book PT session</button>}
          {s.can('classes.manage') && <button className="btn primary" onClick={() => setCls({ name: '', branch_id: s.branches[0]?.id, coach_id: '', room: '', day_of_week: new Date(d).getDay(), start_time: '06:30', duration_min: 60, capacity: 20, late_cancel_fee: 0, no_show_fee: 0 })}>+ Add class</button>}
        </div>
      </header>
      <Tabs items={[{ value: 'today', label: 'Day view' }, { value: 'week', label: 'Weekly timetable' }, { value: 'resources', label: 'Courts & rooms' }]} value={tab} onChange={setTab} />
      {tab === 'today' && <>
        <section className="col gap8">
          <h2>Classes</h2>
          {data.today.length === 0 ? <Empty>No classes on this day.</Empty> : (
            <div className="grid4">{data.today.map((c) => {
              const full = c.booked >= c.capacity;
              return (
                <button key={c.id} className="card" style={{ textAlign: 'left', cursor: 'pointer' }} onClick={() => setBookings(c)}>
                  <span className="small muted">{hm(c.start_time)} · {c.room}{s.branches.length > 1 ? ` · ${c.branch_name}` : ''}</span>
                  <span className="num" style={{ fontSize: 20, fontWeight: 600 }}>{c.name}</span>
                  <span className="small t2">Coach {c.coach_name || '—'}</span>
                  <Progress pct={(c.booked / c.capacity) * 100} />
                  <div className="row between small"><span>{c.booked} / {c.capacity} booked</span><span className={full ? 'warn' : 'lime'}>{c.waitlist > 0 ? `${c.waitlist} on waitlist` : `${c.capacity - c.booked} spots left`}</span></div>
                </button>
              );
            })}</div>
          )}
        </section>
        <div className="split">
          <section className="card main">
            <h2>PT sessions</h2>
            {data.pt.length === 0 ? <Empty>No PT sessions.</Empty> : (
              <div className="tablebox"><table className="t" style={{ minWidth: 520 }}>
                <thead><tr><th>Time</th><th>Member</th><th>Trainer</th><th>Pack</th><th>Status</th><th></th></tr></thead>
                <tbody>{data.pt.map((p) => <PtRow key={p.id} p={p} onDone={reload} />)}</tbody>
              </table></div>
            )}
          </section>
          <section className="card side">
            <h2>Trainers on floor</h2>
            {data.trainers.map((tr) => (
              <div key={tr.id} className="listrow">
                <span className="avatar">{tr.name.slice(0, 2).toUpperCase()}</span>
                <div className="grow col" style={{ gap: 0 }}><span style={{ fontWeight: 500 }}>{tr.name}</span><span className="small muted">{tr.in_at ? `IN ${time(tr.in_at)}` : 'Not in yet'} · {tr.pt_today} PT</span></div>
                <span className={'small ' + (tr.in_at && !tr.out_at ? 'lime' : 'muted')} style={{ fontWeight: 600 }}>{tr.in_at && !tr.out_at ? 'On floor' : tr.out_at ? 'Left' : 'Off'}</span>
              </div>
            ))}
            <span className="small muted">Trainer attendance comes from the same fingerprint device.</span>
          </section>
        </div>
      </>}
      {tab === 'week' && (
        <section className="card">
          <div className="tablebox"><table className="t"><thead><tr><th>Day</th><th>Time</th><th>Class</th><th>Coach</th><th>Room</th><th>Capacity</th><th>Status</th><th></th></tr></thead>
            <tbody>{data.schedule.map((c) => (
              <tr key={c.id} style={{ opacity: c.is_active ? 1 : 0.5 }}><td>{DOW[c.day_of_week]}</td><td>{hm(c.start_time)} · {c.duration_min}m</td><td style={{ fontWeight: 600 }}>{c.name}</td><td>{c.coach_name || '—'}</td><td>{c.room}</td><td>{c.capacity}</td>
                <td>{c.is_active ? <Pill>Active</Pill> : <Pill tone="grey">Off</Pill>}</td>
                <td className="r">{s.can('classes.manage') && <button className="btn sm" onClick={() => setCls({ ...c, start_time: c.start_time.slice(0, 5), coach_id: c.coach_id || '' })}>Edit</button>}</td></tr>))}</tbody></table></div>
        </section>
      )}
      {tab === 'resources' && <Resources date={d} />}
      {cls && <ClassForm c={cls} onClose={() => setCls(null)} onDone={() => { setCls(null); reload(); }} />}
      {bookings && <Bookings c={bookings} date={d} onClose={() => { setBookings(null); reload(); }} />}
      {pt && <PtForm date={d} onClose={() => setPt(false)} onDone={() => { setPt(false); reload(); }} />}
    </>
  );
}

function PtRow({ p, onDone }) {
  const s = useStaff();
  const { run } = useAction();
  const setStatus = async (status) => { if (await run(() => put('/pt/' + p.id, { status }), 'Updated')) onDone(); };
  return (
    <tr>
      <td className="num">{time(p.starts_at)}</td><td style={{ fontWeight: 500 }}><Link to={'/admin/members/' + p.member_id} style={{ color: 'inherit' }}>{p.member_name}</Link></td><td>{p.trainer_name}</td>
      <td className="muted">{p.sessions_total ? `${p.sessions_total - p.sessions_used} of ${p.sessions_total} left` : '—'}</td>
      <td><Pill tone={p.status === 'booked' ? 'grey' : p.status === 'done' ? undefined : 'warn'}>{titleCase(p.status)}</Pill></td>
      <td className="r" style={{ whiteSpace: 'nowrap' }}>{p.status === 'booked' && s.can('pt.manage') && <>
        <button className="btn sm ghost" onClick={() => setStatus('done')}>Done</button>{' '}
        <button className="btn sm" onClick={() => setStatus('no_show')}>No-show</button>{' '}
        <button className="btn sm danger" onClick={() => setStatus('cancelled')}>Cancel</button></>}</td>
    </tr>
  );
}

function ClassForm({ c, onClose, onDone }) {
  const s = useStaff();
  const staff = useApi('/staff');
  const [f, setF] = useState(c);
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const save = async () => {
    const body = { name: f.name, branch_id: Number(f.branch_id), coach_id: f.coach_id ? Number(f.coach_id) : null, room: f.room || null, day_of_week: Number(f.day_of_week), start_time: f.start_time,
      duration_min: Number(f.duration_min), capacity: Number(f.capacity), late_cancel_fee: Number(f.late_cancel_fee || 0), no_show_fee: Number(f.no_show_fee || 0), is_active: f.is_active === undefined ? true : !!Number(f.is_active) };
    if (await run(() => (c.id ? put('/classes/' + c.id, body) : post('/classes', body)), 'Class saved')) onDone();
  };
  return (
    <Modal title={c.id ? 'Edit class' : 'Add class'} onClose={onClose} footer={<>
      {c.id && <button className="btn danger" onClick={async () => { if (await run(() => del('/classes/' + c.id), 'Class turned off')) onDone(); }}>Turn off</button>}
      <button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={busy} onClick={save}>Save</button></>}>
      <div className="grid2">
        <Input label="Name" value={f.name} onChange={set('name')} error={fields.name} />
        <Select label="Coach" value={f.coach_id} onChange={set('coach_id')} placeholder="—" options={(staff.data?.items || []).filter((u) => ['trainer', 'manager', 'owner'].includes(u.role)).map((u) => ({ value: u.id, label: u.name }))} />
        <Select label="Day" value={f.day_of_week} onChange={set('day_of_week')} options={DOW.map((x, i) => ({ value: i, label: x }))} />
        <Input label="Start" type="time" value={f.start_time} onChange={set('start_time')} error={fields.start_time} />
        <Input label="Minutes" type="number" value={f.duration_min} onChange={set('duration_min')} />
        <Input label="Capacity" type="number" value={f.capacity} onChange={set('capacity')} />
        <Input label="Room" value={f.room || ''} onChange={set('room')} />
        {s.branches.length > 1 && <Select label="Branch" value={f.branch_id} onChange={set('branch_id')} options={s.branches.map((b) => ({ value: b.id, label: b.name }))} />}
        <Input label="No-show fee (₹)" type="number" value={f.no_show_fee} onChange={set('no_show_fee')} />
        <Input label="Late-cancel fee (₹)" type="number" value={f.late_cancel_fee} onChange={set('late_cancel_fee')} />
      </div>
    </Modal>
  );
}

function Bookings({ c, date, onClose }) {
  const s = useStaff();
  const { data, reload } = useApi(`/classes/${c.id}/bookings`, { date });
  const { run } = useAction();
  const book = async (m) => { if (await run(() => post(`/classes/${c.id}/bookings`, { member_id: m.id, date }), 'Booked')) reload(); };
  const setStatus = async (b, status) => { if (await run(() => put('/bookings/' + b.id, { status }))) reload(); };
  return (
    <Modal title={`${c.name} · ${dayDate(date)} ${hm(c.start_time)}`} wide onClose={onClose}>
      {s.can('pt.manage') && <MemberSearch onPick={book} placeholder="Add a member to this class" />}
      {!data ? <Loading /> : data.items.length === 0 ? <Empty>No bookings.</Empty> : (
        <table className="t"><thead><tr><th>Member</th><th>Status</th><th></th></tr></thead>
          <tbody>{data.items.map((b) => (
            <tr key={b.id}><td>{b.name} <span className="small muted">{b.member_code}</span></td>
              <td><Pill tone={b.status === 'booked' || b.status === 'attended' ? undefined : b.status === 'waitlist' ? 'grey' : 'warn'}>{b.status === 'waitlist' ? `Waitlist #${b.waitlist_pos}` : titleCase(b.status)}</Pill></td>
              <td className="r" style={{ whiteSpace: 'nowrap' }}>{['booked', 'waitlist'].includes(b.status) && s.can('pt.manage') && <>
                {b.status === 'booked' && <><button className="btn sm ghost" onClick={() => setStatus(b, 'attended')}>Attended</button>{' '}<button className="btn sm" onClick={() => setStatus(b, 'no_show')}>No-show</button>{' '}</>}
                <button className="btn sm danger" onClick={() => setStatus(b, 'cancelled')}>Cancel</button></>}</td></tr>))}</tbody></table>
      )}
      <span className="small muted">Cancelling a booked place moves the first person on the waitlist in and messages them.</span>
    </Modal>
  );
}

function PtForm({ date, onClose, onDone }) {
  const s = useStaff();
  const staff = useApi('/staff');
  const [m, setM] = useState(null);
  const [f, setF] = useState({ trainer_id: s.user.role === 'trainer' ? s.user.id : '', at: date + 'T07:00', duration_min: 60 });
  const { run, busy } = useAction();
  return (
    <Modal title="Book PT session" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy || !m || !f.trainer_id} onClick={async () => { if (await run(() => post('/pt', { member_id: m.id, trainer_id: Number(f.trainer_id), starts_at: f.at.replace('T', ' ') + ':00', duration_min: Number(f.duration_min) }), 'Session booked')) onDone(); }}>Book</button></>}>
      {m ? <div className="inner">{m.name} · {m.member_code} · {m.pt_left ?? 0} PT sessions left</div> : <MemberSearch onPick={setM} />}
      <div className="grid2">
        <Select label="Trainer" value={f.trainer_id} placeholder="Choose" onChange={(e) => setF({ ...f, trainer_id: e.target.value })} options={(staff.data?.items || []).filter((u) => u.role === 'trainer' && u.status === 'active').map((u) => ({ value: u.id, label: u.name }))} />
        <Input label="When" type="datetime-local" value={f.at} onChange={(e) => setF({ ...f, at: e.target.value })} />
      </div>
      <span className="small muted">Needs an active PT pack. Done and no-show both use up one session.</span>
    </Modal>
  );
}

function Resources({ date }) {
  const s = useStaff();
  const { data, reload } = useApi('/resources', { date });
  const [edit, setEdit] = useState(null);
  const [book, setBook] = useState(null);
  if (!data) return <Loading />;
  return (
    <section className="col gap16">
      <div className="row between"><span className="muted">Courts, pool lanes and recovery rooms members can book by the slot.</span>{s.can('classes.manage') && <button className="btn" onClick={() => setEdit({ name: '', branch_id: s.branches[0]?.id, slot_minutes: 60, price: 0, open_time: '06:00', close_time: '21:00' })}>+ Add resource</button>}</div>
      {data.length === 0 && <Empty>No bookable resources yet.</Empty>}
      <div className="grid3">{data.map((r) => (
        <div key={r.id} className="card">
          <div className="row between"><h3>{r.name}</h3>{s.can('classes.manage') && <button className="btn sm" onClick={() => setEdit({ ...r, open_time: r.open_time.slice(0, 5), close_time: r.close_time.slice(0, 5) })}>Edit</button>}</div>
          <span className="small muted">{r.branch_name} · {r.slot_minutes} min slots · {Number(r.price) ? `₹${r.price}` : 'Free'} · {hm(r.open_time)}–{hm(r.close_time)}</span>
          {r.bookings.length === 0 ? <span className="small muted">No bookings on this day.</span> : r.bookings.map((b) => <div key={b.id} className="row between small"><span>{time(b.starts_at)}</span><span>{b.name}</span></div>)}
          {s.can('pt.manage') && <button className="btn sm ghost" onClick={() => setBook(r)}>Book for a member</button>}
        </div>))}</div>
      {edit && <ResourceForm r={edit} onClose={() => setEdit(null)} onDone={() => { setEdit(null); reload(); }} />}
      {book && <BookResource r={book} date={date} onClose={() => setBook(null)} onDone={() => { setBook(null); reload(); }} />}
    </section>
  );
}

function ResourceForm({ r, onClose, onDone }) {
  const s = useStaff();
  const [f, setF] = useState(r);
  const { run, busy } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  return (
    <Modal title={r.id ? 'Edit resource' : 'Add resource'} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy} onClick={async () => { const b = { name: f.name, branch_id: Number(f.branch_id), slot_minutes: Number(f.slot_minutes), price: Number(f.price || 0), open_time: f.open_time, close_time: f.close_time, is_active: true }; if (await run(() => (r.id ? put('/resources/' + r.id, b) : post('/resources', b)), 'Saved')) onDone(); }}>Save</button></>}>
      <div className="grid2">
        <Input label="Name" value={f.name} onChange={set('name')} />
        {s.branches.length > 1 && <Select label="Branch" value={f.branch_id} onChange={set('branch_id')} options={s.branches.map((b) => ({ value: b.id, label: b.name }))} />}
        <Input label="Slot (minutes)" type="number" value={f.slot_minutes} onChange={set('slot_minutes')} />
        <Input label="Price per slot (₹)" type="number" value={f.price} onChange={set('price')} />
        <Input label="Opens" type="time" value={f.open_time} onChange={set('open_time')} />
        <Input label="Closes" type="time" value={f.close_time} onChange={set('close_time')} />
      </div>
    </Modal>
  );
}

function BookResource({ r, date, onClose, onDone }) {
  const [m, setM] = useState(null);
  const [at, setAt] = useState(date + 'T' + r.open_time.slice(0, 5));
  const { run, busy } = useAction();
  return (
    <Modal title={`Book ${r.name}`} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button>
      <button className="btn primary" disabled={busy || !m} onClick={async () => { if (await run(() => post(`/resources/${r.id}/book`, { member_id: m.id, starts_at: at.replace('T', ' ') + ':00' }), 'Booked')) onDone(); }}>Book</button></>}>
      {m ? <div className="inner">{m.name}</div> : <MemberSearch onPick={setM} />}
      <Input label="Start" type="datetime-local" value={at} onChange={(e) => setAt(e.target.value)} />
    </Modal>
  );
}
