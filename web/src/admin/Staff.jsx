import { useState } from 'react';
import { post, put, download } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { money, time, month as thisMonth, titleCase, date } from '../lib/format.js';
import { ErrorBox, Input, Loading, Modal, Pill, Select, Stat, useAction, useApi, Empty } from '../components/ui.jsx';

const ROLES = [['manager', 'Manager', 'Members, billing, leads, reports for own branch'], ['front_desk', 'Front desk', 'Add members, collect payments, enrol fingerprints'],
  ['trainer', 'Trainer', 'Own clients, workout and diet plans, PT schedule'], ['staff', 'Staff', 'Attendance only (housekeeping, etc.)']];

export default function Staff() {
  const s = useStaff();
  const [m, setM] = useState(thisMonth());
  const { data, error, loading, reload } = useApi('/staff', { month: m });
  const [edit, setEdit] = useState(null);
  const [payroll, setPayroll] = useState(false);
  const [att, setAtt] = useState(null);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  const pay = s.can('payroll.manage');
  const salary = data.items.filter((u) => u.status === 'active').reduce((a, u) => a + Number(u.salary || 0), 0);
  const comm = data.items.reduce((a, u) => a + Number(u.commission || 0), 0);
  return (
    <>
      <header className="topbar">
        <div className="col gap4"><h1>Staff &amp; payroll</h1><span className="muted">{new Date(m + '-01').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })} · attendance from the fingerprint device</span></div>
        <div className="row wrap">
          <input type="month" className="input" style={{ width: 160 }} aria-label="Month" value={m} max={thisMonth()} onChange={(e) => e.target.value && setM(e.target.value)} />
          {pay && <button className="btn" onClick={() => setPayroll(true)}>{data.payroll ? 'Open payroll' : 'Run payroll'}</button>}
          {s.can('staff.manage') && <button className="btn primary" onClick={() => setEdit({ name: '', email: '', phone: '', role: 'trainer', title: '', branch_id: '', device_user_id: '', shift_start: '06:00', salary: '', pt_commission_pct: '', sales_commission_pct: '', password: '' })}>+ Add staff</button>}
        </div>
      </header>
      <section className="grid4">
        <Stat label="Staff on duty now" value={`${data.on_duty} of ${data.total}`} />
        {pay && <Stat label="Salary payable" value={money(salary)} />}
        {pay && <Stat label="PT and sales commission" value={money(comm)} />}
        <Stat tone={data.late_total ? 'alert' : undefined} label="Late punches this month" value={data.late_total} sub="More than 15 min after shift start" />
      </section>
      <section className="card" aria-label="Staff list">
        <div className="tablebox"><table className="t" style={{ minWidth: 800 }}>
          <thead><tr><th>Name</th><th>Role</th><th>Today</th><th>Days present</th><th>PT sessions</th>{pay && <th className="r">Commission</th>}{pay && <th className="r">Salary</th>}<th>Access</th><th></th></tr></thead>
          <tbody>{data.items.map((u) => {
            const late = u.today_in && u.shift_start && u.today_in.slice(11, 16) > addMin(u.shift_start, 15);
            return (
              <tr key={u.id} style={{ opacity: u.status === 'active' ? 1 : 0.5 }}>
                <td style={{ fontWeight: 500 }}>{u.name}<br /><span className="small muted">{u.email}</span></td>
                <td>{u.title || titleCase(u.role)}{u.branch_name ? <span className="small muted"> · {u.branch_name}</span> : ''}</td>
                <td className={u.today_in && !u.today_out ? (late ? 'warn' : 'lime') : 'muted'}>{u.today_in ? (u.today_out ? `OUT ${time(u.today_out)}` : `IN ${time(u.today_in)}${late ? ' · late' : ''}`) : u.status === 'active' ? 'Not in' : 'Inactive'}</td>
                <td className="num">{u.days_present}</td><td className="num">{u.pt_sessions || '—'}</td>
                {pay && <td className="r num">{Number(u.commission) ? money(u.commission) : '—'}</td>}
                {pay && <td className="r num">{Number(u.salary) ? money(u.salary) : '—'}</td>}
                <td className="muted">{titleCase(u.role)}</td>
                <td className="r" style={{ whiteSpace: 'nowrap' }}>
                  <button className="btn sm" onClick={() => setAtt(u)}>Attendance</button>{' '}
                  {s.can('staff.manage') && <button className="btn sm" onClick={() => setEdit({ ...u, shift_start: u.shift_start?.slice(0, 5) || '', password: '' })}>Edit</button>}
                </td>
              </tr>
            );
          })}</tbody>
        </table></div>
      </section>
      <section className="card">
        <h2>Roles &amp; permissions</h2>
        <div className="grid4">
          <div className="inner col gap4"><b>Owner</b><span className="small muted">Everything, all branches, payroll, settings, integrations</span></div>
          {ROLES.map(([k, l, d]) => <div key={k} className="inner col gap4"><b>{l}</b><span className="small muted">{d}</span></div>)}
        </div>
      </section>
      {edit && <StaffForm u={edit} onClose={() => setEdit(null)} onDone={() => { setEdit(null); reload(); }} />}
      {payroll && <Payroll period={m} onClose={() => { setPayroll(false); reload(); }} />}
      {att && <Attendance u={att} month={m} onClose={() => setAtt(null)} />}
    </>
  );
}

