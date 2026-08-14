import { useEffect, useState } from "react";
import { apiClient, downloadAuthedFile, openAuthedPdf } from "../../api/client";
import { formatPHP, formatTime12, formatLateLabel, FONT_DISPLAY } from "../../theme";
import { Button, StatCard, tabBtnStyle, tableWrap, tableStyle, thStyle, tdStyle, inputStyle } from "../../components/ui";
import PayslipView from "./PayslipView";

const thisMonth = () => new Date().toISOString().slice(0, 7);

export default function PayrollView({ isAdmin = true }) {
  const [range, setRange] = useState("daily");
  const [data, setData] = useState(null);
  // Track which range the loaded data belongs to, so the daily/weekly table is
  // never rendered against the other range's data shape during a switch.
  const [dataRange, setDataRange] = useState(null);

  // Semi-monthly (all-staff) register controls + data.
  const [month, setMonth] = useState(thisMonth());
  const [period, setPeriod] = useState("second");
  const [periodData, setPeriodData] = useState(null);

  useEffect(() => {
    if (range === "payslip" || range === "semi") return;
    let cancelled = false;
    setData(null);
    const endpoint = range === "daily" ? "/api/admin/payroll/daily" : "/api/admin/payroll/weekly";
    apiClient.get(endpoint).then((res) => {
      if (!cancelled) { setData(res.data); setDataRange(range); }
    });
    return () => { cancelled = true; };
  }, [range]);

  function reloadPeriod() {
    return apiClient.get(`/api/admin/payroll/period?month=${month}&period=${period}`).then((res) => setPeriodData(res.data));
  }

  useEffect(() => {
    if (range !== "semi") return;
    let cancelled = false;
    setPeriodData(null);
    apiClient.get(`/api/admin/payroll/period?month=${month}&period=${period}`).then((res) => {
      if (!cancelled) setPeriodData(res.data);
    });
    return () => { cancelled = true; };
  }, [range, month, period]);

  function download(kind) {
    const path = `/api/admin/payroll/${kind}?range=${range}`;
    if (kind === "export") downloadAuthedFile(path, `payroll-${range}.csv`);
    else openAuthedPdf(path);
  }

  return (
    <div>
      <h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 26, marginBottom: 18 }}>Payroll</h1>
      <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 14, flexWrap: "wrap", gap: 8 }}>
        <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
          <button onClick={() => setRange("daily")} style={tabBtnStyle(range === "daily")}>Daily</button>
          <button onClick={() => setRange("weekly")} style={tabBtnStyle(range === "weekly")}>Weekly</button>
          <button onClick={() => setRange("semi")} style={tabBtnStyle(range === "semi")}>Semi-Monthly</button>
          <button onClick={() => setRange("payslip")} style={tabBtnStyle(range === "payslip")}>Payslip</button>
        </div>
        {(range === "daily" || range === "weekly") && (
          <div style={{ display: "flex", gap: 8 }}>
            <Button variant="outline" onClick={() => download("export")}>⬇ CSV</Button>
            <Button variant="outline" onClick={() => download("pdf")}>⬇ PDF</Button>
          </div>
        )}
      </div>

      {range === "payslip" ? (
        <PayslipView />
      ) : range === "semi" ? (
        <SemiMonthly
          month={month} setMonth={setMonth} period={period} setPeriod={setPeriod} data={periodData} isAdmin={isAdmin}
          onGenerated={reloadPeriod}
        />
      ) : (!data || dataRange !== range) ? (
        <div>Loading...</div>
      ) : (
      <>
      {isAdmin && (
        <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
          <StatCard label={`Total ${range === "daily" ? "Today" : "This Week"}`} value={formatPHP(data.total)} />
        </div>
      )}
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
                  <tr key={r.record?.id}>
                    <td style={{ ...tdStyle, fontWeight: 600 }}>{r.employee?.short_name ?? "—"}</td>
                    <td style={tdStyle}>{r.employee?.role ?? "—"}</td>
                    <td style={tdStyle}>{r.employee?.branch?.name ?? "—"}</td>
                    <td style={tdStyle}>{formatTime12(r.record?.clock_in)}</td>
                    <td style={tdStyle}>{formatTime12(r.record?.clock_out)}</td>
                    <td style={{ ...tdStyle, textAlign: "right", fontWeight: 600 }}>
                      {formatPHP(r.pay?.total)}
                      {r.pay?.premium_label && r.pay.premium_label !== "Ordinary" && (
                        <div style={{ fontSize: 11, color: "#9A6B12" }}>{r.pay.premium_label}</div>
                      )}
                      {r.pay?.late && (
                        <div style={{ fontSize: 11, color: "#C1521F" }}>{formatLateLabel(r.pay.late_minutes)} · −{formatPHP(r.pay.tardiness)}</div>
                      )}
                    </td>
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
                    <td style={{ ...tdStyle, fontWeight: 600 }}>{r.employee?.short_name ?? "—"}</td>
                    <td style={tdStyle}>{r.employee?.role ?? "—"}</td>
                    <td style={tdStyle}>{r.employee?.branch?.name ?? "—"}</td>
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
      </>
      )}
    </div>
  );
}

