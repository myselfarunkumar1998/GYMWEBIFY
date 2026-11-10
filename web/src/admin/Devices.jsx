import { useState } from 'react';
import { post, put, del } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { dateTime, titleCase } from '../lib/format.js';
import { Check, Confirm, ErrorBox, Input, Loading, Modal, Pill, Select, Stat, useAction, useApi, Empty } from '../components/ui.jsx';

export default function Devices() {
  const s = useStaff();
  const { data, error, loading, reload } = useApi('/devices');
  const [edit, setEdit] = useState(null);
  const [cmds, setCmds] = useState(null);
  const { run } = useAction();
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  const e = data.enrolled;
  return (
    <>
      <header className="topbar">
        <div className="col gap4"><h1>Devices &amp; access</h1><span className="muted">eSSL / ZKTeco fingerprint, face and turnstile devices over ADMS push</span></div>
        <button className="btn primary" onClick={() => setEdit({ name: '', serial_no: '', branch_id: s.branches[0]?.id, model: 'eSSL MB160', kind: 'fingerprint', direction: 'both', relay_controls_door: true, is_active: true })}>+ Add device</button>
      </header>
      <section className="grid4">
        <Stat label="Devices online" value={`${data.devices.filter((d) => Number(d.online)).length} of ${data.devices.length}`} />
        <Stat label="Fingerprints enrolled" value={`${e.fingerprint || 0} / ${e.members}`} />
        <Stat label="Faces enrolled" value={`${e.face || 0} / ${e.members}`} />
        <Stat label="Punches today" value={data.devices.reduce((a, d) => a + Number(d.punches_today), 0)} />
      </section>
      <section className="card">
        {data.devices.length === 0 ? <Empty>No devices yet.</Empty> : (
          <div className="tablebox"><table className="t" style={{ minWidth: 760 }}>
            <thead><tr><th>Device</th><th>Serial</th><th>Branch</th><th>Type</th><th>Status</th><th>Last seen</th><th>Pending</th><th></th></tr></thead>
            <tbody>{data.devices.map((d) => (
              <tr key={d.id}>
                <td style={{ fontWeight: 500 }}>{d.name}<br /><span className="small muted">{d.model}</span></td><td className="num small">{d.serial_no}</td><td>{d.branch_name}</td>
                <td>{titleCase(d.kind)} · {d.direction === 'both' ? 'IN & OUT' : d.direction.toUpperCase()}</td>
                <td>{Number(d.online) ? <Pill>Online</Pill> : <Pill tone="warn">Offline</Pill>}</td>
                <td className="small">{d.last_seen_at ? dateTime(d.last_seen_at) : 'Never'}</td><td>{d.pending_commands}</td>
                <td className="r" style={{ whiteSpace: 'nowrap' }}>
                  <button className="btn sm" onClick={() => setCmds(d)}>Commands</button>{' '}
                  <button className="btn sm ghost" onClick={async () => { const r = await run(() => post(`/devices/${d.id}/sync`)); if (r) { alert(`${r.queued} users queued. The device picks them up within a minute.`); reload(); } }}>Sync users</button>{' '}
                  <button className="btn sm" onClick={() => setEdit({ ...d, relay_controls_door: !!Number(d.relay_controls_door), is_active: !!Number(d.is_active) })}>Edit</button>
                </td>
              </tr>))}</tbody>
          </table></div>
        )}
      </section>
      <section className="card">
        <h2>Connect a device</h2>
        <ol className="t2" style={{ margin: 0, paddingLeft: 20, lineHeight: 1.8 }}>
          <li>Add the device here with its serial number (Menu › System Info on the device).</li>
          <li>On the device: Comm. › Cloud Server Setting (ADMS). Server address <b className="lime">{data.server.host}</b>, port <b className="lime">{data.server.port}</b>, HTTPS on. Leave the proxy off.</li>
          <li>Within a minute the status turns Online and every active member is pushed to the device.</li>
          <li>Enrol fingerprints on the device using each member's Device ID (on their profile), or from the profile with Enrol fingerprint/face.</li>
          <li>Blocking: expired, frozen and blocked members are moved to access group {s.rules ? 2 : 2}. On the device, set access group 2 to a time zone with no allowed hours so their punches are refused at the door.</li>
        </ol>
        <span className="small muted">Every punch starts or ends a session. Duplicate punches within 90 seconds are ignored, and open sessions close automatically at closing time.</span>
      </section>
      {edit && <DeviceForm d={edit} onClose={() => setEdit(null)} onDone={() => { setEdit(null); reload(); }} />}
      {cmds && <Commands d={cmds} onClose={() => setCmds(null)} />}
    </>
  );
}