const addMin = (t, n) => { const [h, mm] = t.split(':').map(Number); const x = h * 60 + mm + n; return `${String(Math.floor(x / 60)).padStart(2, '0')}:${String(x % 60).padStart(2, '0')}`; };

function StaffForm({ u, onClose, onDone }) {
  const s = useStaff();
  const [f, setF] = useState({ ...u, branch_id: u.branch_id || '' });
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const save = async () => {
    const body = { name: f.name, email: f.email, phone: f.phone || null, title: f.title || null, branch_id: f.branch_id ? Number(f.branch_id) : null, device_user_id: f.device_user_id || null,
      shift_start: f.shift_start || null, salary: Number(f.salary || 0), pt_commission_pct: Number(f.pt_commission_pct || 0), sales_commission_pct: Number(f.sales_commission_pct || 0) };
    if (u.role !== 'owner') body.role = f.role;
    if (u.id && u.role !== 'owner') body.status = f.status;
    if (f.password) body.password = f.password;
    if (await run(() => (u.id ? put('/staff/' + u.id, body) : post('/staff', body)), 'Saved')) onDone();
  };
  return (
    <Modal title={u.id ? `Edit ${u.name}` : 'Add staff'} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={busy} onClick={save}>Save</button></>}>
      <div className="grid2">
        <Input label="Name" value={f.name} onChange={set('name')} error={fields.name} />
        <Input label="Email (login)" type="email" value={f.email} onChange={set('email')} error={fields.email} />
        <Input label="Phone" value={f.phone || ''} onChange={set('phone')} error={fields.phone} />
        {u.role !== 'owner' && <Select label="Role" value={f.role} onChange={set('role')} options={ROLES.map(([v, l]) => ({ value: v, label: l }))} />}
        <Input label="Title" value={f.title || ''} onChange={set('title')} placeholder="e.g. Head trainer" />
        <Select label="Branch" value={f.branch_id} onChange={set('branch_id')} placeholder="All branches" options={s.branches.map((b) => ({ value: b.id, label: b.name }))} />
        <Input label="Biometric device ID" value={f.device_user_id || ''} onChange={set('device_user_id')} error={fields.device_user_id} />
        <Input label="Shift starts" type="time" value={f.shift_start || ''} onChange={set('shift_start')} />
        <Input label="Monthly salary (₹)" type="number" value={f.salary} onChange={set('salary')} />
        <Input label="PT commission %" type="number" value={f.pt_commission_pct} onChange={set('pt_commission_pct')} />
        <Input label="Sales commission %" type="number" value={f.sales_commission_pct} onChange={set('sales_commission_pct')} />
        {u.id && u.role !== 'owner' && <Select label="Status" value={f.status} onChange={set('status')} options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive (no login)' }]} />}
        <Input label={u.id ? 'New password (optional)' : 'Password'} type="password" autoComplete="new-password" value={f.password} onChange={set('password')} error={fields.password} hint="At least 10 characters with letters and numbers" />
      </div>
    </Modal>
  );
}

