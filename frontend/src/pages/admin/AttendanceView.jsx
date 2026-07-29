import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP, formatTime12, FONT_DISPLAY } from "../../theme";
import { Button, Pill, StatCard, tableWrap, tableStyle, thStyle, tdStyle } from "../../components/ui";
import AdjustAttendanceModal from "../../components/AdjustAttendanceModal";

export default function AttendanceView() {
  const [data, setData] = useState(null);
  const [adjustRow, setAdjustRow] = useState(null);

  function load() {
    apiClient.get("/api/admin/attendance/today").then((res) => setData(res.data));
  }
  useEffect(load, []);

  async function approve(recordId) {
    await apiClient.post(`/api/admin/attendance/${recordId}/approve`);
    load();
  }

  if (!data) return <div>Loading...</div>;

  return (
    <div>
      <h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 26, marginBottom: 18 }}>Attendance</h1>
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard label="Clocked In Today" value={data.clocked_in} />
        <StatCard label="Pending Approval" value={data.pending} />
        <StatCard label="Total Pay Today" value={formatPHP(data.total_pay_today)} />
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
              <tr><td style={{ ...tdStyle, textAlign: "center", color: "#7A6A57" }} colSpan={7}>No one has clocked in today.</td></tr>
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
    </div>
  );
}
