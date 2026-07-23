import React, { useState, useEffect, useMemo } from "react";

/* ---------------------------------------------------------------------- */
/* Design tokens                                                          */
/* ---------------------------------------------------------------------- */
const COLOR = {
  espresso: "#2E2118",
  espressoSoft: "#4A3728",
  gold: "#C9A227",
  cream: "#FAF6EC",
  parchment: "#F3EAD3",
  ink: "#221A13",
  inkSoft: "#7A6A57",
  rust: "#C1521F",
  rustSoft: "#F7E2D0",
  green: "#3F6B45",
  greenSoft: "#DDEBDD",
  amber: "#9A6B12",
  amberSoft: "#F3E7C8",
  line: "#E7DCC6",
};
const FONT_DISPLAY = "'Fraunces', serif";
const FONT_BODY = "'Inter', sans-serif";
const FONT_MONO = "'IBM Plex Mono', monospace";

/* ---------------------------------------------------------------------- */
/* Domain constants                                                       */
/* ---------------------------------------------------------------------- */
const EARNING_CODES = {
  BASIC: "Basic Salary", OVERTIME: "Overtime Pay", NIGHT_DIFF: "Night Differential",
  HOLIDAY_PREMIUM: "Holiday Premium", ALLOWANCE: "Allowances", BONUS: "Bonuses",
  INCENTIVE: "Incentives", COMMISSION: "Commissions", LEAVE_CONVERSION: "Leave Conversion",
};
const EMPLOYMENT_TYPES = ["regular", "probationary", "fixed_term", "seasonal"];
const BRANCHES = ["General Luna", "Bonifacio", "Diego Silang", "La Trinidad", "La Union"];
const PAYROLL_YEAR = 2026;
const MONTH_NAMES = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const DEMO_PIN = "1234";
const TODAY_LABEL = "Tuesday, July 21, 2026";
const SHIFT_START_24 = "08:00";
const ATT_REASONS = ["Late Arrival", "Early Departure", "Forgot to Clock In/Out", "System Error", "Power / Internet Outage", "Client / Supplier Errand", "Other"];

const STAFF_BASE = [
  { id: 1, shortName: "Joshua Bacuyag", fullName: "Joshua Bacuyag", role: "Operations Manager", branch: "General Luna", employmentType: "regular", hireMonth: 1, lastMonth: 12 },
  { id: 2, shortName: "Kristen", fullName: "Kristen Nicole Urbano", role: "Brand Manager", branch: "General Luna", employmentType: "regular", hireMonth: 1, lastMonth: 12 },
  { id: 3, shortName: "Kyle", fullName: "Kyle Antazo", role: "Marketing & Investor Relations Officer", branch: "General Luna", employmentType: "probationary", hireMonth: 5, lastMonth: 12 },
  { id: 4, shortName: "Khirby", fullName: "Khirby Domingo", role: "Driver (Manual, knows Baguio & La Trinidad roads)", branch: "La Trinidad", employmentType: "regular", hireMonth: 1, lastMonth: 12 },
  { id: 5, shortName: "Summer", fullName: "Summer Dizon", role: "Barista (Female)", branch: "Bonifacio", employmentType: "regular", hireMonth: 1, lastMonth: 12 },
  { id: 6, shortName: "Jhon", fullName: "Jhon Alonzo", role: "Male Counter Staff", branch: "Diego Silang", employmentType: "regular", hireMonth: 1, lastMonth: 12 },
  { id: 7, shortName: "Jhon", fullName: "Jhon Ancheta", role: "Male Kitchen Staff", branch: "Diego Silang", employmentType: "regular", hireMonth: 1, lastMonth: 9 },
  { id: 8, shortName: "Jamiel Miclat", fullName: "Jamiel Miclat", role: "Marketing & Investor Relations Manager", branch: "General Luna", employmentType: "regular", hireMonth: 1, lastMonth: 12 },
  { id: 9, shortName: "Jhen", fullName: "Jhen Aquino", role: "Female Kitchen Staff", branch: "Bonifacio", employmentType: "regular", hireMonth: 1, lastMonth: 12 },
  { id: 10, shortName: "Jhen", fullName: "Jhen Navarro", role: "Female Counter Staff", branch: "La Trinidad", employmentType: "regular", hireMonth: 1, lastMonth: 12 },
  { id: 11, shortName: "Michiko", fullName: "Michiko Reyes", role: "Sales Agent (Knows how to drive)", branch: "La Union", employmentType: "regular", hireMonth: 1, lastMonth: 12 },
  { id: 12, shortName: "Michiko", fullName: "Michiko Ramos", role: "Market Coordinator", branch: "La Union", employmentType: "seasonal", hireMonth: 1, lastMonth: 12 },
  { id: 13, shortName: "Glenn", fullName: "Glenn Aspiras", role: "Male Kitchen Staff", branch: "Bonifacio", employmentType: "regular", hireMonth: 1, lastMonth: 12 },
  { id: 14, shortName: "Rhea", fullName: "Rhea Ibarra", role: "Female Counter Staff", branch: "Diego Silang", employmentType: "regular", hireMonth: 1, lastMonth: 12 },
  { id: 15, shortName: "test", fullName: "test", role: "Male Kitchen Staff", branch: "General Luna", employmentType: "probationary", hireMonth: 1, lastMonth: 12 },
  { id: 16, shortName: "test2", fullName: "test2", role: "Female Counter Staff", branch: "General Luna", employmentType: "fixed_term", hireMonth: 8, lastMonth: 12 },
];

function seededRand(seed) {
  let s = seed;
  return () => { s = (s * 9301 + 49297) % 233280; return s / 233280; };
}
function buildStaffRoster() {
  const rand = seededRand(42);
  return STAFF_BASE.map((base) => {
    const otHoursByMonth = {};
    const earnings = [];
    for (let m = base.hireMonth; m <= base.lastMonth; m++) {
      otHoursByMonth[m] = rand() < 0.55 ? Math.round(rand() * 18) : 0;
      if (rand() < 0.3) earnings.push({ month: m, code: "NIGHT_DIFF", amount: Math.round(rand() * 1200) });
      if (rand() < 0.2) earnings.push({ month: m, code: "HOLIDAY_PREMIUM", amount: Math.round(rand() * 1800) });
      if (rand() < 0.5) earnings.push({ month: m, code: "ALLOWANCE", amount: 1500 });
    }
    if (rand() < 0.25) earnings.push({ month: 12, code: "BONUS", amount: Math.round(3000 + rand() * 5000) });
    if (rand() < 0.15) earnings.push({ month: base.lastMonth, code: "LEAVE_CONVERSION", amount: Math.round(2000 + rand() * 4000) });
    if (rand() < 0.15) earnings.push({ month: 6, code: "COMMISSION", amount: Math.round(1000 + rand() * 3000) });
    return {
      ...base,
      hireDate: `2026-${String(base.hireMonth).padStart(2, "0")}-01`,
      resignationDate: base.lastMonth < 12 ? `2026-${String(base.lastMonth).padStart(2, "0")}-30` : null,
      pin: DEMO_PIN,
      otHoursByMonth,
      earnings,
    };
  });
}
const STAFF = buildStaffRoster();

