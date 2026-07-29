import { useEffect, useState } from "react";
import { apiClient } from "../api/client";
import { formatTime12 } from "../theme";
import { Button, ModalShell, Pill, tableWrap, tableStyle, thStyle, tdStyle } from "./ui";

function thisMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

function ymOf(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

export default function AttendanceLogModal({ employee, onClose }) {
  const [month, setMonth] = useState(thisMonth());
  const [records, setRecords] = useState([]);

  useEffect(() => {
    apiClient.get(`/api/admin/employees/${employee.id}/attendance?month=${month}`)
      .then((res) => setRecords(res.data.records))
      .catch(() => setRecords([]));
  }, [employee.id, month]);

  function shiftMonth(delta) {
    const [y, m] = month.split("-").map(Number);
    setMonth(ymOf(new Date(y, m - 1 + delta, 1)));
  }

  return (
    <ModalShell width={760} onClose={onClose}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12 }}>
        <h3 style={{ margin: 0 }}>Attendance — {employee.short_name}</h3>
        <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
          <Button small onClick={() => shiftMonth(-1)}>‹</Button>
          <span style={{ fontSize: 13, minWidth: 90, textAlign: "center" }}>{month}</span>
          <Button small onClick={() => shiftMonth(1)}>›</Button>
        </div>
      </div>
      <div style={tableWrap}>
        <table style={tableStyle}>
          <thead>
            <tr>
              <th style={thStyle}>Date</th>
              <th style={thStyle}>Shift</th>
              <th style={thStyle}>In</th>
              <th style={thStyle}>Out</th>
              <th style={{ ...thStyle, textAlign: "right" }}>Hours</th>
              <th style={thStyle}>Type</th>
            </tr>
          </thead>
          <tbody>
            {records.length === 0 && (
              <tr><td style={{ ...tdStyle, textAlign: "center", color: "#7A6A57" }} colSpan={6}>No attendance this month.</td></tr>
            )}
            {records.map((r) => (
              <tr key={r.id}>
                <td style={tdStyle}>{String(r.work_date).slice(0, 10)}</td>
                <td style={tdStyle}>{r.shift_start ? String(r.shift_start).slice(0, 5) : "—"}–{r.shift_end ? String(r.shift_end).slice(0, 5) : "—"}</td>
                <td style={tdStyle}>{r.clock_in ? formatTime12(r.clock_in) : "—"}</td>
                <td style={tdStyle}>{r.clock_out ? formatTime12(r.clock_out) : "—"}</td>
                <td style={{ ...tdStyle, textAlign: "right" }}>{r.pay ? `${r.pay.total_hours}h` : "—"}</td>
                <td style={tdStyle}>
                  {r.absence_type ? <Pill tone="locked">{r.absence_type.replace("_", " ")}</Pill>
                    : (r.pay?.premium_label && r.pay.premium_label !== "Ordinary" ? <Pill tone="pending">{r.pay.premium_label}</Pill> : "—")}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div style={{ marginTop: 14 }}><Button onClick={onClose}>Close</Button></div>
    </ModalShell>
  );
}
