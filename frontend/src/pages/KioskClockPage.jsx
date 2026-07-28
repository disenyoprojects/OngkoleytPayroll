import { useEffect, useState } from "react";
import { apiClient } from "../api/client";
import PinPad from "../components/PinPad";
import { COLOR, FONT_DISPLAY } from "../theme";

function initialsOf(name) {
  const parts = name.trim().split(/\s+/);
  return parts.length === 1 ? parts[0].slice(0, 2).toUpperCase() : (parts[0][0] + parts[1][0]).toUpperCase();
}

function branchOptionsOf(staff) {
  return Array.from(new Map(staff.filter((s) => s.branch).map((s) => [s.branch.id, s.branch.name])).entries())
    .map(([id, name]) => ({ id, name }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

export default function KioskClockPage() {
  const [staff, setStaff] = useState([]);
  const [search, setSearch] = useState("");
  const [branchFilter, setBranchFilter] = useState("");
  const [selected, setSelected] = useState(null);
  const [error, setError] = useState(false);
  const [toast, setToast] = useState(null);

  useEffect(() => {
    apiClient.get("/api/kiosk/staff").then((res) => setStaff(res.data));
  }, []);

  function showToast(msg) {
    setToast(msg);
    setTimeout(() => setToast(null), 2400);
  }

  async function submitPin(pin) {
    try {
      const { data } = await apiClient.post("/api/kiosk/verify-pin", { employee_id: selected.id, pin });
      const todayRes = await apiClient.get("/api/kiosk/today", { headers: { Authorization: `Bearer ${data.token}` } });
      const record = todayRes.data;
      const action = !record?.clock_in || record.clock_out ? "clock-in" : "clock-out";
      await apiClient.post(`/api/kiosk/${action}`, {}, { headers: { Authorization: `Bearer ${data.token}` } });
      showToast(`${selected.short_name} clocked ${action === "clock-in" ? "in" : "out"}.`);
      setSelected(null);
      setError(false);
    } catch {
      setError(true);
      setTimeout(() => setError(false), 700);
    }
  }

  const branches = branchOptionsOf(staff);
  const filtered = staff.filter((s) =>
    s.short_name.toLowerCase().includes(search.toLowerCase()) &&
    (!branchFilter || String(s.branch_id) === String(branchFilter))
  );

  if (selected) {
    return (
      <div style={{ minHeight: "80vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 20 }}>
          <div style={{ width: 48, height: 48, borderRadius: "50%", background: COLOR.rust, color: "white", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700 }}>{initialsOf(selected.full_name)}</div>
          <div>
            <div style={{ fontWeight: 700, fontSize: 17 }}>{selected.full_name}</div>
            <div style={{ fontSize: 13, color: COLOR.inkSoft }}>{selected.role}</div>
          </div>
        </div>
        <PinPad onSubmit={submitPin} onBack={() => setSelected(null)} error={error} />
      </div>
    );
  }

  return (
    <div style={{ minHeight: "80vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
      <div style={{ fontWeight: 700, fontSize: 19, marginBottom: 4, fontFamily: FONT_DISPLAY }}>Who are you?</div>
      <div style={{ fontSize: 13, color: COLOR.inkSoft, marginBottom: 20 }}>Tap your name to continue</div>
      <div style={{ display: "flex", gap: 12, width: "min(900px, 92vw)", marginBottom: 22, flexWrap: "wrap" }}>
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name..." style={{ flex: "1 1 260px", padding: "12px 16px", borderRadius: 10, border: `1px solid ${COLOR.line}`, fontSize: 14 }} />
        <select value={branchFilter} onChange={(e) => setBranchFilter(e.target.value)} style={{ padding: "12px 16px", borderRadius: 10, border: `1px solid ${COLOR.line}`, fontSize: 14, background: "white" }}>
          <option value="">All branches</option>
          {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, minmax(220px, 1fr))", gap: 16, width: "min(900px, 92vw)" }}>
        {filtered.map((s) => (
          <div key={s.id} onClick={() => setSelected(s)} style={{ display: "flex", alignItems: "center", gap: 12, background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: "14px 16px", cursor: "pointer" }}>
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
