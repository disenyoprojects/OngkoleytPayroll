import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP, formatHoursLabel, formatTime12 } from "../../theme";
import { Button, StatCard, tabBtnStyle } from "../../components/ui";

export default function PayrollView() {
  const [range, setRange] = useState("daily");
  const [data, setData] = useState(null);

  useEffect(() => {
    const endpoint = range === "daily" ? "/api/admin/payroll/daily" : "/api/admin/payroll/weekly";
    apiClient.get(endpoint).then((res) => setData(res.data));
  }, [range]);

  function download(kind) {
    const url = `${apiClient.defaults.baseURL}/api/admin/payroll/${kind}?range=${range}`;
    window.open(url, "_blank");
  }

  if (!data) return <div>Loading...</div>;

  return (
    <div>
      <h1>Payroll</h1>
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard label={`Total ${range === "daily" ? "Today" : "This Week"}`} value={formatPHP(data.total)} />
      </div>
      <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 14 }}>
        <div style={{ display: "flex", gap: 8 }}>
          <button onClick={() => setRange("daily")} style={tabBtnStyle(range === "daily")}>Daily</button>
          <button onClick={() => setRange("weekly")} style={tabBtnStyle(range === "weekly")}>Weekly</button>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <Button variant="outline" onClick={() => download("export")}>⬇ CSV</Button>
          <Button variant="outline" onClick={() => download("pdf")}>⬇ PDF</Button>
        </div>
      </div>
      <table style={{ width: "100%", borderCollapse: "collapse" }}>
        {range === "daily" ? (
          <>
            <thead><tr><th>Staff</th><th>Role</th><th>Branch</th><th>Clock In</th><th>Clock Out</th><th>Total Pay</th></tr></thead>
            <tbody>
              {data.rows.map((r) => (
                <tr key={r.record.id}>
                  <td>{r.employee.short_name}</td><td>{r.employee.role}</td><td>{r.employee.branch.name}</td>
                  <td>{formatTime12(r.record.clock_in)}</td><td>{formatTime12(r.record.clock_out)}</td>
                  <td>{formatPHP(r.pay.total)}</td>
                </tr>
              ))}
            </tbody>
          </>
        ) : (
          <>
            <thead><tr><th>Staff</th><th>Role</th><th>Branch</th><th>Days Worked</th><th>Total Hours</th><th>Total Pay</th></tr></thead>
            <tbody>
              {data.rows.map((r) => (
                <tr key={r.employee_id}>
                  <td>{r.employee.short_name}</td><td>{r.employee.role}</td><td>{r.employee.branch.name}</td>
                  <td>{r.days_worked}</td><td>{r.total_hours}h</td><td>{formatPHP(r.total)}</td>
                </tr>
              ))}
            </tbody>
          </>
        )}
      </table>
    </div>
  );
}