function Payroll({ period, onClose }) {
  const { data, reload } = useApi('/payroll/' + period);
  const [wd, setWd] = useState('');
  const { run, busy } = useAction();
  return (
    <Modal title={`Payroll · ${period}`} wide onClose={onClose}>
      <div className="row wrap">
        <Input label="Working days (blank = Mon–Sat)" type="number" value={wd} onChange={(e) => setWd(e.target.value)} />
        <button className="btn" style={{ alignSelf: 'flex-end' }} disabled={busy || data?.status === 'paid'} onClick={async () => { if (await run(() => post('/payroll/run', { period, working_days: wd ? Number(wd) : undefined }), 'Payroll calculated')) reload(); }}>{data?.items?.length ? 'Recalculate' : 'Calculate'}</button>
        {data?.items?.length > 0 && data.status !== 'paid' && <button className="btn primary" style={{ alignSelf: 'flex-end' }} disabled={busy} onClick={async () => { if (await run(() => post(`/payroll/${period}/paid`), 'Marked paid and added to expenses')) reload(); }}>Mark paid</button>}
        {data?.status === 'paid' && <Pill>Paid</Pill>}
      </div>
      <span className="small muted">Deductions apply only for absences beyond 4 paid leave days. Commission: PT % of each completed session's pack value, plus sales % of invoices the person created.</span>
      {!data ? <Loading /> : data.items.length === 0 ? <Empty>Not calculated yet.</Empty> : (
        <div className="tablebox"><table className="t"><thead><tr><th>Name</th><th>Present</th><th className="r">Base</th><th className="r">Deductions</th><th>PT</th><th className="r">Commission</th><th className="r">Net pay</th></tr></thead>
          <tbody>{data.items.map((i) => <tr key={i.id}><td>{i.name}<br /><span className="small muted">{i.title || titleCase(i.role)}</span></td><td>{i.days_present}/{i.working_days}</td><td className="r num">{money(i.base_salary)}</td><td className="r num warn">{Number(i.deductions) ? '−' + money(i.deductions) : '—'}</td><td>{i.pt_sessions}</td><td className="r num">{money(i.commission)}</td><td className="r num" style={{ fontWeight: 700 }}>{money(i.net)}</td></tr>)}
            <tr><td colSpan={6} style={{ fontWeight: 700 }}>Total</td><td className="r num" style={{ fontWeight: 700 }}>{money(data.total)}</td></tr></tbody></table></div>
      )}
      <button className="btn sm" style={{ alignSelf: 'flex-start' }} onClick={() => download('/reports/export/staff_attendance', { from: period + '-01', to: period + '-' + new Date(+period.slice(0, 4), +period.slice(5), 0).getDate() })}>Download attendance CSV</button>
    </Modal>
  );
}

function Attendance({ u, month, onClose }) {
  const { data } = useApi(`/staff/${u.id}/attendance`, { month });
  return (
    <Modal title={`${u.name} · attendance`} onClose={onClose}>
      {!data ? <Loading /> : data.length === 0 ? <Empty>No punches this month.</Empty> : (
        <table className="t"><thead><tr><th>Date</th><th>IN</th><th>OUT</th><th>Hours</th></tr></thead>
          <tbody>{data.map((a, i) => <tr key={i}><td>{date(a.in_at)}</td><td>{time(a.in_at)}</td><td>{a.out_at ? time(a.out_at) : '—'}</td><td>{a.duration_min ? (a.duration_min / 60).toFixed(1) : '—'}</td></tr>)}</tbody></table>
      )}
    </Modal>
  );
}
