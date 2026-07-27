import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP, formatTime12, FONT_DISPLAY } from "../../theme";
import { Button, StatCard, tabBtnStyle, tableWrap, tableStyle, thStyle, tdStyle } from "../../components/ui";

export default function PayrollView() {
  const [range, setRange] = useState("daily");
  const [data, setData] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setData(null);
    const endpoint = range === "daily" ? "/api/admin/payroll/daily" : "/api/admin/payroll/weekly";
    apiClient.get(endpoint).then((res) => {
      if (!cancelled) setData(res.data);
    });
    return () => { cancelled = true; };
  }, [range]);

  function download(kind) {
    const url = `${apiClient.defaults.baseURL}/api/admin/payroll/${kind}?range=${range}`;
    window.open(url, "_blank");
  }

  if (!data) return <div>Loading...</div>;

  return (
    <div>
      <h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 26, marginBottom: 18 }}>Payroll</h1>
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
      <div style={tableWrap}>
        <table style={tableStyle}>
          {range === "daily" ? (
            <>
              <thead>
                <tr>
                  <th style={thStyle}>Staff</th>
                  <th style={thStyle}>Role</th>
                  <th style={thStyle}>Branch</th>
                  <th style={thStyle}>Clock In</th>
                  <th style={thStyle}>Clock Out</th>
                  <th style={{ ...thStyle, textAlign: "right" }}>Total Pay</th>
                </tr>
              </thead>
              <tbody>
                {data.rows.length === 0 && (
                  <tr><td style={{ ...tdStyle, textAlign: "center", color: "#7A6A57" }} colSpan={6}>No payroll for this period.</td></tr>
                )}
                {data.rows.map((r) => (
                  <tr key={r.record.id}>
                    <td style={{ ...tdStyle, fontWeight: 600 }}>{r.employee.short_name}</td>
                    <td style={tdStyle}>{r.employee.role}</td>
                    <td style={tdStyle}>{r.employee.branch.name}</td>
                    <td style={tdStyle}>{formatTime12(r.record.clock_in)}</td>
                    <td style={tdStyle}>{formatTime12(r.record.clock_out)}</td>
                    <td style={{ ...tdStyle, textAlign: "right", fontWeight: 600 }}>{formatPHP(r.pay.total)}</td>
                  </tr>
                ))}
              </tbody>
            </>
          ) : (
            <>
              <thead>
                <tr>
                  <th style={thStyle}>Staff</th>
                  <th style={thStyle}>Role</th>
                  <th style={thStyle}>Branch</th>
                  <th style={{ ...thStyle, textAlign: "right" }}>Days Worked</th>
                  <th style={{ ...thStyle, textAlign: "right" }}>Total Hours</th>
                  <th style={{ ...thStyle, textAlign: "right" }}>Total Pay</th>
                </tr>
              </thead>
              <tbody>
                {data.rows.length === 0 && (
                  <tr><td style={{ ...tdStyle, textAlign: "center", color: "#7A6A57" }} colSpan={6}>No payroll for this period.</td></tr>
                )}
                {data.rows.map((r) => (
                  <tr key={r.employee_id}>
                    <td style={{ ...tdStyle, fontWeight: 600 }}>{r.employee.short_name}</td>
                    <td style={tdStyle}>{r.employee.role}</td>
                    <td style={tdStyle}>{r.employee.branch.name}</td>
                    <td style={{ ...tdStyle, textAlign: "right" }}>{r.days_worked}</td>
                    <td style={{ ...tdStyle, textAlign: "right" }}>{r.total_hours}h</td>
                    <td style={{ ...tdStyle, textAlign: "right", fontWeight: 600 }}>{formatPHP(r.total)}</td>
                  </tr>
                ))}
              </tbody>
            </>
          )}
        </table>
      </div>
    </div>
  );
}
