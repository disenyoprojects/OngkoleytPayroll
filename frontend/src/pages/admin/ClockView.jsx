import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { COLOR, FONT_DISPLAY, formatTime12 } from "../../theme";
import { Button } from "../../components/ui";
import { useOnlineStatus } from "../../offline/useOnlineStatus";
import { enqueueClock, onQueueChange, pendingActionFor, removeFromQueue } from "../../offline/clockQueue";

const STAFF_CACHE_KEY = "ongkoleyt_clock_staff_cache";

// The day's six punches, in timesheet order. The employee taps the one that
// matches what they are doing — the system never infers it from the tap count,
// so a half day (Clock In, Clock Out) can't land in the break columns.
const PUNCHES = [
  { action: "in", label: "Clock In", column: "clock_in", after: null },
  { action: "break-out", label: "Break Out", column: "break_out", after: "clock_in" },
  { action: "break-in", label: "Break In", column: "break_in", after: "break_out" },
  { action: "out", label: "Clock Out", column: "clock_out", after: "clock_in" },
  { action: "ot-in", label: "OT In", column: "ot_in", after: "clock_out" },
  { action: "ot-out", label: "OT Out", column: "ot_out", after: "ot_in" },
];

/** Punches still open for the day: not already recorded, and their prerequisite is. */
function availablePunches(record) {
  if (!record) return PUNCHES.filter((p) => p.after === null);
  return PUNCHES.filter((p) => !record[p.column] && (p.after === null || record[p.after]));
}

function initialsOf(name) {
  const parts = name.trim().split(/\s+/);
  return parts.length === 1 ? parts[0].slice(0, 2).toUpperCase() : (parts[0][0] + parts[1][0]).toUpperCase();
}

