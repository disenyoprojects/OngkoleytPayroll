import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP, formatTime12, formatLateLabel } from "../../theme";
import { Button, inputStyle, tableWrap, tableStyle, thStyle, tdStyle } from "../../components/ui";

function thisMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

const PERIODS = [["first", "1–15"], ["second", "16–end"], ["whole", "Whole month"]];
const CATEGORIES = [
  ["cash_on_hand", "Cash on Hand"],
  ["allowance", "Allowance"],
  ["bonus", "Bonus"],
  ["deduction", "Deduction"],
  ["other", "Other"],
];
const BLANK_ADJ = { label: "", category: "cash_on_hand", amount: "", paid: true, date: "" };

function signed(amount) {
  return (amount < 0 ? "−" : "") + formatPHP(Math.abs(amount));
}

export default function PayslipView() {
  const [staff, setStaff] = useState([]);
  const [employeeId, setEmployeeId] = useState("");
  const [month, setMonth] = useState(thisMonth());
  const [period, setPeriod] = useState("first");
  const [slip, setSlip] = useState(null);
  const [reload, setReload] = useState(0);
  const [adj, setAdj] = useState(BLANK_ADJ);
  const [adjError, setAdjError] = useState(null);

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
  }, [employeeId, month, period, reload]);

  function downloadPdf() {
    if (!employeeId) return;
    window.open(`${apiClient.defaults.baseURL}/api/admin/employees/${employeeId}/payslip/pdf?month=${month}&period=${period}`, "_blank");
  }

  async function addAdjustment() {
    setAdjError(null);
    const date = adj.date || slip.period.from;
    try {
      await apiClient.post(`/api/admin/employees/${employeeId}/adjustments`, {
        date,
        label: adj.label,
        category: adj.category,
        amount: Number(adj.amount),
        paid: adj.paid,
      });
      setAdj(BLANK_ADJ);
      setReload((n) => n + 1);
    } catch (e) {
      setAdjError(e?.response?.data?.message || "Couldn't add that. Check the amount, label, and date (must fall in the period).");
    }
  }

  async function removeAdjustment(id) {
    await apiClient.delete(`/api/admin/adjustments/${id}`);
    setReload((n) => n + 1);
  }

  const setAdjField = (k, v) => setAdj((a) => ({ ...a, [k]: v }));

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
                        <div style={{ fontSize: 11, color: "#C1521F" }}>{formatLateLabel(l.late_minutes)} · −{formatPHP(l.late_penalty)}</div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Adjustments */}
          <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 16, marginTop: 16 }}>
            <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 10 }}>Adjustments (bonuses, allowances, cash on hand)</div>
            {slip.adjustments.length > 0 && (
              <table style={{ ...tableStyle, marginBottom: 12 }}>
                <thead>
                  <tr>
                    <th style={thStyle}>Label</th>
                    <th style={thStyle}>Date</th>
                    <th style={thStyle}>Type</th>
                    <th style={thStyle}>Status</th>
                    <th style={{ ...thStyle, textAlign: "right" }}>Amount</th>
                    <th style={thStyle}></th>
                  </tr>
                </thead>
                <tbody>
                  {slip.adjustments.map((a) => (
                    <tr key={a.id}>
                      <td style={tdStyle}>{a.label}</td>
                      <td style={tdStyle}>{a.date}</td>
                      <td style={tdStyle}>{(CATEGORIES.find(([v]) => v === a.category) || [null, a.category])[1]}</td>
                      <td style={tdStyle}>{a.paid ? <span style={{ color: "#3F6B45" }}>Paid ✓</span> : "To pay"}</td>
                      <td style={{ ...tdStyle, textAlign: "right" }}>{signed(a.amount)}</td>
                      <td style={{ ...tdStyle, textAlign: "right" }}><Button small variant="outline" onClick={() => removeAdjustment(a.id)}>Remove</Button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
              <input value={adj.label} onChange={(e) => setAdjField("label", e.target.value)} placeholder="Label (e.g. Night shift bonus)" style={{ ...inputStyle, width: 220 }} />
              <input type="number" step="0.01" value={adj.amount} onChange={(e) => setAdjField("amount", e.target.value)} placeholder="Amount" style={{ ...inputStyle, width: 110 }} />
              <select value={adj.category} onChange={(e) => setAdjField("category", e.target.value)} style={{ ...inputStyle, width: "auto" }}>
                {CATEGORIES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
              </select>
              <input type="date" value={adj.date || slip.period.from} min={slip.period.from} max={slip.period.to} onChange={(e) => setAdjField("date", e.target.value)} style={{ ...inputStyle, width: "auto" }} />
              <label style={{ display: "flex", gap: 6, alignItems: "center", fontSize: 13 }}>
                <input type="checkbox" checked={adj.paid} onChange={(e) => setAdjField("paid", e.target.checked)} />
                Already paid (cash on hand)
              </label>
              <Button variant="gold" onClick={addAdjustment} disabled={!adj.label || adj.amount === ""}>Add</Button>
            </div>
            {adjError && <div style={{ color: "#C1521F", fontSize: 12, marginTop: 8 }}>{adjError}</div>}
            <div style={{ color: "#7A6A57", fontSize: 12, marginTop: 8 }}>Tip: pick the type and enter a positive amount — a <b>Deduction</b> (e.g. a cash advance) is subtracted automatically; allowances/bonuses are added. "Already paid" is added to Total Salary but subtracted from what's still handed over.</div>
          </div>

          <div style={{ maxWidth: 340, marginLeft: "auto", marginTop: 14, fontSize: 14 }}>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0" }}><span>Basic</span><span>{formatPHP(slip.totals.basic)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0" }}><span>Overtime</span><span>{formatPHP(slip.totals.ot)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0" }}><span>Night Differential</span><span>{formatPHP(slip.totals.night_diff)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0", borderTop: "1px solid #E7DCC6" }}><span>Gross Pay</span><span>{formatPHP(slip.totals.gross)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0", color: "#C1521F" }}><span>Late Penalty</span><span>−{formatPHP(slip.totals.late_penalty)}</span></div>
            {CATEGORIES.map(([cat, label]) => {
              const sum = slip.adjustments.filter((a) => a.category === cat).reduce((t, a) => t + Number(a.amount), 0);
              if (!sum) return null;
              return (
                <div key={cat} style={{ display: "flex", justifyContent: "space-between", padding: "3px 0", color: sum < 0 ? "#C1521F" : undefined }}>
                  <span>{label}</span><span>{signed(sum)}</span>
                </div>
              );
            })}
            <div style={{ display: "flex", justifyContent: "space-between", padding: "6px 0", fontWeight: 700, fontSize: 16, borderTop: "1px solid #E7DCC6" }}><span>Total Salary</span><span>{formatPHP(slip.totals.total_salary)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "3px 0", color: "#7A6A57" }}><span>Less already paid (Cash on Hand)</span><span>−{formatPHP(slip.totals.paid)}</span></div>
            <div style={{ display: "flex", justifyContent: "space-between", padding: "6px 0", fontWeight: 700, fontSize: 16, borderTop: "1px solid #E7DCC6" }}><span>Net to Release</span><span>{formatPHP(slip.totals.net_to_release)}</span></div>
          </div>
          <p style={{ color: "#7A6A57", fontSize: 12, marginTop: 10 }}>Total salary includes adjustments and is net of late penalties. "Net to Release" excludes amounts already paid in cash. Excludes statutory deductions (SSS / PhilHealth / Pag-IBIG / tax).</p>
        </div>
      )}
    </div>
  );
}
