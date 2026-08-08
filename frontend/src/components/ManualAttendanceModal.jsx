import { useEffect, useState } from "react";
import { apiClient } from "../api/client";
import { Button, ModalShell, inputStyle, textareaStyle, labelStyle } from "./ui";

const rowStyle = { display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14, marginBottom: 16 };

const REASONS = ["Forgot to Clock In/Out", "System Error", "Power / Internet Outage", "Client / Supplier Errand", "Other"];

function today() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

export default function ManualAttendanceModal({ defaultDate, onCancel, onSaved }) {
  const [employees, setEmployees] = useState([]);
  const [employeeId, setEmployeeId] = useState("");
  const [workDate, setWorkDate] = useState(defaultDate || today());
  const [clockIn, setClockIn] = useState("08:00");
  const [clockOut, setClockOut] = useState("17:00");
  const [reason, setReason] = useState("");
  const [details, setDetails] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    apiClient.get("/api/admin/employees").then((res) => setEmployees(res.data));
  }, []);

  async function save() {
    setError("");
    setSaving(true);
    try {
      await apiClient.post(`/api/admin/employees/${employeeId}/attendance/manual`, {
        work_date: workDate, clock_in: clockIn, clock_out: clockOut, reason, details,
      });
      onSaved();
    } catch (err) {
      setError(err?.response?.data?.errors?.work_date?.[0] || err?.response?.data?.message || "Couldn't save this entry.");
    } finally {
      setSaving(false);
    }
  }

  const canSave = employeeId && workDate && clockIn && clockOut && reason && !saving;

  return (
    <ModalShell onClose={onCancel}>
      <h3 style={{ margin: "0 0 2px" }}>Add Missed Entry</h3>
      <div style={{ fontSize: 12.5, color: "#7A6A57", marginBottom: 16 }}>
        For a day with no attendance record at all — e.g. an employee forgot to clock in or out.
      </div>
      <div style={{ marginBottom: 16 }}>
        <label style={labelStyle}>Employee</label>
        <select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} style={inputStyle}>
          <option value="">Select an employee...</option>
          {employees.map((e) => <option key={e.id} value={e.id}>{e.short_name} — {e.branch?.name}</option>)}
        </select>
      </div>
      <div style={rowStyle}>
        <div>
          <label style={labelStyle}>Date</label>
          <input type="date" value={workDate} max={today()} onChange={(e) => setWorkDate(e.target.value)} style={inputStyle} />
        </div>
      </div>
      <div style={rowStyle}>
        <div>
          <label style={labelStyle}>Clock In</label>
          <input type="time" value={clockIn} onChange={(e) => setClockIn(e.target.value)} style={inputStyle} />
        </div>
        <div>
          <label style={labelStyle}>Clock Out</label>
          <input type="time" value={clockOut} onChange={(e) => setClockOut(e.target.value)} style={inputStyle} />
        </div>
      </div>
      <div style={{ marginBottom: 16 }}>
        <label style={labelStyle}>Reason *</label>
        <select value={reason} onChange={(e) => setReason(e.target.value)} style={inputStyle}>
          <option value="">Select a reason...</option>
          {REASONS.map((r) => <option key={r} value={r}>{r}</option>)}
        </select>
      </div>
      <div style={{ marginBottom: 16 }}>
        <label style={labelStyle}>Additional Details</label>
        <textarea value={details} onChange={(e) => setDetails(e.target.value)} style={textareaStyle} />
      </div>
      {error && <div style={{ color: "#C1521F", fontSize: 12.5, marginBottom: 14 }}>{error}</div>}
      <div style={{ display: "flex", gap: 8 }}>
        <Button variant="gold" disabled={!canSave} onClick={save}>Save Entry</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </ModalShell>
  );
}