function branchOptionsOf(staff) {
  return Array.from(new Map(staff.filter((s) => s.branch).map((s) => [s.branch.id, s.branch.name])).entries())
    .map(([id, name]) => ({ id, name }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

function loadCachedStaff() {
  try {
    const raw = localStorage.getItem(STAFF_CACHE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

export default function ClockView() {
  const [staff, setStaff] = useState(null); // null = loading, [] = loaded empty
  const [loadError, setLoadError] = useState(false);
  const [usingCache, setUsingCache] = useState(false);
  const [search, setSearch] = useState("");
  const [branchFilter, setBranchFilter] = useState("");
  const [selected, setSelected] = useState(null); // { employee, record, unknown }
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);
  const [, setQueueTick] = useState(0); // bumped on queue changes, to re-render queued badges
  const online = useOnlineStatus();

  useEffect(() => {
    setLoadError(false);
    apiClient.get("/api/admin/clock/staff")
      .then((res) => {
        setStaff(res.data);
        setUsingCache(false);
        localStorage.setItem(STAFF_CACHE_KEY, JSON.stringify(res.data));
      })
      .catch(() => {
        const cached = loadCachedStaff();
        if (cached) {
          setStaff(cached);
          setUsingCache(true);
        } else {
          setStaff([]);
          setLoadError(true);
        }
      });
  }, []);

  useEffect(() => onQueueChange(() => setQueueTick((n) => n + 1)), []);

  function showToast(msg) {
    setToast(msg);
    setTimeout(() => setToast(null), 2800);
  }

  async function pick(employee) {
    const queued = pendingActionFor(employee.id);
    if (queued) {
      setSelected({ employee, queued });
      return;
    }
    if (!online) {
      // No pending queue entry and no connection — we genuinely don't know
      // today's status. Let the person pick the correct action themselves,
      // same as tapping a physical time clock; the server (or the sync
      // step) catches anything that doesn't make sense once it's reachable.
      setSelected({ employee, record: null, unknown: true });
      return;
    }
    try {
      const { data } = await apiClient.get(`/api/admin/clock/status?employee_id=${employee.id}`);
      setSelected({ employee, record: data, unknown: false });
    } catch {
      setSelected({ employee, record: null, unknown: true });
    }
  }

  async function doClock(action) {
    if (busy || !selected) return;
    setBusy(true);
    const { employee } = selected;
    const clockedAt = new Date().toISOString();
    const label = PUNCHES.find((p) => p.action === action)?.label ?? action;
    try {
      await apiClient.post(`/api/admin/clock/${action}`, { employee_id: employee.id, clocked_at: clockedAt });
      showToast(`${employee.short_name}: ${label} recorded.`);
      setSelected(null);
    } catch (e) {
      if (!e?.response) {
        // Couldn't reach the server at all — queue it for later instead of failing.
        enqueueClock({ employeeId: employee.id, employeeName: employee.short_name, action });
        showToast(`${employee.short_name}: offline — queued to sync automatically.`);
        setSelected(null);
      } else {
        const msg = e.response?.data?.errors ? Object.values(e.response.data.errors)[0][0] : "Something went wrong.";
        showToast(msg);
      }
    } finally {
      setBusy(false);
    }
  }

  function cancelQueued() {
    if (!selected?.queued) return;
    removeFromQueue(selected.queued.localId);
    showToast(`Cancelled queued clock ${selected.queued.action === "in" ? "in" : "out"} for ${selected.employee.short_name}.`);
    setSelected(null);
  }

  const list = staff ?? [];
  const branches = branchOptionsOf(list);
  const filtered = list.filter((s) =>
    s.short_name.toLowerCase().includes(search.toLowerCase()) &&
    (!branchFilter || String(s.branch_id) === String(branchFilter))
  );

  if (selected) {
    const { employee, record, unknown, queued } = selected;
    const available = unknown ? PUNCHES : availablePunches(record);
    const recorded = record ? PUNCHES.filter((p) => record[p.column]) : [];
    return (
      <div style={{ minHeight: "60vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 24 }}>
          <div style={{ width: 56, height: 56, borderRadius: "50%", background: COLOR.rust, color: "white", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, fontSize: 20 }}>{initialsOf(employee.full_name)}</div>
          <div>
            <div style={{ fontWeight: 700, fontSize: 19 }}>{employee.full_name}</div>
            <div style={{ fontSize: 13, color: COLOR.inkSoft }}>{employee.role}</div>
          </div>
        </div>

        {queued ? (
          <>
            <div style={{ fontSize: 14, color: COLOR.inkSoft, marginBottom: 20, textAlign: "center" }}>
              Queued to clock {queued.action === "in" ? "in" : "out"} at {formatTime12(new Date(queued.clocked_at).toTimeString().slice(0, 8))} — waiting to sync.
            </div>
            <div style={{ display: "flex", gap: 12 }}>
              <Button variant="outline" onClick={cancelQueued}>Cancel queued action</Button>
              <Button variant="outline" onClick={() => setSelected(null)}>Back</Button>
            </div>
          </>
        ) : (
          <>
            <div style={{ fontSize: 14, color: COLOR.inkSoft, marginBottom: 20, textAlign: "center" }}>
              {unknown
                ? "Offline — today's status is unknown. Choose the action that actually happened:"
                : recorded.length === 0 ? "Not clocked in yet today."
                : recorded.map((p) => `${p.label} ${formatTime12(record[p.column])}`).join(" · ")}
            </div>
            {!unknown && available.length === 0 && (
              <div style={{ fontSize: 13, color: COLOR.inkSoft, marginBottom: 16 }}>All punches recorded for today.</div>
            )}
            <div style={{ display: "flex", gap: 12, flexWrap: "wrap", justifyContent: "center" }}>
              {available.map((p) => (
                <Button key={p.action} variant="gold" onClick={() => doClock(p.action)} disabled={busy}>{p.label}</Button>
              ))}
              <Button variant="outline" onClick={() => setSelected(null)} disabled={busy}>Back</Button>
            </div>
          </>
        )}
        {toast && <div style={{ position: "fixed", bottom: 24, right: 24, background: COLOR.espresso, color: COLOR.cream, padding: "12px 18px", borderRadius: 8, fontSize: 13 }}>{toast}</div>}
      </div>
    );
  }

  return (
    <div style={{ display: "flex", flexDirection: "column", alignItems: "center", padding: "12px 0 48px" }}>
      <div style={{ fontWeight: 700, fontSize: 22, marginBottom: 4, fontFamily: FONT_DISPLAY }}>Clock In / Out</div>
      <div style={{ fontSize: 13, color: COLOR.inkSoft, marginBottom: 4 }}>Tap a name to clock them in or out</div>
      {usingCache && <div style={{ fontSize: 12, color: COLOR.rust, marginBottom: 16 }}>Offline — showing the staff list from your last visit.</div>}
      <div style={{ display: "flex", gap: 12, width: "min(900px, 92vw)", marginBottom: 22, flexWrap: "wrap", marginTop: usingCache ? 0 : 16 }}>
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name..." style={{ flex: "1 1 260px", padding: "12px 16px", borderRadius: 10, border: `1px solid ${COLOR.line}`, fontSize: 14 }} />
        <select value={branchFilter} onChange={(e) => setBranchFilter(e.target.value)} style={{ padding: "12px 16px", borderRadius: 10, border: `1px solid ${COLOR.line}`, fontSize: 14, background: "white" }}>
          <option value="">All branches</option>
          {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, minmax(220px, 1fr))", gap: 16, width: "min(900px, 92vw)" }}>
        {filtered.map((s) => {
          const queued = pendingActionFor(s.id);
          return (
            <div key={s.id} onClick={() => pick(s)} style={{ position: "relative", display: "flex", alignItems: "center", gap: 12, background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: "14px 16px", cursor: "pointer" }}>
              <div style={{ width: 42, height: 42, borderRadius: "50%", background: COLOR.rust, color: "white", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, flexShrink: 0 }}>{initialsOf(s.full_name)}</div>
              <div>
                <div style={{ fontWeight: 700, fontSize: 14.5 }}>{s.short_name}</div>
                <div style={{ fontSize: 12.5, color: COLOR.inkSoft }}>{s.role}</div>
              </div>
              {queued && (
                <div style={{ position: "absolute", top: 8, right: 10, fontSize: 10.5, fontWeight: 700, color: COLOR.amber, background: COLOR.amberSoft, padding: "2px 7px", borderRadius: 999 }}>
                  ⏳ syncing
                </div>
              )}
            </div>
          );
        })}
      </div>
      {staff === null && <div style={{ marginTop: 24, color: COLOR.inkSoft, fontSize: 14 }}>Loading staff…</div>}
      {staff !== null && filtered.length === 0 && (
        <div style={{ marginTop: 24, color: COLOR.inkSoft, fontSize: 14, textAlign: "center" }}>
          {loadError ? "Couldn't load staff. Refresh the page and try again."
            : list.length === 0 ? "No staff yet — add employees in the Employees tab."
            : "No staff match your search."}
        </div>
      )}
      {toast && <div style={{ position: "fixed", bottom: 24, right: 24, background: COLOR.espresso, color: COLOR.cream, padding: "12px 18px", borderRadius: 8, fontSize: 13 }}>{toast}</div>}
    </div>
  );
}
