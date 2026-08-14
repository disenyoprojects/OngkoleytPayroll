import { useEffect, useState } from "react";
import { apiClient, openAuthedPdf } from "../../api/client";
import { formatPHP, formatTime12, formatLateLabel } from "../../theme";
import { Button, inputStyle, tableWrap, tableStyle, thStyle, tdStyle } from "../../components/ui";
import GenerateStatutoryButton from "../../components/GenerateStatutoryButton";

function thisMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

const PERIODS = [["first", "1–15"], ["second", "16–end"], ["whole", "Whole month"]];
const CATEGORIES = [
  ["cash_on_hand", "Cash on Hand"],
  ["allowance", "Allowance"],
  ["bonus", "Bonus"],
  ["deduction", "Authorized Deduction"],
  ["sss", "SSS"],
  ["pagibig", "Pag-IBIG"],
  ["philhealth", "PhilHealth"],
  ["other", "Other"],
];
// Deduction categories that don't need a typed label — the name is the label.
const DEFAULT_LABELS = { deduction: "Authorized Deduction", sss: "SSS", pagibig: "Pag-IBIG", philhealth: "PhilHealth" };
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
    openAuthedPdf(`/api/admin/employees/${employeeId}/payslip/pdf?month=${month}&period=${period}`);
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
        <GenerateStatutoryButton month={month} period={period} onGenerated={() => setReload((n) => n + 1)} />
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
                        <div style={{ fontSize: 11, color: "#C1521F" }}>{formatLateLabel(l.late_minutes)} · −{formatPHP(l.tardiness)}</div>
                      )}
                      {l.undertime > 0 && (
                        <div style={{ fontSize: 11, color: "#C1521F" }}>{l.undertime_minutes ? `${l.undertime_minutes} min early` : "overbreak"} · −{formatPHP(l.undertime)}</div>
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
              <select
                value={adj.category}
                onChange={(e) => {
                  const cat = e.target.value;
                  setAdj((a) => {
                    // Auto-fill the label for SSS/Pag-IBIG/PhilHealth/Authorized Deduction, but keep a custom label the user typed.
                    const wasAuto = !a.label || Object.values(DEFAULT_LABELS).includes(a.label);
                    return { ...a, category: cat, label: wasAuto ? (DEFAULT_LABELS[cat] || "") : a.label };
                  });
                }}
                style={{ ...inputStyle, width: "auto" }}
              >
                {CATEGORIES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
              </select>
              <input type="date" value={adj.date || slip.period.from} min={slip.period.from} max={slip.period.to} onChange={(e) => setAdjField("date", e.target.value)} style={{ ...inputStyle, width: "auto" }} />
              <label style={{ display: "flex", gap: 6, alignItems: "center", fontSize: 13 }}>
                <input type="checkbox" checked={adj.paid} onChange={(e) => setAdjField("paid", e.target.checked)} />
                Already paid (cash on hand)
              </label>
              <Button variant="gold" onClick={addAdjustment} disabled={adj.amount === "" || (!adj.label && !DEFAULT_LABELS[adj.category])}>Add</Button>
            </div>
            {adjError && <div style={{ color: "#C1521F", fontSize: 12, marginTop: 8 }}>{adjError}</div>}
            <div style={{ color: "#7A6A57", fontSize: 12, marginTop: 8 }}>Tip: pick the type and enter a positive amount. <b>SSS, Pag-IBIG, PhilHealth</b> and <b>Authorized Deduction</b> are subtracted automatically (type any amount you want — nothing is fixed); allowances/bonuses are added. For <b>Other</b>, type your own label. "Already paid" is added to Total Salary but subtracted from what's still handed over.</div>
          </div>

          <div style={{ fontWeight: 700, fontSize: 14, margin: "20px 0 8px" }}>Payslip preview</div>
          <PayslipDocument slip={slip} />
        </div>
      )}
    </div>
  );
}

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
function periodText(from, to) {
  const [y1, m1, d1] = from.split("-").map(Number);
  const [, m2, d2] = to.split("-").map(Number);
  if (m1 === m2) return `${MONTHS[m1 - 1]} ${d1} to ${d2}, ${y1}`;
  return `${MONTHS[m1 - 1]} ${d1} to ${MONTHS[m2 - 1]} ${d2}, ${y1}`;
}

const COMPANY = { name: "WANG CHOCOLATE INC.", address: "Upper Ground Floor, Olympian, Upper Mabini,  Baguio City 2600" };

