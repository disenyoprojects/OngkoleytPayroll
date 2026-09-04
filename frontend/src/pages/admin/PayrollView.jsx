import { useEffect, useState } from "react";
import { apiClient, downloadAuthedFile, openAuthedPdf } from "../../api/client";
import { formatPHP, formatTime12, formatLateLabel, phThisMonth as thisMonth, phToday, FONT_DISPLAY } from "../../theme";
import { Button, StatCard, tabBtnStyle, tableWrap, tableStyle, thStyle, tdStyle, inputStyle } from "../../components/ui";
import PayslipView from "./PayslipView";
import GenerateStatutoryButton from "../../components/GenerateStatutoryButton";
import StatutoryWarning from "../../components/StatutoryWarning";

// Calendar math on YYYY-MM-DD strings. The Date is built at UTC midnight from
// an explicit date, so it carries no time-of-day the browser's timezone could
// roll over — unlike deriving "today" from a real timestamp, which must go
// through phToday().
function addDays(ymd, days) {
  const d = new Date(`${ymd}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

/** Monday of the week containing a date — the week the backend's weekly range starts on. */
function mondayOf(ymd) {
  const dow = new Date(`${ymd}T00:00:00Z`).getUTCDay();
  return addDays(ymd, -((dow + 6) % 7)); // Sunday (0) closes a week, it doesn't open one
}

function formatDayLabel(ymd) {
  return new Date(`${ymd}T00:00:00Z`).toLocaleDateString("en-PH", { timeZone: "UTC", month: "short", day: "numeric", year: "numeric" });
}

function formatWeekLabel(start) {
  return `${formatDayLabel(start)} – ${formatDayLabel(addDays(start, 6))}`;
}

export default function PayrollView({ isAdmin = true }) {
  const [range, setRange] = useState("daily");
  const [data, setData] = useState(null);
  // Track which range the loaded data belongs to, so the daily/weekly table is
  // never rendered against the other range's data shape during a switch.
  const [dataRange, setDataRange] = useState(null);

  // Which day / week the daily and weekly tables are looking at. Both start on
  // "now" but can be walked backwards to review any past period.
  const [day, setDay] = useState(phToday);
  const [weekStart, setWeekStart] = useState(() => mondayOf(phToday()));

  // Semi-monthly (all-staff) register controls + data.
  const [month, setMonth] = useState(thisMonth());
  const [period, setPeriod] = useState("second");
  const [periodData, setPeriodData] = useState(null);

  useEffect(() => {
    if (range === "payslip" || range === "semi") return;
    let cancelled = false;
    setData(null);
    const endpoint = range === "daily"
      ? `/api/admin/payroll/daily?date=${day}`
      : `/api/admin/payroll/weekly?start=${weekStart}`;
    apiClient.get(endpoint).then((res) => {
      if (!cancelled) { setData(res.data); setDataRange(range); }
    });
    return () => { cancelled = true; };
  }, [range, day, weekStart]);

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

  // Both export endpoints take a single `date`: the day for daily, the week's
  // first day for weekly. Passing the viewed period keeps the file in step with
  // the table on screen instead of always exporting the current one.
  const viewedDate = range === "daily" ? day : weekStart;

  function download(kind) {
    const path = `/api/admin/payroll/${kind}?range=${range}&date=${viewedDate}`;
    if (kind === "export") downloadAuthedFile(path, `payroll-${range}-${viewedDate}.csv`);
    else openAuthedPdf(path);
  }

  function stepPeriod(direction) {
    if (range === "daily") setDay((d) => addDays(d, direction));
    else setWeekStart((w) => addDays(w, direction * 7));
  }

  function jumpTo(ymd) {
    if (!ymd) return;
    if (range === "daily") setDay(ymd);
    else setWeekStart(mondayOf(ymd));
  }

  const today = phToday();
  const atLatest = range === "daily" ? day >= today : weekStart >= mondayOf(today);

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

      {(range === "daily" || range === "weekly") && (
        <div style={{ display: "flex", gap: 8, alignItems: "center", marginBottom: 14, flexWrap: "wrap" }}>
          <Button variant="outline" onClick={() => stepPeriod(-1)}>‹ Prev</Button>
          <input
            type="date"
            value={viewedDate}
            max={today}
            onChange={(e) => jumpTo(e.target.value)}
            style={{ ...inputStyle, width: 170 }}
          />
          <Button variant="outline" onClick={() => stepPeriod(1)} disabled={atLatest}>Next ›</Button>
          {!atLatest && (
            <Button variant="ghost" onClick={() => jumpTo(today)}>{range === "daily" ? "Today" : "This week"}</Button>
          )}
          <span style={{ color: "#7A6A57", fontSize: 13 }}>
            {range === "daily" ? formatDayLabel(day) : formatWeekLabel(weekStart)}
          </span>
        </div>
      )}

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
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard
          label={range === "daily" ? `Total · ${formatDayLabel(day)}` : `Total · ${formatWeekLabel(weekStart)}`}
          value={formatPHP(data.total)}
        />
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
                      {r.pay?.undertime > 0 && (
                        <div style={{ fontSize: 11, color: "#C1521F" }}>undertime · −{formatPHP(r.pay.undertime)}</div>
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
            <Button variant="outline" onClick={() => downloadAuthedFile(`/api/admin/payroll/period/export?month=${month}&period=${period}`, `payroll-summary-${month}-${period}.xlsx`)}>⬇ Summary (Excel)</Button>
            <Button variant="outline" onClick={() => openAuthedPdf(`/api/admin/payroll/period/payslips-pdf?month=${month}&period=${period}`)}>🖨 Print All Payslips</Button>
          </>
        )}
        <GenerateStatutoryButton month={month} period={period} onGenerated={onGenerated} />
      </div>

      {!data ? (
        <div>Loading...</div>
      ) : (
        <>
          {/* The register below shows no SSS/Pag-IBIG/PhilHealth at all until
              the generator has run, which is indistinguishable from a period
              where nothing is owed. Say so, with the button to hand. */}
          <StatutoryWarning count={data.statutory_ungenerated}>
            <GenerateStatutoryButton month={month} period={period} onGenerated={onGenerated} />
          </StatutoryWarning>

          {/* Totals cover whatever the login can see: the owner gets the whole
              company, a branch login gets its own branches summed. */}
          <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
            <StatCard label={`Gross · ${data.period.label}`} value={formatPHP(data.totals.gross)} />
            <StatCard label="Deductions" value={`−${formatPHP(data.totals.tardiness + Math.max(0, -data.totals.adjustments))}`} />
            <StatCard label="Net to Release" value={formatPHP(data.totals.net_to_release)} />
          </div>
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
                  <th style={numTh}>Late/UT</th>
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
