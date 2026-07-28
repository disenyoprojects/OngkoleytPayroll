import { useEffect, useState } from "react";
import { apiClient } from "../api/client";
import PinPad from "../components/PinPad";
import StaffDashboardPage from "./StaffDashboardPage";
import { COLOR, FONT_DISPLAY } from "../theme";

function branchOptionsOf(staff) {
  return Array.from(new Map(staff.filter((s) => s.branch).map((s) => [s.branch.id, s.branch.name])).entries())
    .map(([id, name]) => ({ id, name }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

export default function StaffLoginPage() {
  const [staff, setStaff] = useState([]);
  const [search, setSearch] = useState("");
  const [branchFilter, setBranchFilter] = useState("");
  const [selected, setSelected] = useState(null);
  const [token, setToken] = useState(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    apiClient.get("/api/kiosk/staff").then((res) => setStaff(res.data));
  }, []);

  async function submitPin(pin) {
    try {
      const { data } = await apiClient.post("/api/kiosk/verify-pin", { employee_id: selected.id, pin });
      setToken(data.token);
      setError(false);
    } catch {
      setError(true);
      setTimeout(() => setError(false), 700);
    }
  }

  if (token && selected) {
    return <StaffDashboardPage staff={selected} token={token} onLogout={() => { setToken(null); setSelected(null); }} />;
  }

  if (selected) {
    return (
      <div style={{ minHeight: "80vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
        <div style={{ fontWeight: 700, fontSize: 17, marginBottom: 20 }}>{selected.full_name}</div>
        <PinPad onSubmit={submitPin} onBack={() => setSelected(null)} error={error} />
      </div>
    );
  }

  const branches = branchOptionsOf(staff);
  const filtered = staff.filter((s) =>
    s.short_name.toLowerCase().includes(search.toLowerCase()) &&
    (!branchFilter || String(s.branch_id) === String(branchFilter))
  );
  return (
    <div style={{ minHeight: "80vh", display: "flex", flexDirection: "column", alignItems: "center", padding: "48px 24px" }}>
      <div style={{ fontWeight: 700, fontSize: 19, marginBottom: 4, fontFamily: FONT_DISPLAY }}>Staff Timesheet Login</div>
      <div style={{ fontSize: 13, color: COLOR.inkSoft, marginBottom: 20 }}>Tap your name to view your timesheet</div>
      <div style={{ display: "flex", gap: 12, width: "min(900px, 92vw)", marginBottom: 22, flexWrap: "wrap" }}>
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name..." style={{ flex: "1 1 260px", padding: "12px 16px", borderRadius: 10, border: `1px solid ${COLOR.line}`, fontSize: 14 }} />
        <select value={branchFilter} onChange={(e) => setBranchFilter(e.target.value)} style={{ padding: "12px 16px", borderRadius: 10, border: `1px solid ${COLOR.line}`, fontSize: 14, background: "white" }}>
          <option value="">All branches</option>
          {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, minmax(220px, 1fr))", gap: 16, width: "min(900px, 92vw)" }}>
        {filtered.map((s) => (
          <div key={s.id} onClick={() => setSelected(s)} style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: "14px 16px", cursor: "pointer" }}>
            <div style={{ fontWeight: 700, fontSize: 14.5 }}>{s.short_name}</div>
            <div style={{ fontSize: 12.5, color: COLOR.inkSoft }}>{s.role}</div>
          </div>
        ))}
      </div>
    </div>
  );
}
