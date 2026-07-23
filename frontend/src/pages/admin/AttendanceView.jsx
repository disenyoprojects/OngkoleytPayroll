import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP, formatTime12 } from "../../theme";
import { Button, Pill, StatCard } from "../../components/ui";
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
      <h1>Attendance</h1>
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard label="Clocked In Today" value={data.clocked_in} />
        <StatCard label="Pending Approval" value={data.pending} />
        <StatCard label="Total Pay Today" value={formatPHP(data.total_pay_today)} />
        <StatCard label="Approved" value={data.approved} />
      </div>
      <table style={{ width: "100%", borderCollapse: "collapse" }}>
        <thead><tr><th>Staff</th><th>Role</th><th>Branch</th><th>Clock In</th><th>Clock Out</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          {data.rows.map((row) => (
            <tr key={row.record.id}>
              <td>{row.employee.short_name}</td>
              <td>{row.employee.role}</td>
              <td>{row.employee.branch.name}</td>
              <td>{formatTime12(row.record.clock_in)}</td>
              <td>{row.record.clock_out ? formatTime12(row.record.clock_out) : "—"}</td>
              <td><Pill tone={row.record.status === "approved" ? "approved" : "pending"}>{row.record.status}</Pill></td>
              <td>
                <Button small variant="outline" onClick={() => setAdjustRow(row)}>Edit Times</Button>{" "}
                {row.record.status === "pending" && <Button small variant="gold" onClick={() => approve(row.record.id)}>Approve</Button>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {adjustRow && <AdjustAttendanceModal row={adjustRow} onCancel={() => setAdjustRow(null)} onSaved={() => { setAdjustRow(null); load(); }} />}
    </div>
  );
}
