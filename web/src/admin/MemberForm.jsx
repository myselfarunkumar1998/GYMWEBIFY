import { useState } from 'react';
import { put } from '../lib/api.js';
import { useStaff } from '../lib/session.jsx';
import { Input, Select, useAction, useApi } from '../components/ui.jsx';

export const GOALS = ['Weight loss', 'Muscle gain', 'General fitness', 'Strength', 'Sports conditioning', 'Rehab'];
export const SOURCES = ['Walk-in', 'Instagram ad', 'Facebook ad', 'Google', 'Referral', 'Website', 'WhatsApp', 'Other'];

/** Shared member fields (used for editing; AddMember composes the same fields). */
export function MemberFields({ f, set, fields, staff, branches }) {
  return (
    <div className="grid2">
      <Input label="Full name" required value={f.name || ''} onChange={set('name')} error={fields.name} />
      <Input label="Mobile (WhatsApp)" type="tel" inputMode="numeric" required value={f.phone || ''} onChange={set('phone')} error={fields.phone} />
      <Input label="Email" type="email" value={f.email || ''} onChange={set('email')} error={fields.email} />
      <Input label="Date of birth" type="date" value={f.dob || ''} onChange={set('dob')} error={fields.dob} />
      <Select label="Gender" value={f.gender || ''} onChange={set('gender')} placeholder="—" options={[{ value: 'male', label: 'Male' }, { value: 'female', label: 'Female' }, { value: 'other', label: 'Other' }]} />
      <Select label="App & WhatsApp language" value={f.language || 'en'} onChange={set('language')} options={[{ value: 'ta', label: 'Tamil' }, { value: 'en', label: 'English' }]} />
      <Input label="Emergency contact" value={f.emergency_contact || ''} onChange={set('emergency_contact')} placeholder="Name and phone" />
      <Select label="Goal" value={f.goal || ''} onChange={set('goal')} placeholder="—" options={GOALS} />
      <Select label="Came from" value={f.source || ''} onChange={set('source')} placeholder="—" options={SOURCES.concat(f.source && !SOURCES.includes(f.source) ? [f.source] : [])} />
      <Select label="Trainer" value={f.trainer_id || ''} onChange={set('trainer_id')} placeholder="No trainer" options={staff.filter((u) => u.role === 'trainer' && u.status === 'active').map((u) => ({ value: u.id, label: u.name }))} />
      {branches.length > 1 && <Select label="Home branch" value={f.branch_id || ''} onChange={set('branch_id')} options={branches.map((b) => ({ value: b.id, label: b.name }))} />}
      <Input label="Biometric device ID" value={f.device_user_id || ''} onChange={set('device_user_id')} error={fields.device_user_id} hint="Leave blank to assign automatically" />
      <Input label="Address" value={f.address || ''} onChange={set('address')} className="span2" />
      <Input as="textarea" rows={2} label="Health notes (injuries, conditions, medication)" value={f.health_notes || ''} onChange={set('health_notes')} placeholder="Shown to trainers only" />
    </div>
  );
}

export default function MemberForm({ member, onSaved }) {
  const s = useStaff();
  const staff = useApi('/staff');
  const [f, setF] = useState({ ...member, trainer_id: member.trainer_id || '', branch_id: member.branch_id || '' });
  const { run, busy, fields } = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const save = async () => {
    const keys = ['name', 'phone', 'email', 'dob', 'gender', 'address', 'emergency_contact', 'health_notes', 'goal', 'source', 'language', 'trainer_id', 'branch_id', 'device_user_id'];
    const body = Object.fromEntries(keys.map((k) => [k, f[k] === '' ? null : f[k]]));
    if (body.trainer_id) body.trainer_id = Number(body.trainer_id);
    if (body.branch_id) body.branch_id = Number(body.branch_id);
    if (await run(() => put('/members/' + member.id, body), 'Saved')) onSaved();
  };
  return (
    <>
      <MemberFields f={f} set={set} fields={fields} staff={staff.data?.items || []} branches={s.branches} />
      <div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn primary" disabled={busy} onClick={save}>Save</button></div>
    </>
  );
}
