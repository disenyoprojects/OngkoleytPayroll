import { useState } from "react";
import { apiClient } from "../api/client";
import { formatTime12 } from "../theme";
import { Button, ModalShell, inputStyle } from "./ui";

const REASONS = ["Late Arrival", "Early Departure", "Forgot to Clock In/Out", "System Error", "Power / Internet Outage", "Client / Supplier Errand", "Other"];

export default function AdjustAttendanceModal({ row, onCancel, onSaved }) {
  const [clockIn, setClockIn] = useState((row.record.clock_in || "08:00").slice(0, 5));
  const [clockOut, setClockOut] = useState((row.record.clock_out || "17:00").slice(0, 5));
  const [reason, setReason] = useState("");
  const [details, setDetails] = useState("");

  async function save() {
    await apiClient.patch(`/api/admin/attendance/${row.record.id}/adjust`, { clock_in: clockIn, clock_out: clockOut, reason, details });
    onSaved();
  }

  return (
    <ModalShell onClose={onCancel}>
      <h3 style={{ margin: "0 0 2px" }}>Adjust Attendance</h3>
      <div style={{ fontSize: 12.5, color: "#7A6A57", marginBottom: 16 }}>
        {row.employee.short_name} · original: {formatTime12(row.record.clock_in)} → {row.record.clock_out ? formatTime12(row.record.clock_out) : "still clocked in"}
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12, marginBottom: 14 }}>
        <div>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Clock In</div>
          <input type="time" value={clockIn} onChange={(e) => setClockIn(e.target.value)} style={inputStyle} />
        </div>
        <div>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Clock Out</div>
          <input type="time" value={clockOut} onChange={(e) => setClockOut(e.target.value)} style={inputStyle} />
        </div>
      </div>
      <div style={{ fontSize: 12, marginBottom: 4 }}>Reason for Adjustment *</div>
      <select value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, marginBottom: 14 }}>
        <option value="">Select a reason...</option>
        {REASONS.map((r) => <option key={r} value={r}>{r}</option>)}
      </select>
      <div style={{ fontSize: 12, marginBottom: 4 }}>Additional Details</div>
      <textarea value={details} onChange={(e) => setDetails(e.target.value)} style={{ ...inputStyle, minHeight: 64, marginBottom: 18 }} />
      <div style={{ display: "flex", gap: 8 }}>
        <Button variant="gold" disabled={!reason} onClick={save}>Save Adjustment</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </ModalShell>
  );
}