const numTd = { ...tdStyle, textAlign: "right" };
const numTh = { ...thStyle, textAlign: "right" };

function SemiMonthly({ month, setMonth, period, setPeriod, data, isAdmin = true, onGenerated }) {
  const [generating, setGenerating] = useState(false);
  const [notice, setNotice] = useState(null);

  async function generateStatutory() {
    setGenerating(true);
    setNotice(null);
    try {
      const res = await apiClient.post(`/api/admin/payroll/period/statutory?month=${month}&period=${period}`);
      const { generated, skipped } = res.data;
      const totalSkipped = skipped.pagibig + skipped.philhealth + skipped.sss;
      setNotice(`Generated ${generated.pagibig} Pag-IBIG, ${generated.philhealth} PhilHealth, ${generated.sss} SSS (${totalSkipped} already existed).`);
      await onGenerated?.();
    } finally {
      setGenerating(false);
    }
  }

  return (
    <>
      <div style={{ display: "flex", gap: 10, alignItems: "center", marginBottom: 10, flexWrap: "wrap" }}>
        <input type="month" value={month} onChange={(e) => setMonth(e.target.value)} style={{ ...inputStyle, width: 170 }} />
        <select value={period} onChange={(e) => setPeriod(e.target.value)} style={{ ...inputStyle, width: 200 }}>
          <option value="first">1st half (1–15)</option>
          <option value="second">2nd half (16–end)</option>
          <option value="whole">Whole month</option>
        </select>
        {data && data.rows.length > 0 && (
          <>
            <Button variant="outline" onClick={() => openAuthedPdf(`/api/admin/payroll/period/pdf?month=${month}&period=${period}`)}>🖨 Print Summary</Button>
            <Button variant="outline" onClick={() => openAuthedPdf(`/api/admin/payroll/period/payslips-pdf?month=${month}&period=${period}`)}>🖨 Print All Payslips</Button>
          </>
        )}
        <Button variant="outline" disabled={generating} onClick={generateStatutory}>
          {generating ? "Generating…" : "Generate SSS/Pag-IBIG/PhilHealth"}
        </Button>
      </div>
      {notice && <div style={{ fontSize: 12.5, color: "#7A6A57", marginBottom: 12 }}>{notice}</div>}

      {!data ? (
        <div>Loading...</div>
      ) : (
        <>
          {isAdmin && (
            <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
              <StatCard label={`Gross · ${data.period.label}`} value={formatPHP(data.totals.gross)} />
              <StatCard label="Deductions" value={`−${formatPHP(data.totals.tardiness + Math.max(0, -data.totals.adjustments))}`} />
              <StatCard label="Net to Release" value={formatPHP(data.totals.net_to_release)} />
            </div>
          )}
          <div style={tableWrap}>
            <table style={tableStyle}>
              <thead>
                <tr>
                  <th style={thStyle}>Staff</th>
                  <th style={thStyle}>Branch</th>
                  <th style={numTh}>Days</th>
                  <th style={numTh}>Basic</th>
                  <th style={numTh}>OT</th>
                  <th style={numTh}>Gross</th>
                  <th style={numTh}>Tardiness</th>
                  <th style={numTh}>Allow./Ded.</th>
                  <th style={numTh}>Total Salary</th>
                  <th style={numTh}>Net to Release</th>
                </tr>
              </thead>
              <tbody>
                {data.rows.length === 0 && (
                  <tr><td style={{ ...tdStyle, textAlign: "center", color: "#7A6A57" }} colSpan={10}>No payroll for this period.</td></tr>
                )}
                {data.rows.map((r) => (
                  <tr key={r.employee_id}>
                    <td style={{ ...tdStyle, fontWeight: 600 }}>{r.name}</td>
                    <td style={tdStyle}>{r.branch ?? "—"}</td>
                    <td style={numTd}>{r.days}</td>
                    <td style={numTd}>{formatPHP(r.basic)}</td>
                    <td style={numTd}>{r.ot ? formatPHP(r.ot) : "—"}</td>
                    <td style={numTd}>{formatPHP(r.gross)}</td>
                    <td style={{ ...numTd, color: r.tardiness ? "#C1521F" : undefined }}>{r.tardiness ? `−${formatPHP(r.tardiness)}` : "—"}</td>
                    <td style={{ ...numTd, color: r.adjustments < 0 ? "#C1521F" : (r.adjustments > 0 ? "#3B7A57" : undefined) }}>
                      {r.adjustments ? `${r.adjustments < 0 ? "−" : "+"}${formatPHP(Math.abs(r.adjustments))}` : "—"}
                    </td>
                    <td style={{ ...numTd, fontWeight: 600 }}>{formatPHP(r.total_salary)}</td>
                    <td style={{ ...numTd, fontWeight: 700 }}>{formatPHP(r.net_to_release)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p style={{ color: "#7A6A57", fontSize: 12, marginTop: 10 }}>
            "Allow./Ded." nets paid allowances (added to Total Salary, already handed out in cash) and cash-advance deductions.
            Net to Release excludes amounts already paid. Excludes statutory deductions (SSS / PhilHealth / Pag-IBIG / tax).
          </p>
        </>
      )}
    </>
  );
}
