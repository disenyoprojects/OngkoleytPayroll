import { useState } from "react";
import { apiClient } from "../api/client";
import { formatTime12 } from "../theme";
import { Button, ModalShell, inputStyle, textareaStyle, labelStyle } from "./ui";

const rowStyle = { display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14, marginBottom: 16 };

const REASONS = ["Late Arrival", "Early Departure", "Forgot to Clock In/Out", "System Error", "Power / Internet Outage", "Client / Supplier Errand", "Other"];

export default function AdjustAttendanceModal({ row, onCancel, onSaved }) {
  const [clockIn, setClockIn] = useState((row.record.clock_in || "08:00").slice(0, 5));
  const [clockOut, setClockOut] = useState((row.record.clock_out || "17:00").slice(0, 5));
  const [reason, setReason] = useState("");
  const [details, setDetails] = useState("");
  const [shiftStart, setShiftStart] = useState((row.record.shift_start || "08:00").slice(0, 5));
  const [shiftEnd, setShiftEnd] = useState((row.record.shift_end || "17:00").slice(0, 5));
  const [holidayType, setHolidayType] = useState(row.record.holiday_type || "");
  const [isRestDay, setIsRestDay] = useState(!!row.record.is_rest_day);
  const [absenceType, setAbsenceType] = useState(row.record.absence_type || "");

  async function save() {
    await apiClient.patch(`/api/admin/attendance/${row.record.id}/adjust`, {
      clock_in: clockIn, clock_out: clockOut, reason, details,
      shift_start: shiftStart, shift_end: shiftEnd,
      holiday_type: holidayType || null,
      is_rest_day: isRestDay,
      absence_type: absenceType || null,
    });
    onSaved();
  }

  return (
    <ModalShell onClose={onCancel}>
      <h3 style={{ margin: "0 0 2px" }}>Adjust Attendance</h3>
      <div style={{ fontSize: 12.5, color: "#7A6A57", marginBottom: 16 }}>
        {row.employee.short_name} · original: {formatTime12(row.record.clock_in)} → {row.record.clock_out ? formatTime12(row.record.clock_out) : "still clocked in"}
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
      <div style={rowStyle}>
        <div>
          <label style={labelStyle}>Shift Start</label>
          <input type="time" value={shiftStart} onChange={(e) => setShiftStart(e.target.value)} style={inputStyle} />
        </div>
        <div>
          <label style={labelStyle}>Shift End</label>
          <input type="time" value={shiftEnd} onChange={(e) => setShiftEnd(e.target.value)} style={inputStyle} />
        </div>
      </div>
      <div style={rowStyle}>
        <div>
          <label style={labelStyle}>Holiday</label>
          <select value={holidayType} onChange={(e) => setHolidayType(e.target.value)} style={inputStyle}>
            <option value="">None</option>
            <option value="special">Special (non-working)</option>
            <option value="regular">Regular holiday</option>
          </select>
        </div>
        <div>
          <label style={labelStyle}>Status</label>
          <select value={absenceType} onChange={(e) => setAbsenceType(e.target.value)} style={inputStyle}>
            <option value="">Worked</option>
            <option value="half_day">Half day</option>
            <option value="leave">Leave</option>
            <option value="sick_leave">Sick leave</option>
            <option value="absent">Absent</option>
            <option value="awol">AWOL</option>
            <option value="travel">Travel</option>
          </select>
        </div>
      </div>
      <label style={{ display: "flex", gap: 8, alignItems: "center", fontSize: 13, marginBottom: 16 }}>
        <input type="checkbox" checked={isRestDay} onChange={(e) => setIsRestDay(e.target.checked)} />
        Rest day (worked)
      </label>
      <div style={{ marginBottom: 16 }}>
        <label style={labelStyle}>Reason for Adjustment *</label>
        <select value={reason} onChange={(e) => setReason(e.target.value)} style={inputStyle}>
          <option value="">Select a reason...</option>
          {REASONS.map((r) => <option key={r} value={r}>{r}</option>)}
        </select>
      </div>
      <div style={{ marginBottom: 18 }}>
        <label style={labelStyle}>Additional Details</label>
        <textarea value={details} onChange={(e) => setDetails(e.target.value)} style={textareaStyle} />
      </div>
      <div style={{ display: "flex", gap: 8 }}>
        <Button variant="gold" disabled={!reason} onClick={save}>Save Adjustment</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </ModalShell>
  );
}