function initialsOf(name) {
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

/* ---------------------------------------------------------------------- */
/* Time / pay helpers                                                     */
/* ---------------------------------------------------------------------- */
function minutesOf(hhmm24) { const [h, m] = hhmm24.split(":").map(Number); return h * 60 + m; }
function formatTime12(hhmm24) {
  if (!hhmm24) return "—";
  let [h, m] = hhmm24.split(":").map(Number);
  const ampm = h >= 12 ? "PM" : "AM";
  let h12 = h % 12; if (h12 === 0) h12 = 12;
  return `${String(h12).padStart(2, "0")}:${String(m).padStart(2, "0")} ${ampm}`;
}
function formatHoursLabel(totalHours) {
  const h = Math.floor(totalHours); const m = Math.round((totalHours - h) * 60);
  return `${h}h ${m}m`;
}
function formatPHP(amount) {
  if (amount == null) return "—";
  return "₱" + Number(amount).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function lateByMinutes(shiftStart24, clockIn24) {
  const diff = minutesOf(clockIn24) - minutesOf(shiftStart24);
  return diff > 0 ? diff : 0;
}
function computeDailyPay(clockIn24, clockOut24, settings) {
  if (!clockIn24 || !clockOut24) return null;
  let start = minutesOf(clockIn24), end = minutesOf(clockOut24);
  if (end <= start) end += 24 * 60;
  const totalHours = (end - start) / 60;
  const hourlyRate = settings.dailyBasicRate / 8;
  const regularHours = Math.min(totalHours, 8);
  const otHours = Math.max(0, totalHours - 8);
  const nightStart = 22 * 60, nightEnd = 24 * 60 + 6 * 60;
  const overlapStart = Math.max(start, nightStart), overlapEnd = Math.min(end, nightEnd);
  const nightDiffHours = Math.max(0, (overlapEnd - overlapStart) / 60);
  const basic = Math.round(regularHours * hourlyRate * 100) / 100;
  const ot = Math.round(otHours * hourlyRate * settings.overtimeMultiplier * 100) / 100;
  const nightDiff = Math.round(nightDiffHours * hourlyRate * settings.nightDiffMultiplier * 100) / 100;
  const total = Math.round((basic + ot + nightDiff) * 100) / 100;
  return { totalHours, regularHours, otHours, nightDiffHours, basic, ot, nightDiff, total };
}

/* ---- 13th month computation (mirrors the Laravel service) ---- */
function buildMonthlyBreakdown(employee, settings) {
  const included = settings.includedEarnings;
  const periodStartMonth = Number(settings.periodStart.split("-")[1]);
  const periodEndMonth = Number(settings.periodEnd.split("-")[1]);
  const hireMonth = Number(employee.hireDate.split("-")[1]);
  const lastMonth = employee.resignationDate ? Number(employee.resignationDate.split("-")[1]) : 12;
  const dailyRate = Number(settings.dailyBasicRate) || 0;
  const workingDays = Number(settings.standardWorkingDaysPerMonth) || 0;
  const hourlyRate = dailyRate / 8;
  const monthlyBasicAmount = Math.round(dailyRate * workingDays * 100) / 100;

  const months = [];
  for (let m = 1; m <= 12; m++) {
    const worked = m >= hireMonth && m <= lastMonth && m >= periodStartMonth && m <= periodEndMonth;
    const basicPay = worked ? monthlyBasicAmount : 0;
    const otHours = worked ? employee.otHoursByMonth[m] || 0 : 0;
    const otPay = Math.round(otHours * hourlyRate * settings.overtimeMultiplier * 100) / 100;
    const otherEntries = worked ? employee.earnings.filter((e) => e.month === m) : [];
    const otherPayIncluded = otherEntries.filter((e) => included.includes(e.code)).reduce((s, e) => s + e.amount, 0);
    const otherPayAll = otherEntries.reduce((s, e) => s + e.amount, 0);
    const includedBasic = included.includes("BASIC") ? basicPay : 0;
    const includedOT = included.includes("OVERTIME") ? otPay : 0;
    const monthTotalIncluded = Math.round((includedBasic + includedOT + otherPayIncluded) * 100) / 100;
    months.push({ month: m, monthName: MONTH_NAMES[m - 1], worked, basicPay, otHours, otPay, otherPay: otherPayAll, monthTotalIncluded });
  }
  return months;
}
function computeThirteenthMonthRecord(employee, settings) {
  const breakdown = buildMonthlyBreakdown(employee, settings);
  const totalBasicEarned = Math.round(breakdown.reduce((s, m) => s + m.monthTotalIncluded, 0) * 100) / 100;
  const totalOvertimePay = Math.round(breakdown.reduce((s, m) => s + m.otPay, 0) * 100) / 100;
  const computedAmount = Math.round((totalBasicEarned / 12) * 100) / 100;
  return { totalBasicEarned, computedAmount, totalOvertimePay, breakdown };
}
function isEligibleForThirteenthMonth(employee, settings) {
  if (!settings.employmentTypesIncluded.includes(employee.employmentType)) return false;
  const hireMonth = Number(employee.hireDate.split("-")[1]);
  const lastMonth = employee.resignationDate ? Number(employee.resignationDate.split("-")[1]) : 12;
  return lastMonth - hireMonth + 1 >= settings.minimumMonths;
}

/* ---- weekly payroll aggregate ---- */
function buildWeeklyData(settings) {
  const rand = seededRand(77);
  const days = ["Jul 15", "Jul 16", "Jul 17", "Jul 18", "Jul 19", "Jul 20", "Jul 21"];
  const hourlyRate = settings.dailyBasicRate / 8;
  return STAFF.map((s) => {
    let totalHours = 0, basic = 0, ot = 0, daysWorked = 0;
    days.forEach(() => {
      if (rand() < 0.85) {
        daysWorked++;
        const hrs = 8 + (rand() < 0.3 ? Math.round(rand() * 3) : 0);
        const reg = Math.min(hrs, 8), otH = Math.max(0, hrs - 8);
        totalHours += hrs;
        basic += reg * hourlyRate;
        ot += otH * hourlyRate * settings.overtimeMultiplier;
      }
    });
    return { staff: s, daysWorked, totalHours: Math.round(totalHours * 100) / 100, basic: Math.round(basic * 100) / 100, ot: Math.round(ot * 100) / 100, total: Math.round((basic + ot) * 100) / 100 };
  }).filter((r) => r.daysWorked > 0);
}

/* ---- today's attendance seed ---- */
function buildInitialAttendance() {
  const rows = {
    1: { clockIn: "07:55", clockOut: "17:05", status: "approved" },
    2: { clockIn: "08:02", clockOut: null, status: "pending" },
    4: { clockIn: "08:10", clockOut: "17:00", status: "approved" },
    5: { clockIn: "08:00", clockOut: "22:15", status: "approved" },
    6: { clockIn: "08:00", clockOut: "16:45", status: "approved" },
    8: { clockIn: "08:05", clockOut: "17:30", status: "approved" },
    9: { clockIn: "08:00", clockOut: null, status: "pending" },
    11: { clockIn: "08:00", clockOut: "17:00", status: "approved" },
    13: { clockIn: "08:00", clockOut: "18:00", status: "approved" },
    14: { clockIn: "07:45", clockOut: "17:15", status: "approved" },
    15: { clockIn: "00:00", clockOut: "21:00", status: "pending" },
    16: { clockIn: "08:00", clockOut: "17:00", status: "approved" },
  };
  const out = {};
  Object.entries(rows).forEach(([id, r]) => { out[id] = { ...r, shiftStart: SHIFT_START_24, adjusted: false, reason: null, details: null }; });
  return out;
}

/* ---------------------------------------------------------------------- */
/* Small UI primitives                                                    */
/* ---------------------------------------------------------------------- */
function Pill({ children, tone = "neutral" }) {
  const tones = {
    neutral: { bg: COLOR.parchment, fg: COLOR.ink },
    approved: { bg: COLOR.greenSoft, fg: COLOR.green },
    pending: { bg: COLOR.amberSoft, fg: COLOR.amber },
    computed: { bg: "#DCE7F5", fg: "#2C5384" },
    released: { bg: COLOR.greenSoft, fg: COLOR.green },
    locked: { bg: COLOR.rustSoft, fg: COLOR.rust },
  };
  const t = tones[tone] || tones.neutral;
  return <span style={{ display: "inline-block", padding: "3px 10px", borderRadius: 999, fontSize: 11, fontWeight: 600, letterSpacing: "0.03em", textTransform: "uppercase", background: t.bg, color: t.fg, fontFamily: FONT_BODY }}>{children}</span>;
}
function Button({ children, onClick, variant = "primary", disabled, small }) {
  const base = { fontFamily: FONT_BODY, fontWeight: 600, fontSize: small ? 12 : 13, padding: small ? "5px 10px" : "9px 18px", borderRadius: 7, border: "1px solid transparent", cursor: disabled ? "not-allowed" : "pointer", opacity: disabled ? 0.45 : 1 };
  const variants = {
    primary: { background: COLOR.espresso, color: COLOR.cream },
    gold: { background: COLOR.gold, color: COLOR.espresso },
    outline: { background: "white", color: COLOR.espresso, border: `1px solid ${COLOR.line}` },
    ghost: { background: "transparent", color: COLOR.inkSoft },
    rust: { background: "white", color: COLOR.rust, border: `1px solid ${COLOR.rust}` },
    danger: { background: "transparent", color: COLOR.rust, border: `1px solid ${COLOR.rust}` },
  };
  return <button style={{ ...base, ...variants[variant] }} onClick={disabled ? undefined : onClick} disabled={disabled}>{children}</button>;
}
function StatCard({ label, value, tone }) {
  const toneColor = { green: COLOR.green, rust: COLOR.rust, amber: COLOR.amber, ink: COLOR.ink }[tone] || COLOR.ink;
  return (
    <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: "16px 20px", flex: "1 1 180px" }}>
      <div style={{ fontSize: 11, letterSpacing: "0.08em", textTransform: "uppercase", color: COLOR.inkSoft, marginBottom: 6 }}>{label}</div>
      <div style={{ fontFamily: FONT_MONO, fontSize: 24, fontWeight: 700, color: toneColor }}>{value}</div>
    </div>
  );
}
function Field({ label, children }) {
  return <div style={{ marginBottom: 12 }}><div style={{ fontSize: 12, color: COLOR.inkSoft, marginBottom: 4 }}>{label}</div>{children}</div>;
}
const inputStyle = { width: "100%", padding: "9px 10px", border: `1px solid ${COLOR.line}`, borderRadius: 6, fontSize: 13, fontFamily: FONT_BODY };
function tabBtnStyle(active) {
  return { padding: "8px 16px", borderRadius: 8, border: `1px solid ${active ? COLOR.espresso : COLOR.line}`, background: active ? COLOR.espresso : "white", color: active ? COLOR.cream : COLOR.ink, fontSize: 13, fontWeight: 600, cursor: "pointer" };
}
function ModalShell({ children, onClose, width = 460 }) {
  return (
    <div style={{ position: "fixed", inset: 0, background: "rgba(46,33,24,0.5)", display: "flex", alignItems: "center", justifyContent: "center", zIndex: 50 }} onClick={onClose}>
      <div style={{ background: "white", borderRadius: 12, width, maxWidth: "90vw", maxHeight: "88vh", overflow: "auto", padding: 24 }} onClick={(e) => e.stopPropagation()}>{children}</div>
    </div>
  );
}
function PayRow({ label, value, mono, bold }) {
  return <div style={{ display: "flex", justifyContent: "space-between", padding: "5px 0", fontSize: 13 }}><span style={{ color: COLOR.inkSoft }}>{label}</span><span style={{ fontFamily: mono ? FONT_MONO : FONT_BODY, fontWeight: bold ? 700 : 500 }}>{value}</span></div>;
}
function useTicker() {
  const [now, setNow] = useState(() => new Date("2026-07-21T17:03:28"));
  useEffect(() => { const id = setInterval(() => setNow((n) => new Date(n.getTime() + 1000)), 1000); return () => clearInterval(id); }, []);
  return now;
}
function formatClock(now) {
  let h = now.getHours(); const m = String(now.getMinutes()).padStart(2, "0"); const s = String(now.getSeconds()).padStart(2, "0");
  const ampm = h >= 12 ? "PM" : "AM"; h = h % 12; if (h === 0) h = 12;
  return `${String(h).padStart(2, "0")}:${m}:${s} ${ampm}`;
}
function currentHHMM(now) { return `${String(now.getHours()).padStart(2, "0")}:${String(now.getMinutes()).padStart(2, "0")}`; }

