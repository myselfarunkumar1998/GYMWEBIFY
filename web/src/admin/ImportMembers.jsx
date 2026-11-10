import { useState } from 'react';
import { post } from '../lib/api.js';
import { Modal, useAction } from '../components/ui.jsx';

const COLS = ['name', 'phone', 'email', 'dob', 'gender', 'address', 'plan', 'start_date', 'end_date', 'paid', 'device_user_id'];
const ALIASES = { 'full name': 'name', 'member name': 'name', mobile: 'phone', 'mobile number': 'phone', 'phone number': 'phone', 'date of birth': 'dob', birthday: 'dob',
  'plan name': 'plan', membership: 'plan', 'start date': 'start_date', 'end date': 'end_date', 'expiry date': 'end_date', expiry: 'end_date', 'valid till': 'end_date',
  amount: 'paid', 'amount paid': 'paid', 'biometric id': 'device_user_id', 'device id': 'device_user_id', 'machine id': 'device_user_id' };

function normDate(v) {
  if (v === undefined || v === null || v === '') return undefined;
  if (v instanceof Date) return v.toISOString().slice(0, 10);
  const s = String(v).trim();
  let m = s.match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/);
  if (m) return `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
  m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? m[0] : s;
}

/** Excel/CSV import: parsed in the browser with SheetJS, validated and saved row by row on the server. */
export default function ImportMembers({ onClose, onDone }) {
  const [rows, setRows] = useState(null);
  const [result, setResult] = useState(null);
  const [fileErr, setFileErr] = useState(null);
  const { run, busy } = useAction();

  const onFile = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setFileErr(null);
    try {
      const XLSX = await import('xlsx');
      const wb = XLSX.read(await file.arrayBuffer(), { type: 'array', cellDates: true });
      const sheet = wb.Sheets[wb.SheetNames[0]];
      const raw = XLSX.utils.sheet_to_json(sheet, { defval: '' });
      const mapped = raw.map((r) => {
        const o = {};
        Object.entries(r).forEach(([k, v]) => {
          const key = k.trim().toLowerCase();
          const col = COLS.includes(key.replace(/ /g, '_')) ? key.replace(/ /g, '_') : ALIASES[key];
          if (col) o[col] = ['dob', 'start_date', 'end_date'].includes(col) ? normDate(v) : (typeof v === 'string' ? v.trim() : v);
        });
        if (o.gender) o.gender = String(o.gender).toLowerCase().startsWith('f') ? 'female' : String(o.gender).toLowerCase().startsWith('m') ? 'male' : 'other';
        if (o.phone !== undefined) o.phone = String(o.phone);
        Object.keys(o).forEach((k) => (o[k] === '' || o[k] === undefined) && delete o[k]);
        return o;
      }).filter((o) => o.name || o.phone);
      if (!mapped.length) throw new Error('No rows with Name and Phone columns were found.');
      setRows(mapped);
    } catch (err) { setFileErr(err.message || 'Could not read that file.'); }
  };

  const go = async () => {
    const r = await run(() => post('/members/import', { rows }));
    if (r) setResult(r);
  };

  return (
    <Modal title="Import members from Excel" wide onClose={onClose}>
      {!result && <>
        <p className="muted" style={{ margin: 0 }}>Upload .xlsx, .xls or .csv. Columns: <b>Name</b>, <b>Phone</b> (required), Email, DOB, Gender, Address, Plan (must match a plan name), Start date, End date, Paid, Biometric ID. Dates as DD/MM/YYYY or YYYY-MM-DD.</p>
        <input type="file" accept=".xlsx,.xls,.csv" onChange={onFile} aria-label="Choose file" />
        {fileErr && <div className="banner">{fileErr}</div>}
        {rows && <>
          <span>{rows.length} rows ready. First 5:</span>
          <div className="tablebox"><table className="t"><thead><tr>{COLS.map((c) => <th key={c}>{c}</th>)}</tr></thead>
            <tbody>{rows.slice(0, 5).map((r, i) => <tr key={i}>{COLS.map((c) => <td key={c}>{String(r[c] ?? '')}</td>)}</tr>)}</tbody></table></div>
          <div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn primary" disabled={busy} onClick={go}>{busy ? 'Importing…' : `Import ${rows.length} members`}</button></div>
        </>}
      </>}
      {result && <>
        <div className="banner info">Imported {result.created} members. Skipped {result.skipped.length}.</div>
        {result.skipped.length > 0 && <div className="tablebox" style={{ maxHeight: 300 }}><table className="t"><thead><tr><th>Row</th><th>Reason</th></tr></thead>
          <tbody>{result.skipped.map((s) => <tr key={s.row}><td>{s.row}</td><td className="warn">{s.error}</td></tr>)}</tbody></table></div>}
        <div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn primary" onClick={onDone}>Done</button></div>
      </>}
    </Modal>
  );
}