// On-screen render of the printed WANG CHOCOLATE payslip (same data as the PDF).
function PayslipDocument({ slip }) {
  const s = slip.slip;
  const rows = Math.max(s.earnings.length, s.deductions.length);
  const line = "1px solid #3a3a3a";
  const money = { textAlign: "right", fontVariantNumeric: "tabular-nums" };
  const cellL = { padding: "3px 12px", color: "#1f3a6b", fontSize: 13 };
  const th = { padding: "6px 12px", background: "#f2f2f2", borderTop: line, borderBottom: line, textAlign: "center", fontSize: 13.5, fontWeight: 700 };

  return (
    <div style={{ maxWidth: 780, border: line, background: "white", color: "#1c1c1c", fontFamily: "'Inter', system-ui, sans-serif" }}>
      <div style={{ background: "#6b3410", color: "white", textAlign: "center", fontSize: 19, fontWeight: 800, letterSpacing: ".5px", padding: "9px 0" }}>{COMPANY.name}</div>
      <div style={{ background: "#dfe8cf", color: "#222", textAlign: "center", fontSize: 12, fontStyle: "italic", fontWeight: 600, padding: "6px 0", borderBottom: line }}>{COMPANY.address}</div>
      <div style={{ textAlign: "center", fontSize: 16, fontWeight: 800, letterSpacing: "1px", padding: "10px 0 4px" }}>PAY SLIP</div>

      <div style={{ display: "flex", justifyContent: "space-between", padding: "2px 18px 10px", flexWrap: "wrap", gap: 8, fontSize: 13 }}>
        <div><span style={{ fontWeight: 700 }}>EMPLOYEE: </span><span style={{ fontWeight: 700 }}>{slip.employee.full_name}</span></div>
        <div style={{ textAlign: "right" }}>
          <div><span style={{ fontWeight: 700 }}>PAY PERIOD: </span>{periodText(slip.period.from, slip.period.to)}</div>
          <div><span style={{ fontWeight: 700 }}>DAYS WORKED </span>{Number(s.days_worked).toFixed(2)}</div>
        </div>
      </div>

      <table style={{ width: "100%", borderCollapse: "collapse", tableLayout: "fixed" }}>
        <colgroup><col style={{ width: "32%" }} /><col style={{ width: "18%" }} /><col style={{ width: "32%" }} /><col style={{ width: "18%" }} /></colgroup>
        <thead>
          <tr><th style={th}>Earnings</th><th style={th}>Amount</th><th style={{ ...th, borderLeft: line }}>Deductions</th><th style={th}>Amount</th></tr>
        </thead>
        <tbody>
          {Array.from({ length: rows }).map((_, i) => (
            <tr key={i}>
              <td style={cellL}>{s.earnings[i]?.label ?? ""}</td>
              <td style={{ ...cellL, ...money, color: "#1c1c1c" }}>{s.earnings[i] ? formatPHP(s.earnings[i].amount) : ""}</td>
              <td style={{ ...cellL, borderLeft: line }}>{s.deductions[i]?.label ?? ""}</td>
              <td style={{ ...cellL, ...money, color: "#1c1c1c" }}>{s.deductions[i] ? formatPHP(s.deductions[i].amount) : ""}</td>
            </tr>
          ))}
          <tr style={{ fontWeight: 700 }}>
            <td style={{ padding: "7px 12px", borderTop: line, borderBottom: line, textAlign: "center" }}>Gross Earnings</td>
            <td style={{ padding: "7px 12px", borderTop: line, borderBottom: line, ...money }}>{formatPHP(s.gross_earnings)}</td>
            <td style={{ padding: "7px 12px", borderTop: line, borderBottom: line, borderLeft: line }}>Total Deductions</td>
            <td style={{ padding: "7px 12px", borderTop: line, borderBottom: line, ...money }}>{formatPHP(s.total_deductions)}</td>
          </tr>
        </tbody>
      </table>

      <div style={{ textAlign: "center", padding: "16px 0 8px", fontSize: 14, fontWeight: 700 }}>
        Net Salary Received: <span style={{ color: "#C00000", marginLeft: 24 }}>{formatPHP(s.net)}</span>
      </div>

      <div style={{ fontStyle: "italic", color: "#333", fontSize: 11, padding: "22px 16px 4px" }}>I hereby confirm that the above records are true and correct.</div>
      <div style={{ display: "flex", gap: 24, padding: "24px 16px 14px" }}>
        <div style={{ flex: "1 1 55%", borderTop: "1px solid #555", paddingTop: 4, fontWeight: 700, fontSize: 11 }}>Employee's Printed Name &amp; Signature</div>
        <div style={{ flex: "1 1 45%", borderTop: "1px solid #555", paddingTop: 4, fontWeight: 700, fontSize: 11 }}>Date</div>
      </div>
    </div>
  );
}
