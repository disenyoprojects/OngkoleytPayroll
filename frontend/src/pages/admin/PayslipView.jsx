import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP, formatTime12 } from "../../theme";
import { Button, inputStyle, tableWrap, tableStyle, thStyle, tdStyle } from "../../components/ui";

function thisMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

const PERIODS = [["first", "1–15"], ["second", "16–end"], ["whole", "Whole month"]];

export default function PayslipView() {
  const [staff, setStaff] = useState([]);
  const [employeeId, setEmployeeId] = useState("");
  const [month, setMonth] = useState(thisMonth());
  const [period, setPeriod] = useState("first");
  const [slip, setSlip] = useState(null);

  useEffect(() => {
    apiClient.get("/api/admin/employees").then((res) => setStaff(res.data));
  }, []);

  useEffect(() => {
    if (!employeeId) { setSlip(null); return; }
    let cancelled = false;
    setSlip(null); // clear the previous employee's slip while the new one loads
    apiClient.get(`/api/admin/employees/${employeeId}/payslip?month=${month}&period=${period}`)
      .then((res) => { if (!cancelled) setSlip(res.data); });
    return () => { cancelled = true; };
  }, [employeeId, month, period]);

  function downloadPdf() {
    if (!employeeId) return;
    window.open(`${apiClient.defaults.baseURL}/api/admin/employees/${employeeId}/payslip/pdf?month=${month}&period=${period}`, "_blank");
  }

  return (
    <div>
      <div style={{ display: "flex", gap: 12, flexWrap: "wrap", marginBottom: 16, alignItems: "center" }}>
        <select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} style={{ ...inputStyle, width: "auto" }}>
          <option value="">Select staff…</option>
          {staff.map((s) => <option key={s.id} value={s.id}>{s.full_name}</option>)}
        </select>
        <input type="month" value={month} onChange={(e) => setMonth(e.target.value)} style={{ ...inputStyle, width: "auto" }} />
        <select value={period} onChange={(e) => setPeriod(e.target.value)} style={{ ...inputStyle, width: "auto" }}>
          {PERIODS.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
        </select>
        <Button variant="outline" onClick={downloadPdf} disabled={!slip}>⬇ PDF</Button>
      </div>

      {!slip && <div style={{ color: "#7A6A57", fontSize: 13 }}>Select a staff member to view their payslip.</div>}

      {slip && (
        <div>
          <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 16, marginBottom: 14 }}>
            <div style={{ fontWeight: 700, fontSize: 16 }}>{slip.employee.full_name}</div>
            <div style={{ fontSize: 13, color: "#7A6A57" }}>{slip.employee.role} · {slip.employee.branch ?? "—"}</div>
            <div style={{ fontSize: 13, color: "#7A6A57" }}>Period: {slip.period.label} ({slip.period.from} to {slip.period.to})</div>
            <div style={{ fontSize: 13, color: "#7A6A57" }}>Daily rate: {formatPHP(slip.employee.daily_rate)}</div>
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
                  <th style={{ ...thStyle, textAlign: "right" }}>Day Pay</th>
                </tr>
              </thead>
              <tbody>
                {slip.lines.length === 0 && (
                  <tr><td style={{ ...tdStyle, textAlign: "center", color: "#7A6A57" }} colSpan={6}>No worked days in this period.</td></tr>
                )}
                {slip.lines.map((l) => (
                  <tr key={l.date}>
                    <td style={tdStyle}>{l.date}</td>
                    <td style={tdStyle}>{l.shift_start ? String(l.shift_start).slice(0, 5) : "—"}–{l.shift_end ? String(l.shift_end).slice(0, 5) : "—"}</td>
                    <td style={tdStyle}>{l.clock_in ? formatTime12(l.clock_in) : "—"}</td>
                    <td style={tdStyle}>{l.clock_out ? formatTime12(l.clock_out) : "—"}</td>
                    <td style={{ ...tdStyle, textAlign: "right" }}>{l.hours}h</td>
                    <td style={{ ...tdStyle, textAlign: "right" }}>
                      {formatPHP(l.day_pay)}
                      {l.premium_label && l.premium_label !== "Ordinary" && (
                        <div style={{ fontSize: 11, color: "#9A6B12" }}>{l.premium_label}</div>
                      )}
                      {l.late && (
                        <div style={{ fontSize: 11, color: "#C1521F" }}>Late −{formatPHP(l.late_penalty)}</div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div style={{ maxWidth: 300, marginLeft: "auto", marginTop: 14, fontSize: 14 }}>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0" }}><span>Basic</span><span>{formatPHP(slip.totals.basic)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0" }}><span>Overtime</span><span>{formatPHP(slip.totals.ot)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0" }}><span>Night Differential</span><span>{formatPHP(slip.totals.night_diff)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0", borderTop: "1px solid #E7DCC6" }}><span>Gross Pay</span><span>{formatPHP(slip.totals.gross)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0", color: "#C1521F" }}><span>Late Penalty</span><span>−{formatPHP(slip.totals.late_penalty)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "6px 0", fontWeight: 700, fontSize: 16, borderTop: "1px solid #E7DCC6" }}><span>Net Pay</span><span>{formatPHP(slip.totals.net)}</span></div>
          </div>
          <p style={{ color: "#7A6A57", fontSize: 12, marginTop: 10 }}>Net of late penalties. Excludes statutory deductions (SSS / PhilHealth / Pag-IBIG / tax).</p>
        </div>
      )}
    </div>
  );
}
