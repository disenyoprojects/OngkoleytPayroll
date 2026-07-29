import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { COLOR, FONT_DISPLAY, formatTime12 } from "../../theme";
import { Button } from "../../components/ui";

function initialsOf(name) {
  const parts = name.trim().split(/\s+/);
  return parts.length === 1 ? parts[0].slice(0, 2).toUpperCase() : (parts[0][0] + parts[1][0]).toUpperCase();
}

function branchOptionsOf(staff) {
  return Array.from(new Map(staff.filter((s) => s.branch).map((s) => [s.branch.id, s.branch.name])).entries())
    .map(([id, name]) => ({ id, name }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

export default function ClockView() {
  const [staff, setStaff] = useState([]);
  const [search, setSearch] = useState("");
  const [branchFilter, setBranchFilter] = useState("");
  const [selected, setSelected] = useState(null); // { employee, record }
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);

  useEffect(() => {
    apiClient.get("/api/admin/clock/staff").then((res) => setStaff(res.data));
  }, []);

  function showToast(msg) {
    setToast(msg);
    setTimeout(() => setToast(null), 2400);
  }

  async function pick(employee) {
    const { data } = await apiClient.get(`/api/admin/clock/status?employee_id=${employee.id}`);
    setSelected({ employee, record: data });
  }

  async function doClock(action) {
    if (busy) return;
    setBusy(true);
    try {
      await apiClient.post(`/api/admin/clock/${action}`, { employee_id: selected.employee.id });
      showToast(`${selected.employee.short_name} clocked ${action === "in" ? "in" : "out"}.`);
      setSelected(null);
    } catch (e) {
      const msg = e?.response?.data?.errors ? Object.values(e.response.data.errors)[0][0] : "Something went wrong.";
      showToast(msg);
    } finally {
      setBusy(false);
    }
  }

  const branches = branchOptionsOf(staff);
  const filtered = staff.filter((s) =>
    s.short_name.toLowerCase().includes(search.toLowerCase()) &&
    (!branchFilter || String(s.branch_id) === String(branchFilter))
  );

  if (selected) {
    const { employee, record } = selected;
    const clockedIn = record && record.clock_in && !record.clock_out;
    const done = record && record.clock_out;
    return (
      <div style={{ minHeight: "60vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 24 }}>
          <div style={{ width: 56, height: 56, borderRadius: "50%", background: COLOR.rust, color: "white", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, fontSize: 20 }}>{initialsOf(employee.full_name)}</div>
          <div>
            <div style={{ fontWeight: 700, fontSize: 19 }}>{employee.full_name}</div>
            <div style={{ fontSize: 13, color: COLOR.inkSoft }}>{employee.role}</div>
          </div>
        </div>

        <div style={{ fontSize: 14, color: COLOR.inkSoft, marginBottom: 20, textAlign: "center" }}>
          {done ? `Clocked in ${formatTime12(record.clock_in)}, out ${formatTime12(record.clock_out)} — done for today.`
            : clockedIn ? `Clocked in at ${formatTime12(record.clock_in)}.`
            : "Not clocked in yet today."}
        </div>

        <div style={{ display: "flex", gap: 12 }}>
          {!done && (clockedIn
            ? <Button variant="gold" onClick={() => doClock("out")} disabled={busy}>Clock Out</Button>
            : <Button variant="gold" onClick={() => doClock("in")} disabled={busy}>Clock In</Button>)}
          <Button variant="outline" onClick={() => setSelected(null)} disabled={busy}>Back</Button>
        </div>
        {toast && <div style={{ position: "fixed", bottom: 24, right: 24, background: COLOR.espresso, color: COLOR.cream, padding: "12px 18px", borderRadius: 8, fontSize: 13 }}>{toast}</div>}
      </div>
    );
  }

  return (
    <div style={{ display: "flex", flexDirection: "column", alignItems: "center", padding: "12px 0 48px" }}>
      <div style={{ fontWeight: 700, fontSize: 22, marginBottom: 4, fontFamily: FONT_DISPLAY }}>Clock In / Out</div>
      <div style={{ fontSize: 13, color: COLOR.inkSoft, marginBottom: 20 }}>Tap a name to clock them in or out</div>
      <div style={{ display: "flex", gap: 12, width: "min(900px, 92vw)", marginBottom: 22, flexWrap: "wrap" }}>
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name..." style={{ flex: "1 1 260px", padding: "12px 16px", borderRadius: 10, border: `1px solid ${COLOR.line}`, fontSize: 14 }} />
        <select value={branchFilter} onChange={(e) => setBranchFilter(e.target.value)} style={{ padding: "12px 16px", borderRadius: 10, border: `1px solid ${COLOR.line}`, fontSize: 14, background: "white" }}>
          <option value="">All branches</option>
          {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, minmax(220px, 1fr))", gap: 16, width: "min(900px, 92vw)" }}>
        {filtered.map((s) => (
          <div key={s.id} onClick={() => pick(s)} style={{ display: "flex", alignItems: "center", gap: 12, background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: "14px 16px", cursor: "pointer" }}>
            <div style={{ width: 42, height: 42, borderRadius: "50%", background: COLOR.rust, color: "white", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, flexShrink: 0 }}>{initialsOf(s.full_name)}</div>
            <div>
              <div style={{ fontWeight: 700, fontSize: 14.5 }}>{s.short_name}</div>
              <div style={{ fontSize: 12.5, color: COLOR.inkSoft }}>{s.role}</div>
            </div>
          </div>
        ))}
      </div>
      {toast && <div style={{ position: "fixed", bottom: 24, right: 24, background: COLOR.espresso, color: COLOR.cream, padding: "12px 18px", borderRadius: 8, fontSize: 13 }}>{toast}</div>}
    </div>
  );
}