function DeviceForm({ d, onClose, onDone }) {
  const s = useStaff();
  const [f, setF] = useState(d);
  const [delOpen, setDelOpen] = useState(false);
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const save = async () => {
    const b = { name: f.name, serial_no: f.serial_no, branch_id: Number(f.branch_id), model: f.model || null, kind: f.kind, direction: f.direction, relay_controls_door: !!f.relay_controls_door, is_active: !!f.is_active };
    if (await run(() => (d.id ? put('/devices/' + d.id, b) : post('/devices', b)), 'Device saved')) onDone();
  };
  return (
    <Modal title={d.id ? 'Edit device' : 'Add device'} onClose={onClose} footer={<>{d.id && <button className="btn danger" onClick={() => setDelOpen(true)}>Remove</button>}<button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={busy} onClick={save}>Save</button></>}>
      <div className="grid2">
        <Input label="Name" value={f.name} onChange={set('name')} error={fields.name} placeholder="Main door" />
        <Input label="Serial number" value={f.serial_no} onChange={set('serial_no')} error={fields.serial_no} />
        <Select label="Branch" value={f.branch_id} onChange={set('branch_id')} options={s.branches.map((b) => ({ value: b.id, label: b.name }))} />
        <Input label="Model" value={f.model || ''} onChange={set('model')} />
        <Select label="Type" value={f.kind} onChange={set('kind')} options={['fingerprint', 'face', 'rfid', 'turnstile'].map((x) => ({ value: x, label: titleCase(x) }))} />
        <Select label="Direction" value={f.direction} onChange={set('direction')} options={[{ value: 'both', label: 'IN and OUT (toggle)' }, { value: 'in', label: 'Entry only' }, { value: 'out', label: 'Exit only' }]} />
      </div>
      <Check label="Relay controls the door / turnstile" checked={f.relay_controls_door} onChange={(v) => setF({ ...f, relay_controls_door: v })} />
      <Check label="Active" checked={f.is_active} onChange={(v) => setF({ ...f, is_active: v })} />
      {delOpen && <Confirm title="Remove device?" danger confirmLabel="Remove" message="The device stops being accepted. Punch history is kept." onClose={() => setDelOpen(false)} onConfirm={async () => { if (await run(() => del('/devices/' + d.id), 'Removed')) onDone(); }} />}
    </Modal>
  );
}

function Commands({ d, onClose }) {
  const s = useStaff();
  const { data, reload } = useApi(`/devices/${d.id}/commands`);
  const { run } = useAction();
  return (
    <Modal title={`${d.name} · commands`} wide onClose={onClose}>
      {s.can('settings.manage') && <div className="row wrap">{['OPEN DOOR', 'CHECK', 'INFO', 'REBOOT'].map((c) => <button key={c} className="btn sm" onClick={async () => { if (await run(() => post(`/devices/${d.id}/command`, { command: c }), 'Queued')) reload(); }}>{titleCase(c.toLowerCase())}</button>)}</div>}
      {!data ? <Loading /> : data.length === 0 ? <Empty>No commands yet.</Empty> : (
        <div className="tablebox" style={{ maxHeight: 400 }}><table className="t"><thead><tr><th>When</th><th>Command</th><th>Status</th></tr></thead>
          <tbody>{data.map((c) => <tr key={c.id}><td className="small">{dateTime(c.created_at)}</td><td className="small num" style={{ maxWidth: 420, wordBreak: 'break-all' }}>{c.command.replace(/\t/g, ' ')}</td><td><Pill tone={c.status === 'done' ? undefined : c.status === 'failed' ? 'warn' : 'grey'}>{titleCase(c.status)}</Pill></td></tr>)}</tbody></table></div>
      )}
    </Modal>
  );
}
