import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP, formatTime12, formatLateLabel, phToday as today, FONT_DISPLAY } from "../../theme";
import { Button, Pill, StatCard, inputStyle, tableWrap, tableStyle, thStyle, tdStyle } from "../../components/ui";
import AdjustAttendanceModal from "../../components/AdjustAttendanceModal";
import ManualAttendanceModal from "../../components/ManualAttendanceModal";

function shiftDate(ymd, delta) {
  const [y, m, d] = ymd.split("-").map(Number);
  const next = new Date(y, m - 1, d + delta);
  return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, "0")}-${String(next.getDate()).padStart(2, "0")}`;
}

export default function AttendanceView() {
  const [date, setDate] = useState(today());
  const [data, setData] = useState(null);
  const [adjustRow, setAdjustRow] = useState(null);
  const [showManual, setShowManual] = useState(false);

  function load() {
    apiClient.get(`/api/admin/attendance/today?date=${date}`).then((res) => setData(res.data));
  }
  useEffect(load, [date]);

  async function approve(recordId) {
    await apiClient.post(`/api/admin/attendance/${recordId}/approve`);
    load();
  }

  const isToday = date === today();

  return (
    <div>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", flexWrap: "wrap", gap: 12, marginBottom: 18 }}>
        <h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 26, margin: 0 }}>Attendance</h1>
        <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
          <Button small onClick={() => setDate((d) => shiftDate(d, -1))}>‹</Button>
          <input type="date" value={date} max={today()} onChange={(e) => setDate(e.target.value || today())} style={{ ...inputStyle, width: "auto" }} />
          <Button small onClick={() => setDate((d) => shiftDate(d, 1))} disabled={isToday}>›</Button>
          <Button small variant="outline" onClick={() => setShowManual(true)}>+ Add Missed Entry</Button>
        </div>
      </div>
      {!data ? <div>Loading...</div> : <>
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard label={isToday ? "Clocked In Today" : "Clocked In"} value={data.clocked_in} />
        <StatCard label="Pending Approval" value={data.pending} />
        <StatCard label={isToday ? "Total Pay Today" : "Total Pay"} value={formatPHP(data.total_pay_today)} />
        <StatCard label="Approved" value={data.approved} />
      </div>
      <div style={tableWrap}>
        <table style={tableStyle}>
          <thead>
            <tr>
              <th style={thStyle}>Staff</th>
              <th style={thStyle}>Role</th>
              <th style={thStyle}>Branch</th>
              <th style={thStyle}>Clock In</th>
              <th style={thStyle}>Clock Out</th>
              <th style={thStyle}>Status</th>
              <th style={{ ...thStyle, textAlign: "right" }}>Action</th>
            </tr>
          </thead>
          <tbody>
            {data.rows.length === 0 && (
              <tr><td style={{ ...tdStyle, textAlign: "center", color: "#7A6A57" }} colSpan={7}>{isToday ? "No one has clocked in today." : "No one clocked in on this date."}</td></tr>
            )}
            {data.rows.map((row) => (
              <tr key={row.record.id}>
                <td style={{ ...tdStyle, fontWeight: 600 }}>{row.employee?.short_name ?? "—"}</td>
                <td style={tdStyle}>{row.employee?.role ?? "—"}</td>
                <td style={tdStyle}>{row.employee?.branch?.name ?? "—"}</td>
                <td style={tdStyle}>{formatTime12(row.record.clock_in)}</td>
                <td style={tdStyle}>{row.record.clock_out ? formatTime12(row.record.clock_out) : "—"}</td>
                <td style={tdStyle}>
                  <Pill tone={row.record.status === "approved" ? "approved" : "pending"}>{row.record.status}</Pill>
                  {row.pay?.premium_label && row.pay.premium_label !== "Ordinary" && (
                    <span style={{ marginLeft: 6, fontSize: 11, color: "#9A6B12" }}>{row.pay.premium_label}</span>
                  )}
                  {row.pay?.late && (
                    <span style={{ marginLeft: 6, fontSize: 11, color: "#C1521F" }}>{formatLateLabel(row.pay.late_minutes)} · −{formatPHP(row.pay.tardiness)}</span>
                  )}
                </td>
                <td style={{ ...tdStyle, textAlign: "right", whiteSpace: "nowrap" }}>
                  <Button small variant="outline" onClick={() => setAdjustRow(row)}>Edit Times</Button>{" "}
                  {row.record.status === "pending" && <Button small variant="gold" onClick={() => approve(row.record.id)}>Approve</Button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {adjustRow && <AdjustAttendanceModal row={adjustRow} onCancel={() => setAdjustRow(null)} onSaved={() => { setAdjustRow(null); load(); }} />}
      </>}
      {showManual && (
        <ManualAttendanceModal
          defaultDate={date}
          onCancel={() => setShowManual(false)}
          onSaved={() => { setShowManual(false); load(); }}
        />
      )}
    </div>
  );
}