/* ---------------------------------------------------------------------- */
/* Root App                                                                */
/* ---------------------------------------------------------------------- */
export default function OngkoleytSystem() {
  const [mode, setMode] = useState("kiosk"); // 'kiosk' | 'staff-login' | 'admin'
  const [settings, setSettings] = useState({
    periodStart: "2026-01-01", periodEnd: "2026-12-31", releaseDate: "2026-12-24",
    includedEarnings: ["BASIC"], employmentTypesIncluded: [...EMPLOYMENT_TYPES], minimumMonths: 1,
    dailyBasicRate: 505, standardWorkingDaysPerMonth: 26, overtimeMultiplier: 1.25, nightDiffMultiplier: 0.1,
  });
  const [attendance, setAttendance] = useState(buildInitialAttendance);
  const [tmRecordState, setTmRecordState] = useState(() => Object.fromEntries(STAFF.map((e) => [e.id, { status: "pending", manualAdjustment: 0, releasedOn: null, paymentMethod: null }])));
  const [auditLog, setAuditLog] = useState([]);
  const [adjustAttendanceTarget, setAdjustAttendanceTarget] = useState(null);
  const [tmAdjustTarget, setTmAdjustTarget] = useState(null);
  const [tmUnlockTarget, setTmUnlockTarget] = useState(null);
  const [tmPayslipTarget, setTmPayslipTarget] = useState(null);
  const [toast, setToast] = useState(null);
  const now = useTicker();

  function showToast(msg) { setToast(msg); setTimeout(() => setToast(null), 2400); }
  function pushAudit(entry) { setAuditLog((log) => [{ id: log.length + 1, timestamp: formatClock(now) + " · " + TODAY_LABEL, ...entry }, ...log]); }

  // ---- attendance actions ----
  function handleClockAction(staffId, action) {
    const nowHHMM = currentHHMM(now);
    setAttendance((prev) => {
      if (action === "in") return { ...prev, [staffId]: { clockIn: nowHHMM, clockOut: null, status: "pending", shiftStart: SHIFT_START_24, adjusted: false, reason: null, details: null } };
      const existing = prev[staffId];
      return existing ? { ...prev, [staffId]: { ...existing, clockOut: nowHHMM, status: "pending" } } : prev;
    });
  }
  function handleSaveAttendanceAdjustment(staffId, clockIn24, clockOut24, reason, details) {
    const staffName = STAFF.find((s) => s.id === staffId)?.shortName;
    setAttendance((prev) => {
      const existing = prev[staffId] || { shiftStart: SHIFT_START_24 };
      pushAudit({ type: "attendance", staffId, staffName, action: "adjust", detail: `${formatTime12(existing.clockIn)} → ${existing.clockOut ? formatTime12(existing.clockOut) : "—"} became ${formatTime12(clockIn24)} → ${formatTime12(clockOut24)}`, reason });
      return { ...prev, [staffId]: { ...existing, clockIn: clockIn24, clockOut: clockOut24, status: "approved", adjusted: true, reason, details } };
    });
    showToast(`Attendance adjusted for ${staffName}.`);
    setAdjustAttendanceTarget(null);
  }
  function handleApproveAttendance(staffId) {
    setAttendance((prev) => ({ ...prev, [staffId]: { ...prev[staffId], status: "approved" } }));
    showToast("Marked as approved.");
  }
  function handleResetToday() { setAttendance(buildInitialAttendance()); showToast("Today's attendance has been reset."); }

  // ---- 13th month derived records ----
  const tmRecords = useMemo(() => {
    return STAFF.filter((e) => isEligibleForThirteenthMonth(e, settings)).map((e) => {
      const { totalBasicEarned, computedAmount, totalOvertimePay, breakdown } = computeThirteenthMonthRecord(e, settings);
      const state = tmRecordState[e.id];
      const adjustedAmount = Math.round((computedAmount + state.manualAdjustment) * 100) / 100;
      return { employee: e, totalBasicEarned, computedAmount, totalOvertimePay, breakdown, manualAdjustment: state.manualAdjustment, adjustedAmount, status: state.status, releasedOn: state.releasedOn, paymentMethod: state.paymentMethod };
    });
  }, [settings, tmRecordState]);

  function updateTmState(employeeId, patch) { setTmRecordState((prev) => ({ ...prev, [employeeId]: { ...prev[employeeId], ...patch } })); }

  function handleTmComputeAll() {
    const pendingIds = tmRecords.filter((r) => r.status === "pending").map((r) => r.employee.id);
    setTmRecordState((prev) => {
      const next = { ...prev };
      pendingIds.forEach((id) => { next[id] = { ...next[id], status: "computed" }; });
      return next;
    });
    pushAudit({ type: "13th_month", staffId: null, staffName: "All eligible employees", action: "bulk_compute", oldAmount: null, newAmount: null, reason: "Bulk computation run" });
    showToast(`Computed 13th month pay for ${pendingIds.length} employee(s).`);
  }
  function handleTmCompute(record) { updateTmState(record.employee.id, { status: "computed" }); pushAudit({ type: "13th_month", staffId: record.employee.id, staffName: record.employee.shortName, action: "compute", oldAmount: null, newAmount: record.adjustedAmount, reason: "Initial computation" }); showToast(`Computed for ${record.employee.shortName}.`); }
  function handleTmRecompute(record) { pushAudit({ type: "13th_month", staffId: record.employee.id, staffName: record.employee.shortName, action: "recompute", oldAmount: record.adjustedAmount, newAmount: record.adjustedAmount, reason: "Manual recomputation triggered" }); showToast(`Recomputed for ${record.employee.shortName}.`); }
  function handleTmLock(record) { updateTmState(record.employee.id, { status: "locked" }); pushAudit({ type: "13th_month", staffId: record.employee.id, staffName: record.employee.shortName, action: "lock", oldAmount: record.adjustedAmount, newAmount: record.adjustedAmount, reason: "Record locked after release" }); showToast(`Locked ${record.employee.shortName}'s record.`); }
  function handleTmRelease(record) { updateTmState(record.employee.id, { status: "released", releasedOn: settings.releaseDate, paymentMethod: "Bank Transfer" }); pushAudit({ type: "13th_month", staffId: record.employee.id, staffName: record.employee.shortName, action: "release", oldAmount: record.adjustedAmount, newAmount: record.adjustedAmount, reason: "Released via Bank Transfer" }); showToast(`Released 13th month pay to ${record.employee.shortName}.`); }
  function handleTmUnlockConfirm(record, reason) { updateTmState(record.employee.id, { status: record.releasedOn ? "released" : "computed" }); pushAudit({ type: "13th_month", staffId: record.employee.id, staffName: record.employee.shortName, action: "unlock", oldAmount: record.adjustedAmount, newAmount: record.adjustedAmount, reason }); showToast(`Unlocked ${record.employee.shortName}'s record.`); setTmUnlockTarget(null); }
  function handleTmAdjustConfirm(record, amount, reason) {
    const newAdjustment = record.manualAdjustment + amount;
    const newTotal = Math.round((record.computedAmount + newAdjustment) * 100) / 100;
    updateTmState(record.employee.id, { manualAdjustment: newAdjustment });
    pushAudit({ type: "13th_month", staffId: record.employee.id, staffName: record.employee.shortName, action: "manual_adjustment", oldAmount: record.adjustedAmount, newAmount: newTotal, reason });
    showToast(`Adjustment applied to ${record.employee.shortName}.`);
    setTmAdjustTarget(null);
  }
  function toggleEarning(code) {
    if (code === "BASIC") return;
    setSettings((prev) => ({ ...prev, includedEarnings: prev.includedEarnings.includes(code) ? prev.includedEarnings.filter((c) => c !== code) : [...prev.includedEarnings, code] }));
  }
  function toggleEmploymentType(type) {
    setSettings((prev) => ({ ...prev, employmentTypesIncluded: prev.employmentTypesIncluded.includes(type) ? prev.employmentTypesIncluded.filter((t) => t !== type) : [...prev.employmentTypesIncluded, type] }));
  }

  return (
    <div style={{ fontFamily: FONT_BODY, background: COLOR.cream, minHeight: "100vh", color: COLOR.ink }}>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap');
        * { box-sizing: border-box; }
        table { border-collapse: collapse; width: 100%; }
        th { text-align: left; font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase; color: ${COLOR.inkSoft}; padding: 8px 12px; border-bottom: 2px solid ${COLOR.line}; }
        td { padding: 10px 12px; border-bottom: 1px solid ${COLOR.line}; font-size: 13px; vertical-align: top; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: ${COLOR.line}; border-radius: 4px; }
        button { font-family: 'Inter', sans-serif; }
      `}</style>

      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "12px 24px", borderBottom: `1px solid ${COLOR.line}`, background: "white" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <div style={{ width: 22, height: 22, borderRadius: 4, background: COLOR.gold }} />
          <div style={{ fontFamily: FONT_DISPLAY, fontWeight: 700, fontSize: 15 }}>Ongkoleyt</div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          {[["kiosk", "Kiosk · Clock In/Out"], ["staff-login", "Staff Timesheet Login"], ["admin", "Admin"]].map(([key, label]) => (
            <button key={key} onClick={() => setMode(key)} style={{ padding: "7px 14px", borderRadius: 999, border: `1px solid ${mode === key ? COLOR.espresso : COLOR.line}`, background: mode === key ? COLOR.espresso : "white", color: mode === key ? COLOR.cream : COLOR.ink, fontSize: 12.5, fontWeight: 600, cursor: "pointer" }}>
              {label}
            </button>
          ))}
        </div>
      </div>

      {mode === "kiosk" && <KioskFlow purpose="clock" now={now} attendance={attendance} settings={settings} onClockAction={handleClockAction} showToast={showToast} />}
      {mode === "staff-login" && <KioskFlow purpose="timesheet" now={now} attendance={attendance} settings={settings} showToast={showToast} />}
      {mode === "admin" && (
        <AdminArea
          now={now} settings={settings} setSettings={setSettings} attendance={attendance} tmRecords={tmRecords} auditLog={auditLog}
          onOpenAdjustAttendance={setAdjustAttendanceTarget} onApproveAttendance={handleApproveAttendance} onResetToday={handleResetToday}
          onTmComputeAll={handleTmComputeAll} onTmCompute={handleTmCompute} onTmRecompute={handleTmRecompute} onTmLock={handleTmLock}
          onTmRelease={handleTmRelease} onOpenTmAdjust={setTmAdjustTarget} onOpenTmUnlock={setTmUnlockTarget} onOpenTmPayslip={setTmPayslipTarget}
          toggleEarning={toggleEarning} toggleEmploymentType={toggleEmploymentType} showToast={showToast}
        />
      )}

      {adjustAttendanceTarget != null && (
        <AdjustAttendanceModal staffId={adjustAttendanceTarget} record={attendance[adjustAttendanceTarget]} onCancel={() => setAdjustAttendanceTarget(null)} onSave={handleSaveAttendanceAdjustment} />
      )}
      {tmAdjustTarget && <TmAdjustModal record={tmAdjustTarget} onCancel={() => setTmAdjustTarget(null)} onConfirm={handleTmAdjustConfirm} />}
      {tmUnlockTarget && <TmUnlockModal record={tmUnlockTarget} onCancel={() => setTmUnlockTarget(null)} onConfirm={handleTmUnlockConfirm} />}
      {tmPayslipTarget && <TmPayslipModal record={tmPayslipTarget} settings={settings} onClose={() => setTmPayslipTarget(null)} />}

      {toast && <div style={{ position: "fixed", bottom: 24, right: 24, background: COLOR.espresso, color: COLOR.cream, padding: "12px 18px", borderRadius: 8, fontSize: 13, boxShadow: "0 8px 24px rgba(0,0,0,0.25)" }}>{toast}</div>}
    </div>
  );
}

/* ---------------------------------------------------------------------- */
/* Kiosk flow: staff select -> PIN entry (clock in/out, or timesheet login)*/
/* ---------------------------------------------------------------------- */
function KioskFlow({ purpose, now, attendance, settings, onClockAction, showToast }) {
  const [step, setStep] = useState("select");
  const [selected, setSelected] = useState(null);
  const [search, setSearch] = useState("");
  const [pin, setPin] = useState("");
  const [error, setError] = useState(false);
  const filtered = STAFF.filter((s) => s.shortName.toLowerCase().includes(search.toLowerCase()));

  function pickStaff(staff) { setSelected(staff); setPin(""); setError(false); setStep("pin"); }
  function pressDigit(d) {
    if (pin.length >= 4) return;
    const next = pin + d; setPin(next);
    if (next.length === 4) setTimeout(() => submitPin(next), 150);
  }
  function submitPin(value) {
    if (value !== DEMO_PIN) { setError(true); setTimeout(() => { setPin(""); setError(false); }, 700); return; }
    if (purpose === "clock") {
      const existing = attendance[selected.id];
      const action = !existing || existing.clockOut ? "in" : "out";
      onClockAction(selected.id, action);
      showToast(`${selected.shortName} clocked ${action === "in" ? "in" : "out"} at ${formatClock(now)}.`);
      setStep("select"); setSelected(null);
    } else {
      setStep("dashboard");
    }
  }

  if (step === "dashboard" && selected) {
    return <StaffDashboard staff={selected} record={attendance[selected.id]} settings={settings} onLogout={() => { setStep("select"); setSelected(null); }} />;
  }

  if (step === "pin" && selected) {
    const existing = attendance[selected.id];
    const willClockIn = !existing || (existing.clockIn && existing.clockOut);
    return (
      <div style={{ minHeight: "80vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
        <div style={{ fontFamily: FONT_DISPLAY, fontSize: 52, fontWeight: 700 }}>{formatClock(now)}</div>
        <div style={{ fontSize: 15, color: COLOR.inkSoft, marginBottom: 28 }}>{TODAY_LABEL}</div>
        <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 20 }}>
          <div style={{ width: 48, height: 48, borderRadius: "50%", background: COLOR.rust, color: "white", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, fontSize: 16 }}>{initialsOf(selected.fullName)}</div>
          <div><div style={{ fontWeight: 700, fontSize: 17 }}>{selected.fullName}</div><div style={{ fontSize: 13, color: COLOR.inkSoft }}>{selected.role}</div></div>
        </div>
        {purpose === "clock" && (
          <div style={{ display: "flex", width: 340, borderRadius: 10, overflow: "hidden", border: `1px solid ${COLOR.line}`, marginBottom: 22 }}>
            <div style={{ flex: 1, textAlign: "center", padding: "10px 0", fontWeight: 700, fontSize: 13, background: willClockIn ? COLOR.green : COLOR.parchment, color: willClockIn ? "white" : COLOR.inkSoft }}>↓ Clock In</div>
            <div style={{ flex: 1, textAlign: "center", padding: "10px 0", fontWeight: 700, fontSize: 13, background: !willClockIn ? COLOR.espresso : COLOR.parchment, color: !willClockIn ? "white" : COLOR.inkSoft }}>↑ Clock Out</div>
          </div>
        )}
        {purpose === "timesheet" && <div style={{ fontSize: 13, color: COLOR.inkSoft, marginBottom: 22 }}>Enter your PIN to view your timesheet</div>}
        <div style={{ display: "flex", gap: 10, marginBottom: 22 }}>
          {[0, 1, 2, 3].map((i) => <div key={i} style={{ width: 14, height: 14, borderRadius: "50%", background: i < pin.length ? (error ? COLOR.rust : COLOR.espresso) : COLOR.line }} />)}
        </div>
        {error && <div style={{ color: COLOR.rust, fontSize: 12, marginTop: -14, marginBottom: 14 }}>Incorrect PIN — try again</div>}
        <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 90px)", gap: 12 }}>
          {["1", "2", "3", "4", "5", "6", "7", "8", "9"].map((d) => (
            <button key={d} onClick={() => pressDigit(d)} style={{ height: 64, borderRadius: 10, border: `1px solid ${COLOR.line}`, background: "white", fontSize: 20, fontWeight: 700, cursor: "pointer" }}>{d}</button>
          ))}
          <button onClick={() => setPin(pin.slice(0, -1))} style={{ height: 64, borderRadius: 10, border: `1px solid ${COLOR.line}`, background: COLOR.parchment, fontSize: 13, fontWeight: 700, color: COLOR.inkSoft, cursor: "pointer" }}>DEL</button>
          <button onClick={() => pressDigit("0")} style={{ height: 64, borderRadius: 10, border: `1px solid ${COLOR.line}`, background: "white", fontSize: 20, fontWeight: 700, cursor: "pointer" }}>0</button>
          <button onClick={() => pin.length === 4 && submitPin(pin)} style={{ height: 64, borderRadius: 10, border: "none", background: COLOR.greenSoft, fontSize: 20, color: COLOR.green, cursor: "pointer" }}>✓</button>
        </div>
        <div style={{ fontSize: 11, color: COLOR.inkSoft, marginTop: 14 }}>Demo PIN for everyone: {DEMO_PIN}</div>
        <button onClick={() => setStep("select")} style={{ marginTop: 18, background: "none", border: "none", color: COLOR.inkSoft, fontSize: 13, textDecoration: "underline", cursor: "pointer" }}>← Back</button>
      </div>
    );
  }

  return (
    <div style={{ minHeight: "80vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
      <div style={{ fontFamily: FONT_DISPLAY, fontSize: 52, fontWeight: 700 }}>{formatClock(now)}</div>
      <div style={{ fontSize: 15, color: COLOR.inkSoft, marginBottom: 20 }}>{TODAY_LABEL}</div>
      <div style={{ fontWeight: 700, fontSize: 19, marginBottom: 4 }}>{purpose === "clock" ? "Who are you?" : "Staff Timesheet Login"}</div>
      <div style={{ fontSize: 13, color: COLOR.inkSoft, marginBottom: 20 }}>{purpose === "clock" ? "Tap your name to continue" : "Tap your name to view your timesheet"}</div>
      <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name..." style={{ width: "min(900px, 92vw)", padding: "12px 16px", borderRadius: 10, border: `1px solid ${COLOR.line}`, fontSize: 14, marginBottom: 22 }} />
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, minmax(220px, 1fr))", gap: 16, width: "min(900px, 92vw)" }}>
        {filtered.map((s) => (
          <div key={s.id} onClick={() => pickStaff(s)} style={{ display: "flex", alignItems: "center", gap: 12, background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: "14px 16px", cursor: "pointer" }}>
            <div style={{ width: 42, height: 42, borderRadius: "50%", background: COLOR.rust, color: "white", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, fontSize: 14, flexShrink: 0 }}>{initialsOf(s.fullName)}</div>
            <div><div style={{ fontWeight: 700, fontSize: 14.5 }}>{s.shortName}</div><div style={{ fontSize: 12.5, color: COLOR.inkSoft }}>{s.role}</div></div>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------------- */
/* Staff self-service dashboard: Today + This Week + 13th Month estimate  */
/* ---------------------------------------------------------------------- */
function StaffDashboard({ staff, record, settings, onLogout }) {
  const pay = record ? computeDailyPay(record.clockIn, record.clockOut, settings) : null;
  const weekly = useMemo(() => buildWeeklyData(settings).find((r) => r.staff.id === staff.id), [staff.id, settings]);
  const eligible = isEligibleForThirteenthMonth(staff, settings);
  const tm = eligible ? computeThirteenthMonthRecord(staff, settings) : null;

  return (
    <div style={{ maxWidth: 900, margin: "0 auto", padding: "32px 24px" }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 20 }}>
        <div>
          <h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 24, margin: 0 }}>Hi, {staff.fullName.split(" ")[0]}</h1>
          <div style={{ fontSize: 13, color: COLOR.inkSoft }}>{staff.role} · {staff.branch} · {TODAY_LABEL}</div>
        </div>
        <Button variant="outline" onClick={onLogout}>Log Out</Button>
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20, marginBottom: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 12px" }}>Today</h3>
        {!record ? <div style={{ fontSize: 13, color: COLOR.inkSoft }}>You haven't clocked in yet today.</div> : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 16 }}>
            <MiniStat label="Clock In" value={formatTime12(record.clockIn)} />
            <MiniStat label="Clock Out" value={record.clockOut ? formatTime12(record.clockOut) : "Still working"} />
            <MiniStat label="Hours" value={pay ? formatHoursLabel(pay.totalHours) : "—"} />
            <MiniStat label="Total Pay" value={pay ? formatPHP(pay.total) : "—"} mono />
          </div>
        )}
        {record && <div style={{ marginTop: 10 }}><Pill tone={record.status === "approved" ? "approved" : "pending"}>{record.status}</Pill></div>}
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20, marginBottom: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 12px" }}>This Week</h3>
        {weekly ? (
          <table>
            <thead><tr><th>Days Worked</th><th>Total Hours</th><th>Basic</th><th>Overtime</th><th>Total Pay</th></tr></thead>
            <tbody><tr>
              <td>{weekly.daysWorked}</td>
              <td style={{ fontFamily: FONT_MONO }}>{weekly.totalHours}h</td>
              <td style={{ fontFamily: FONT_MONO }}>{formatPHP(weekly.basic)}</td>
              <td style={{ fontFamily: FONT_MONO }}>{formatPHP(weekly.ot)}</td>
              <td style={{ fontFamily: FONT_MONO, fontWeight: 700 }}>{formatPHP(weekly.total)}</td>
            </tr></tbody>
          </table>
        ) : <div style={{ fontSize: 13, color: COLOR.inkSoft }}>No hours logged yet this week.</div>}
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 12px" }}>13th Month Pay ({PAYROLL_YEAR}, estimated to date)</h3>
        {tm ? (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 16 }}>
            <MiniStat label="Basic Earned (Annual)" value={formatPHP(tm.totalBasicEarned)} mono />
            <MiniStat label="Formula" value="÷ 12" />
            <MiniStat label="Estimated 13th Month" value={formatPHP(tm.computedAmount)} mono />
          </div>
        ) : <div style={{ fontSize: 13, color: COLOR.inkSoft }}>Not currently eligible under this year's settings.</div>}
      </div>
    </div>
  );
}
function MiniStat({ label, value, mono }) {
  return <div><div style={{ fontSize: 11, color: COLOR.inkSoft, textTransform: "uppercase", letterSpacing: "0.05em", marginBottom: 4 }}>{label}</div><div style={{ fontFamily: mono ? FONT_MONO : FONT_BODY, fontWeight: 700, fontSize: 15 }}>{value}</div></div>;
}

/* ---------------------------------------------------------------------- */
/* Admin area                                                              */
/* ---------------------------------------------------------------------- */
function AdminArea(props) {
  const [tab, setTab] = useState("attendance");
  const { now, settings, setSettings, attendance, tmRecords, auditLog } = props;

  return (
    <div style={{ maxWidth: 1180, margin: "0 auto", padding: "28px 32px" }}>
      <div style={{ display: "flex", gap: 8, marginBottom: 20, flexWrap: "wrap" }}>
        {[["attendance", "Attendance"], ["payroll", "Payroll"], ["thirteenth-month", "13th Month"], ["settings", "Settings"], ["audit", "Audit Log"]].map(([key, label]) => (
          <button key={key} onClick={() => setTab(key)} style={tabBtnStyle(tab === key)}>{label}</button>
        ))}
      </div>

      {tab === "attendance" && (
        <AdminAttendanceView now={now} attendance={attendance} settings={settings} onOpenAdjust={props.onOpenAdjustAttendance} onApprove={props.onApproveAttendance} onResetToday={props.onResetToday} />
      )}
      {tab === "payroll" && <AdminPayrollView attendance={attendance} settings={settings} />}
      {tab === "thirteenth-month" && (
        <ThirteenthMonthView
          tmRecords={tmRecords} settings={settings}
          onComputeAll={props.onTmComputeAll} onCompute={props.onTmCompute} onRecompute={props.onTmRecompute}
          onLock={props.onTmLock} onRelease={props.onTmRelease} onOpenAdjust={props.onOpenTmAdjust}
          onOpenUnlock={props.onOpenTmUnlock} onOpenPayslip={props.onOpenTmPayslip}
        />
      )}
      {tab === "settings" && (
        <SettingsView settings={settings} setSettings={setSettings} toggleEarning={props.toggleEarning} toggleEmploymentType={props.toggleEmploymentType} showToast={props.showToast} />
      )}
      {tab === "audit" && <AuditLogView log={auditLog} />}
    </div>
  );
}

/* ---- Attendance ---- */
function AdminAttendanceView({ now, attendance, settings, onOpenAdjust, onApprove, onResetToday }) {
  const rows = STAFF.filter((s) => attendance[s.id]).map((s) => ({ staff: s, record: attendance[s.id] }));
  const clockedIn = rows.length;
  const pending = rows.filter((r) => r.record.status === "pending").length;
  const approved = rows.filter((r) => r.record.status === "approved").length;
  const totalPayToday = rows.reduce((sum, r) => { const pay = computeDailyPay(r.record.clockIn, r.record.clockOut, settings); return sum + (pay ? pay.total : 0); }, 0);

  return (
    <div>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", marginBottom: 18 }}>
        <div><h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 24, margin: 0 }}>Attendance</h1><div style={{ fontSize: 13, color: COLOR.inkSoft }}>Staff time logs · {TODAY_LABEL}</div></div>
        <Button variant="outline" onClick={onResetToday}>↻ Reset Today</Button>
      </div>
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard label="Clocked In Today" value={clockedIn} tone="ink" />
        <StatCard label="Pending Approval" value={pending} tone="amber" />
        <StatCard label="Total Pay Today" value={formatPHP(totalPayToday)} tone="rust" />
        <StatCard label="Approved" value={approved} tone="green" />
      </div>
      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, overflow: "hidden" }}>
        <div style={{ padding: "14px 18px", borderBottom: `1px solid ${COLOR.line}`, fontFamily: FONT_DISPLAY, fontSize: 15, fontWeight: 600 }}>Today's Time Log</div>
        <table>
          <thead><tr><th>Staff</th><th>Role</th><th>Branch</th><th>Clock In</th><th>Clock Out</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            {rows.map(({ staff, record }) => {
              const late = lateByMinutes(record.shiftStart, record.clockIn);
              return (
                <tr key={staff.id}>
                  <td style={{ fontWeight: 600 }}>{staff.shortName}</td>
                  <td>{staff.role}</td>
                  <td>{staff.branch}</td>
                  <td style={{ color: COLOR.green, fontWeight: 600 }}>{formatTime12(record.clockIn)}</td>
                  <td>{record.clockOut ? formatTime12(record.clockOut) : "—"}</td>
                  <td>
                    <Pill tone={record.status === "approved" ? "approved" : "pending"}>{record.status}</Pill>
                    <div style={{ fontSize: 11, color: COLOR.inkSoft, marginTop: 3 }}>{record.adjusted ? `Present (Adjusted) · ${record.reason}` : late > 0 ? `Late by ${late}m` : "On time"}</div>
                  </td>
                  <td>
                    <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                      <Button small variant="outline" onClick={() => onOpenAdjust(staff.id)}>Edit Times</Button>
                      {record.status === "pending" && <Button small variant="gold" onClick={() => onApprove(staff.id)}>Approve</Button>}
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
function AdjustAttendanceModal({ staffId, record, onCancel, onSave }) {
  const staff = STAFF.find((s) => s.id === staffId);
  const [clockIn, setClockIn] = useState(record?.clockIn || "08:00");
  const [clockOut, setClockOut] = useState(record?.clockOut || "17:00");
  const [reason, setReason] = useState("");
  const [details, setDetails] = useState("");
  const late = lateByMinutes(record?.shiftStart || SHIFT_START_24, record?.clockIn || "08:00");
  return (
    <ModalShell onClose={onCancel}>
      <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 18, margin: "0 0 2px" }}>Adjust Attendance</h3>
      <div style={{ fontSize: 12.5, color: COLOR.inkSoft, marginBottom: 16 }}>{staff?.shortName} · original: {formatTime12(record?.clockIn)} → {record?.clockOut ? formatTime12(record.clockOut) : "still clocked in"}</div>
      <div style={{ background: COLOR.parchment, borderRadius: 8, padding: "12px 14px", display: "flex", justifyContent: "space-between", marginBottom: 18 }}>
        <div><div style={{ fontSize: 11, color: COLOR.inkSoft, textTransform: "uppercase" }}>Shift Start</div><div style={{ fontWeight: 700 }}>{formatTime12(record?.shiftStart || SHIFT_START_24)}</div></div>
        <div><div style={{ fontSize: 11, color: COLOR.inkSoft, textTransform: "uppercase" }}>Clocked In</div><div style={{ fontWeight: 700, color: COLOR.rust }}>{formatTime12(record?.clockIn)}</div></div>
        <div><div style={{ fontSize: 11, color: COLOR.inkSoft, textTransform: "uppercase" }}>Late By</div><div style={{ fontWeight: 700, color: COLOR.rust }}>{late}m</div></div>
      </div>
      <div style={{ fontSize: 11, color: COLOR.inkSoft, textTransform: "uppercase", marginBottom: 8 }}>Adjust Times</div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12, marginBottom: 14 }}>
        <div><div style={{ fontSize: 12, color: COLOR.inkSoft, marginBottom: 4 }}>Clock In</div><input type="time" value={clockIn} onChange={(e) => setClockIn(e.target.value)} style={inputStyle} /></div>
        <div><div style={{ fontSize: 12, color: COLOR.inkSoft, marginBottom: 4 }}>Clock Out</div><input type="time" value={clockOut} onChange={(e) => setClockOut(e.target.value)} style={inputStyle} /></div>
      </div>
      <div style={{ fontSize: 11, color: COLOR.inkSoft, marginBottom: 16 }}>Original times are always saved in the audit log.</div>
      <div style={{ fontSize: 12, color: COLOR.inkSoft, marginBottom: 4 }}>Reason for Adjustment *</div>
      <select value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, marginBottom: 14 }}>
        <option value="">Select a reason...</option>
        {ATT_REASONS.map((r) => <option key={r} value={r}>{r}</option>)}
      </select>
      <div style={{ fontSize: 12, color: COLOR.inkSoft, marginBottom: 4 }}>Additional Details</div>
      <textarea value={details} onChange={(e) => setDetails(e.target.value)} placeholder="e.g. Sent to pick up supplies from SM City" style={{ ...inputStyle, minHeight: 64, marginBottom: 18 }} />
      <div style={{ display: "flex", gap: 8 }}>
        <Button variant="gold" disabled={!reason} onClick={() => onSave(staffId, clockIn, clockOut, reason, details)}>Save Adjustment</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </ModalShell>
  );
}

/* ---- Payroll ---- */
function AdminPayrollView({ attendance, settings }) {
  const [range, setRange] = useState("daily");
  const dailyRows = STAFF.filter((s) => attendance[s.id] && attendance[s.id].clockOut).map((s) => ({ staff: s, record: attendance[s.id], pay: computeDailyPay(attendance[s.id].clockIn, attendance[s.id].clockOut, settings) }));
  const weeklyRows = useMemo(() => buildWeeklyData(settings), [settings]);
  const approvedToday = dailyRows.filter((r) => r.record.status === "approved").reduce((s, r) => s + r.pay.total, 0);
  const pendingToday = dailyRows.filter((r) => r.record.status !== "approved").reduce((s, r) => s + r.pay.total, 0);
  const totalToday = approvedToday + pendingToday;

  function exportCSV() {
    const rows = range === "daily" ? dailyRows : weeklyRows;
    const header = range === "daily" ? ["Staff", "Role", "Branch", "Clock In", "Clock Out", "Hours", "Basic", "OT", "Night Diff", "Total Pay", "Status"] : ["Staff", "Role", "Branch", "Days Worked", "Total Hours", "Basic", "OT", "Total Pay"];
    const body = range === "daily"
      ? rows.map((r) => [r.staff.shortName, r.staff.role, r.staff.branch, formatTime12(r.record.clockIn), formatTime12(r.record.clockOut), formatHoursLabel(r.pay.totalHours), r.pay.basic, r.pay.ot, r.pay.nightDiff, r.pay.total, r.record.status])
      : rows.map((r) => [r.staff.shortName, r.staff.role, r.staff.branch, r.daysWorked, r.totalHours, r.basic, r.ot, r.total]);
    const csv = [header, ...body].map((row) => row.map((c) => `"${c}"`).join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a"); a.href = url; a.download = `payroll-${range}-2026-07-21.csv`; a.click(); URL.revokeObjectURL(url);
  }

  return (
    <div>
      <div style={{ marginBottom: 18 }}><h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 24, margin: 0 }}>Payroll</h1><div style={{ fontSize: 13, color: COLOR.inkSoft }}>Daily and weekly pay computation · Admin only</div></div>
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard label="Approved Today" value={formatPHP(approvedToday)} tone="green" />
        <StatCard label="Pending" value={formatPHP(pendingToday)} tone="rust" />
        <StatCard label="Total Today" value={formatPHP(totalToday)} tone="rust" />
      </div>
      <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 14 }}>
        <div style={{ display: "flex", gap: 8 }}>
          <button onClick={() => setRange("daily")} style={tabBtnStyle(range === "daily")}>Daily</button>
          <button onClick={() => setRange("weekly")} style={tabBtnStyle(range === "weekly")}>Weekly</button>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <Button variant="outline" onClick={exportCSV}>⬇ CSV</Button>
          <Button variant="rust" onClick={() => window.print()}>⬇ PDF</Button>
        </div>
      </div>
      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, overflow: "hidden" }}>
        <div style={{ padding: "14px 18px", borderBottom: `1px solid ${COLOR.line}`, fontFamily: FONT_DISPLAY, fontSize: 15, fontWeight: 600 }}>{range === "daily" ? `Daily Payroll — ${TODAY_LABEL}` : "Weekly Payroll — Jul 15–21, 2026"}</div>
        <table>
          {range === "daily" ? (
            <>
              <thead><tr><th>Staff</th><th>Role</th><th>Branch</th><th>Clock In</th><th>Clock Out</th><th>Hours</th><th>Basic</th><th>OT</th><th>Night Diff</th><th>Total Pay</th><th>Status</th></tr></thead>
              <tbody>
                {dailyRows.map(({ staff, record, pay }) => (
                  <tr key={staff.id}>
                    <td style={{ fontWeight: 600 }}>{staff.shortName}</td><td>{staff.role}</td><td>{staff.branch}</td>
                    <td>{formatTime12(record.clockIn)}</td><td>{formatTime12(record.clockOut)}</td><td>{formatHoursLabel(pay.totalHours)}</td>
                    <td style={{ fontFamily: FONT_MONO }}>{formatPHP(pay.basic)}</td>
                    <td style={{ fontFamily: FONT_MONO }}>{pay.ot > 0 ? formatPHP(pay.ot) : "—"}</td>
                    <td style={{ fontFamily: FONT_MONO }}>{pay.nightDiff > 0 ? formatPHP(pay.nightDiff) : "—"}</td>
                    <td style={{ fontFamily: FONT_MONO, fontWeight: 700, color: COLOR.rust }}>{formatPHP(pay.total)}</td>
                    <td><Pill tone={record.status === "approved" ? "approved" : "pending"}>{record.status}</Pill></td>
                  </tr>
                ))}
              </tbody>
            </>
          ) : (
            <>
              <thead><tr><th>Staff</th><th>Role</th><th>Branch</th><th>Days Worked</th><th>Total Hours</th><th>Basic</th><th>OT</th><th>Total Pay</th></tr></thead>
              <tbody>
                {weeklyRows.map((r) => (
                  <tr key={r.staff.id}>
                    <td style={{ fontWeight: 600 }}>{r.staff.shortName}</td><td>{r.staff.role}</td><td>{r.staff.branch}</td>
                    <td>{r.daysWorked}</td><td>{r.totalHours}h</td>
                    <td style={{ fontFamily: FONT_MONO }}>{formatPHP(r.basic)}</td>
                    <td style={{ fontFamily: FONT_MONO }}>{r.ot > 0 ? formatPHP(r.ot) : "—"}</td>
                    <td style={{ fontFamily: FONT_MONO, fontWeight: 700, color: COLOR.rust }}>{formatPHP(r.total)}</td>
                  </tr>
                ))}
              </tbody>
            </>
          )}
        </table>
        <div style={{ display: "flex", justifyContent: "space-between", padding: "14px 18px", fontWeight: 700, color: COLOR.rust }}>
          <span>TOTAL {range === "daily" ? "TODAY" : "THIS WEEK"}</span>
          <span style={{ fontFamily: FONT_MONO }}>{range === "daily" ? formatPHP(totalToday) : formatPHP(weeklyRows.reduce((s, r) => s + r.total, 0))}</span>
        </div>
      </div>
    </div>
  );
}

/* ---- 13th Month ---- */
function ThirteenthMonthView({ tmRecords, settings, onComputeAll, onCompute, onRecompute, onLock, onRelease, onOpenAdjust, onOpenUnlock, onOpenPayslip }) {
  const [subTab, setSubTab] = useState("dashboard");
  const pending = tmRecords.filter((r) => r.status === "pending").length;
  const processed = tmRecords.filter((r) => r.status !== "pending").length;
  const released = tmRecords.filter((r) => r.status === "released" || r.status === "locked").length;
  const totalLiability = tmRecords.reduce((s, r) => s + r.adjustedAmount, 0);
  const releaseStatus = released === 0 ? "Not started" : released >= tmRecords.length ? "Complete" : "In progress";
  const branchSummary = BRANCHES.map((b) => {
    const rows = tmRecords.filter((r) => r.employee.branch === b);
    return { branch: b, count: rows.length, total: rows.reduce((s, r) => s + r.adjustedAmount, 0) };
  });

  return (
    <div>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", marginBottom: 18 }}>
        <div><h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 24, margin: 0 }}>13th Month Pay</h1><div style={{ fontSize: 13, color: COLOR.inkSoft }}>Payroll Year {PAYROLL_YEAR} · Release date {settings.releaseDate}</div></div>
        {subTab === "dashboard" && <Button variant="gold" onClick={onComputeAll} disabled={pending === 0}>Compute All Pending ({pending})</Button>}
      </div>
      <div style={{ display: "flex", gap: 8, marginBottom: 20 }}>
        {[["dashboard", "Dashboard"], ["employees", "Employees"], ["reports", "Reports"]].map(([key, label]) => (
          <button key={key} onClick={() => setSubTab(key)} style={tabBtnStyle(subTab === key)}>{label}</button>
        ))}
      </div>

      {subTab === "dashboard" && (
        <div>
          <div style={{ display: "flex", gap: 16, flexWrap: "wrap", marginBottom: 24 }}>
            <StatCard label="Eligible Employees" value={tmRecords.length} tone="ink" />
            <StatCard label="Total Liability" value={formatPHP(totalLiability)} tone="rust" />
            <StatCard label="Processed" value={`${processed} of ${tmRecords.length}`} tone="ink" />
            <StatCard label="Pending" value={pending} tone="amber" />
            <StatCard label="Release Status" value={releaseStatus} tone="green" />
          </div>
          <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, overflow: "hidden" }}>
            <div style={{ padding: "14px 18px", borderBottom: `1px solid ${COLOR.line}`, fontFamily: FONT_DISPLAY, fontSize: 15, fontWeight: 600 }}>Branch Summary</div>
            <table>
              <thead><tr><th>Branch</th><th>Employees</th><th>Total 13th Month Liability</th></tr></thead>
              <tbody>{branchSummary.map((b) => (<tr key={b.branch}><td style={{ fontWeight: 600 }}>{b.branch}</td><td>{b.count}</td><td style={{ fontFamily: FONT_MONO }}>{formatPHP(b.total)}</td></tr>))}</tbody>
            </table>
          </div>
        </div>
      )}

      {subTab === "employees" && (
        <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, overflow: "auto" }}>
          <table>
            <thead><tr><th>Employee</th><th>Role</th><th>Branch</th><th>Basic Earned (Annual)</th><th>Overtime Pay</th><th>13th Month Amount</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              {tmRecords.map((r) => (
                <tr key={r.employee.id}>
                  <td><div style={{ fontWeight: 600 }}>{r.employee.shortName}</div><div style={{ fontSize: 11, color: COLOR.inkSoft }}>ONG-{String(1000 + r.employee.id)}</div></td>
                  <td>{r.employee.role}</td>
                  <td>{r.employee.branch}</td>
                  <td style={{ fontFamily: FONT_MONO }}>{formatPHP(r.totalBasicEarned)}</td>
                  <td style={{ fontFamily: FONT_MONO, color: COLOR.inkSoft }}>{formatPHP(r.totalOvertimePay)}</td>
                  <td style={{ fontFamily: FONT_MONO, fontWeight: 600 }}>{formatPHP(r.adjustedAmount)}</td>
                  <td><Pill tone={r.status}>{r.status}</Pill></td>
                  <td>
                    <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                      {r.status === "pending" && <Button small variant="gold" onClick={() => onCompute(r)}>Compute</Button>}
                      {r.status !== "pending" && r.status !== "locked" && <Button small variant="outline" onClick={() => onRecompute(r)}>Recompute</Button>}
                      {r.status !== "pending" && r.status !== "locked" && <Button small variant="outline" onClick={() => onOpenAdjust(r)}>Adjust</Button>}
                      {r.status === "computed" && <Button small variant="primary" onClick={() => onRelease(r)}>Release</Button>}
                      {(r.status === "computed" || r.status === "released") && <Button small variant="danger" onClick={() => onLock(r)}>Lock</Button>}
                      {r.status === "locked" && <Button small variant="outline" onClick={() => onOpenUnlock(r)}>Unlock</Button>}
                      {r.status !== "pending" && <Button small variant="ghost" onClick={() => onOpenPayslip(r)}>Payslip</Button>}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {subTab === "reports" && (
        <div>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 16, marginBottom: 24 }}>
            <ReportCard label="13th Month Summary" rows={[["Eligible employees", tmRecords.length], ["Total liability", formatPHP(totalLiability)], ["Release status", releaseStatus]]} />
            <ReportCard label="Year-End Payroll" rows={[["13th month total", formatPHP(totalLiability)], ["Regular payroll total", "— (from Payroll tab)"]]} />
            <ReportCard label="Per-Employee" rows={[["Records generated", tmRecords.length], ["Avg. 13th month amount", formatPHP(totalLiability / (tmRecords.length || 1))]]} />
          </div>
          <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, overflow: "hidden" }}>
            <div style={{ padding: "14px 18px", borderBottom: `1px solid ${COLOR.line}`, fontFamily: FONT_DISPLAY, fontSize: 15, fontWeight: 600 }}>13th Month Per Branch</div>
            <table><thead><tr><th>Branch</th><th>Employees</th><th>Total Liability</th></tr></thead>
              <tbody>{branchSummary.map((b) => (<tr key={b.branch}><td style={{ fontWeight: 600 }}>{b.branch}</td><td>{b.count}</td><td style={{ fontFamily: FONT_MONO }}>{formatPHP(b.total)}</td></tr>))}</tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
function ReportCard({ label, rows }) {
  return (
    <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 16 }}>
      <div style={{ fontFamily: FONT_DISPLAY, fontSize: 13.5, fontWeight: 600, marginBottom: 10 }}>{label}</div>
      {rows.map(([k, v]) => <div key={k} style={{ display: "flex", justifyContent: "space-between", fontSize: 12.5, padding: "4px 0", color: COLOR.inkSoft }}><span>{k}</span><span style={{ fontFamily: FONT_MONO, color: COLOR.ink, fontWeight: 600 }}>{v}</span></div>)}
    </div>
  );
}
function TmAdjustModal({ record, onCancel, onConfirm }) {
  const [amount, setAmount] = useState(""); const [reason, setReason] = useState("");
  return (
    <ModalShell onClose={onCancel}>
      <h3 style={{ fontFamily: FONT_DISPLAY, margin: "0 0 4px" }}>Manual Adjustment</h3>
      <p style={{ fontSize: 12, color: COLOR.inkSoft, margin: "0 0 16px" }}>{record.employee.shortName} · Current amount {formatPHP(record.adjustedAmount)}</p>
      <Field label="Adjustment Amount (use negative for deduction)"><input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} style={inputStyle} placeholder="e.g. 500 or -500" /></Field>
      <Field label="Reason"><textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} placeholder="Required for the audit log" /></Field>
      <div style={{ display: "flex", gap: 8, marginTop: 8 }}>
        <Button variant="gold" disabled={!amount || reason.length < 5} onClick={() => onConfirm(record, Number(amount), reason)}>Apply Adjustment</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </ModalShell>
  );
}
function TmUnlockModal({ record, onCancel, onConfirm }) {
  const [reason, setReason] = useState("");
  return (
    <ModalShell onClose={onCancel}>
      <h3 style={{ fontFamily: FONT_DISPLAY, margin: "0 0 4px" }}>Unlock Record</h3>
      <p style={{ fontSize: 12, color: COLOR.inkSoft, margin: "0 0 16px" }}>{record.employee.shortName} — unlocking a released record requires an approval reason.</p>
      <Field label="Reason for unlock (approval note)"><textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} placeholder="e.g. Corrected basic salary after payroll error, approved by HR Manager" /></Field>
      <div style={{ display: "flex", gap: 8, marginTop: 8 }}>
        <Button variant="danger" disabled={reason.length < 5} onClick={() => onConfirm(record, reason)}>Confirm Unlock</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </ModalShell>
  );
}
function TmPayslipModal({ record, settings, onClose }) {
  const r = record;
  return (
    <ModalShell onClose={onClose} width={620}>
      <div style={{ textAlign: "center", borderBottom: `2px solid ${COLOR.espresso}`, paddingBottom: 12, marginBottom: 16 }}>
        <div style={{ fontFamily: FONT_DISPLAY, fontSize: 18, fontWeight: 700 }}>13th Month Pay Payslip</div>
        <div style={{ fontSize: 12, color: COLOR.inkSoft }}>Payroll Year {PAYROLL_YEAR}</div>
      </div>
      <PayRow label="Employee" value={r.employee.fullName} />
      <PayRow label="Role" value={r.employee.role} />
      <PayRow label="Branch" value={r.employee.branch} />
      <div style={{ height: 1, background: COLOR.line, margin: "12px 0" }} />
      <div style={{ fontSize: 11, letterSpacing: "0.06em", textTransform: "uppercase", color: COLOR.inkSoft, marginBottom: 6 }}>Rate Basis — Day → Month</div>
      <PayRow label="Daily Basic Rate" value={formatPHP(settings.dailyBasicRate)} mono />
      <PayRow label="× Standard Working Days / Month" value={settings.standardWorkingDaysPerMonth} mono />
      <PayRow label="= Monthly Basic Salary" value={formatPHP(settings.dailyBasicRate * settings.standardWorkingDaysPerMonth)} mono bold />
      <div style={{ height: 1, background: COLOR.line, margin: "12px 0" }} />
      <div style={{ fontSize: 11, letterSpacing: "0.06em", textTransform: "uppercase", color: COLOR.inkSoft, marginBottom: 6 }}>Month → Year</div>
      <div style={{ maxHeight: 220, overflow: "auto", border: `1px solid ${COLOR.line}`, borderRadius: 6, marginBottom: 4 }}>
        <table>
          <thead><tr><th>Month</th><th>Basic Pay</th><th>OT Hrs</th><th>OT Pay</th><th>Other</th><th>Month Total</th></tr></thead>
          <tbody>
            {r.breakdown.map((m) => (
              <tr key={m.month} style={{ opacity: m.worked ? 1 : 0.4 }}>
                <td>{m.monthName}</td>
                <td style={{ fontFamily: FONT_MONO }}>{m.worked ? formatPHP(m.basicPay) : "—"}</td>
                <td style={{ fontFamily: FONT_MONO }}>{m.worked ? m.otHours : "—"}</td>
                <td style={{ fontFamily: FONT_MONO }}>{m.worked ? formatPHP(m.otPay) : "—"}</td>
                <td style={{ fontFamily: FONT_MONO }}>{m.worked ? formatPHP(m.otherPay) : "—"}</td>
                <td style={{ fontFamily: FONT_MONO, fontWeight: 600 }}>{m.worked ? formatPHP(m.monthTotalIncluded) : "—"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <PayRow label="Total Basic Salary Earned (Annual)" value={formatPHP(r.totalBasicEarned)} mono />
      <PayRow label={`Total Overtime Pay, Annual (${settings.includedEarnings.includes("OVERTIME") ? "included in base" : "not in base"})`} value={formatPHP(r.totalOvertimePay)} mono />
      <PayRow label="Formula" value="Total Basic Salary Earned ÷ 12" />
      <PayRow label="Computed Amount" value={formatPHP(r.computedAmount)} mono />
      <PayRow label="Manual Adjustment" value={formatPHP(r.manualAdjustment)} mono />
      <PayRow label="13th Month Amount" value={formatPHP(r.adjustedAmount)} mono bold />
      <div style={{ height: 1, background: COLOR.line, margin: "12px 0" }} />
      <PayRow label="Release Date" value={r.releasedOn || settings.releaseDate} />
      <PayRow label="Payment Method" value={r.paymentMethod || "—"} />
      <PayRow label="Status" value={<Pill tone={r.status}>{r.status}</Pill>} />
      <div style={{ display: "flex", gap: 8, marginTop: 18 }}>
        <Button variant="gold" onClick={() => window.print()}>Download PDF</Button>
        <Button variant="ghost" onClick={onClose}>Close</Button>
      </div>
    </ModalShell>
  );
}

/* ---- Settings (shared across attendance, payroll, and 13th month) ---- */
function SettingsView({ settings, setSettings, toggleEarning, toggleEmploymentType, showToast }) {
  return (
    <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20 }}>
      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 6px" }}>Basic Pay Rate & Overtime</h3>
        <p style={{ fontSize: 12, color: COLOR.inkSoft, margin: "0 0 12px" }}>Drives Attendance, Payroll, and 13th Month together — change it once here.</p>
        <Field label="Daily Basic Rate (₱)"><input type="number" min={0} value={settings.dailyBasicRate} onChange={(e) => setSettings((s) => ({ ...s, dailyBasicRate: Number(e.target.value) }))} style={inputStyle} /></Field>
        <Field label="Standard Working Days / Month"><input type="number" min={1} max={31} value={settings.standardWorkingDaysPerMonth} onChange={(e) => setSettings((s) => ({ ...s, standardWorkingDaysPerMonth: Number(e.target.value) }))} style={inputStyle} /></Field>
        <Field label="Overtime Multiplier (e.g. 1.25 = 125%)"><input type="number" min={1} step={0.05} value={settings.overtimeMultiplier} onChange={(e) => setSettings((s) => ({ ...s, overtimeMultiplier: Number(e.target.value) }))} style={inputStyle} /></Field>
        <Field label="Night Differential Multiplier (e.g. 0.10 = +10%)"><input type="number" min={0} step={0.01} value={settings.nightDiffMultiplier} onChange={(e) => setSettings((s) => ({ ...s, nightDiffMultiplier: Number(e.target.value) }))} style={inputStyle} /></Field>
        <div style={{ background: COLOR.parchment, borderRadius: 6, padding: "10px 12px", marginTop: 8 }}>
          <PayRow label="Standard Monthly Basic Salary" value={formatPHP(settings.dailyBasicRate * settings.standardWorkingDaysPerMonth)} mono />
          <PayRow label="Hourly Rate" value={formatPHP(settings.dailyBasicRate / 8)} mono />
        </div>
        <div style={{ marginTop: 12 }}><Button variant="gold" onClick={() => showToast("Settings saved.")}>Save Settings</Button></div>
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 6px" }}>13th Month Period &amp; Release</h3>
        <Field label="Period Start"><input type="date" value={settings.periodStart} onChange={(e) => setSettings((s) => ({ ...s, periodStart: e.target.value }))} style={inputStyle} /></Field>
        <Field label="Period End"><input type="date" value={settings.periodEnd} onChange={(e) => setSettings((s) => ({ ...s, periodEnd: e.target.value }))} style={inputStyle} /></Field>
        <Field label="Release Date"><input type="date" value={settings.releaseDate} onChange={(e) => setSettings((s) => ({ ...s, releaseDate: e.target.value }))} style={inputStyle} /></Field>
        <Field label="Minimum Months of Service"><input type="number" min={0} max={12} value={settings.minimumMonths} onChange={(e) => setSettings((s) => ({ ...s, minimumMonths: Number(e.target.value) }))} style={inputStyle} /></Field>
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 6px" }}>Included Earnings (13th Month Base)</h3>
        <p style={{ fontSize: 12, color: COLOR.inkSoft, margin: "0 0 12px" }}>Per PD 851, only Basic Salary counts unless you explicitly include other types below.</p>
        {Object.entries(EARNING_CODES).map(([code, label]) => (
          <label key={code} style={{ display: "flex", alignItems: "center", gap: 10, padding: "7px 0", fontSize: 13, cursor: code === "BASIC" ? "default" : "pointer" }}>
            <input type="checkbox" checked={settings.includedEarnings.includes(code)} disabled={code === "BASIC"} onChange={() => toggleEarning(code)} />
            {label}{code === "BASIC" && <span style={{ fontSize: 10, color: COLOR.inkSoft }}>(mandatory)</span>}
          </label>
        ))}
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 6px" }}>Employment Type Eligibility</h3>
        <p style={{ fontSize: 12, color: COLOR.inkSoft, margin: "0 0 12px" }}>DOLE requires 13th month pay for rank-and-file employees with at least one month of service.</p>
        <div style={{ display: "flex", gap: 24, flexWrap: "wrap" }}>
          {EMPLOYMENT_TYPES.map((type) => (
            <label key={type} style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 13, cursor: "pointer", textTransform: "capitalize" }}>
              <input type="checkbox" checked={settings.employmentTypesIncluded.includes(type)} onChange={() => toggleEmploymentType(type)} />{type.replace("_", " ")}
            </label>
          ))}
        </div>
      </div>
    </div>
  );
}

/* ---- Combined Audit Log ---- */
function AuditLogView({ log }) {
  if (log.length === 0) return <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 40, textAlign: "center", color: COLOR.inkSoft, fontSize: 13 }}>No actions recorded yet. Adjust attendance, compute, adjust, lock, unlock, or release a 13th month record to see it logged here.</div>;
  return (
    <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, overflow: "auto" }}>
      <table>
        <thead><tr><th>Timestamp</th><th>Staff</th><th>Area</th><th>Action</th><th>Detail / Old → New</th><th>Reason</th></tr></thead>
        <tbody>
          {log.map((entry) => (
            <tr key={entry.id}>
              <td style={{ fontSize: 12, color: COLOR.inkSoft }}>{entry.timestamp}</td>
              <td>{entry.staffName}</td>
              <td><Pill>{entry.type === "attendance" ? "Attendance" : "13th Month"}</Pill></td>
              <td><Pill tone="neutral">{entry.action.replace("_", " ")}</Pill></td>
              <td style={{ fontSize: 12 }}>{entry.detail ? entry.detail : (entry.oldAmount != null ? `${formatPHP(entry.oldAmount)} → ${formatPHP(entry.newAmount)}` : "—")}</td>
              <td style={{ fontSize: 12 }}>{entry.reason}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
