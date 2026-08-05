import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { Button, Field, inputStyle } from "../../components/ui";

const EARNING_CODES = { BASIC: "Basic Salary", OVERTIME: "Overtime Pay", NIGHT_DIFF: "Night Differential", HOLIDAY_PREMIUM: "Holiday Premium", ALLOWANCE: "Allowances", BONUS: "Bonuses", INCENTIVE: "Incentives", COMMISSION: "Commissions", LEAVE_CONVERSION: "Leave Conversion" };
const EMPLOYMENT_TYPES = ["regular", "probationary", "fixed_term", "seasonal"];

const cardStyle = { background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 20 };
const cardTitle = { margin: "0 0 16px", fontSize: 16 };

export default function SettingsView() {
  const [settings, setSettings] = useState(null);
  const [saved, setSaved] = useState(false);
  const [users, setUsers] = useState([]);
  const [pw, setPw] = useState({}); // userId -> new password typed
  const [pwMsg, setPwMsg] = useState({}); // userId -> { ok, text }

  useEffect(() => {
    apiClient.get("/api/admin/settings").then((res) => setSettings(res.data));
    apiClient.get("/api/admin/users").then((res) => setUsers(res.data)).catch(() => setUsers([]));
  }, []);

  async function changePassword(user) {
    const value = (pw[user.id] || "").trim();
    if (value.length < 8) {
      setPwMsg((m) => ({ ...m, [user.id]: { ok: false, text: "At least 8 characters." } }));
      return;
    }
    try {
      await apiClient.put(`/api/admin/users/${user.id}/password`, { password: value });
      setPw((p) => ({ ...p, [user.id]: "" }));
      setPwMsg((m) => ({ ...m, [user.id]: { ok: true, text: "Updated ✓" } }));
      setTimeout(() => setPwMsg((m) => ({ ...m, [user.id]: null })), 2500);
    } catch {
      setPwMsg((m) => ({ ...m, [user.id]: { ok: false, text: "Couldn't update — try again." } }));
    }
  }

  function set(field, value) {
    setSettings((s) => ({ ...s, [field]: value }));
  }

  function toggleEarning(code) {
    if (code === "BASIC") return;
    const list = settings.included_earnings.includes(code)
      ? settings.included_earnings.filter((c) => c !== code)
      : [...settings.included_earnings, code];
    set("included_earnings", list);
  }

  function toggleType(type) {
    const list = settings.employment_types_included.includes(type)
      ? settings.employment_types_included.filter((t) => t !== type)
      : [...settings.employment_types_included, type];
    set("employment_types_included", list);
  }

  async function save() {
    await apiClient.put("/api/admin/settings", settings);
    setSaved(true);
    setTimeout(() => setSaved(false), 2000);
  }

  if (!settings) return <div>Loading...</div>;

  return (
    <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20 }}>
      <div style={cardStyle}>
        <h3 style={cardTitle}>Basic Pay Rate & Overtime</h3>
        <Field label="Daily Basic Rate (₱)">
          <input type="number" value={settings.daily_basic_rate} onChange={(e) => set("daily_basic_rate", e.target.value)} style={inputStyle} />
        </Field>
        <Field label="Overtime Multiplier">
          <input type="number" step="0.05" value={settings.overtime_multiplier} onChange={(e) => set("overtime_multiplier", e.target.value)} style={inputStyle} />
        </Field>
        <Field label="Night Differential Multiplier">
          <input type="number" step="0.01" value={settings.night_diff_multiplier} onChange={(e) => set("night_diff_multiplier", e.target.value)} style={inputStyle} />
        </Field>
        <Field label="Unpaid Break (hours) — deducted from worked hours">
          <input type="number" step="0.25" value={settings.unpaid_break_hours} onChange={(e) => set("unpaid_break_hours", e.target.value)} style={inputStyle} />
        </Field>
        <Field label="Late Penalty (₱) — flat deduction for any late clock-in">
          <input type="number" step="0.01" value={settings.late_penalty_amount} onChange={(e) => set("late_penalty_amount", e.target.value)} style={inputStyle} />
        </Field>
        <Button variant="gold" onClick={save}>{saved ? "Saved ✓" : "Save Settings"}</Button>
      </div>

      <div style={cardStyle}>
        <h3 style={cardTitle}>13th Month Period & Release</h3>
        <Field label="Release Date">
          <input type="date" value={settings.release_date} onChange={(e) => set("release_date", e.target.value)} style={inputStyle} />
        </Field>
        <Field label="Minimum Months of Service">
          <input type="number" value={settings.minimum_months} onChange={(e) => set("minimum_months", e.target.value)} style={inputStyle} />
        </Field>
      </div>

      <div style={cardStyle}>
        <h3 style={cardTitle}>Included Earnings (13th Month Base)</h3>
        {Object.entries(EARNING_CODES).map(([code, label]) => (
          <label key={code} style={{ display: "flex", gap: 10, padding: "7px 0", fontSize: 13 }}>
            <input type="checkbox" checked={settings.included_earnings.includes(code)} disabled={code === "BASIC"} onChange={() => toggleEarning(code)} />
            {label}
          </label>
        ))}
      </div>

      <div style={cardStyle}>
        <h3 style={cardTitle}>Employment Type Eligibility</h3>
        {EMPLOYMENT_TYPES.map((type) => (
          <label key={type} style={{ display: "flex", gap: 8, fontSize: 13, marginBottom: 8 }}>
            <input type="checkbox" checked={settings.employment_types_included.includes(type)} onChange={() => toggleType(type)} />
            {type.replace("_", " ")}
          </label>
        ))}
      </div>

      <div style={{ ...cardStyle, gridColumn: "1 / -1" }}>
        <h3 style={cardTitle}>Logins & Passwords</h3>
        <p style={{ margin: "-8px 0 14px", fontSize: 13, color: "#7A6A57" }}>
          Change the password for any login — the admin and every branch. Type a new password (min 8 characters) and press Update.
        </p>
        <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
          {users.length === 0 && <div style={{ fontSize: 13, color: "#7A6A57" }}>No logins found.</div>}
          {users.map((u) => (
            <div key={u.id} style={{ display: "flex", gap: 12, alignItems: "center", flexWrap: "wrap", borderTop: "1px solid #F0E7D4", paddingTop: 10 }}>
              <div style={{ minWidth: 220 }}>
                <div style={{ fontWeight: 600, fontSize: 13 }}>
                  {u.role === "admin" ? "Admin" : (u.branch || "Branch")}
                  <span style={{ marginLeft: 6, fontWeight: 400, fontSize: 11, color: u.role === "admin" ? "#9A6B12" : "#3F6B45" }}>
                    {u.role === "admin" ? "· full access" : "· branch only"}
                  </span>
                </div>
                <div style={{ fontSize: 12, color: "#7A6A57" }}>{u.email}</div>
              </div>
              <input
                type="text"
                value={pw[u.id] || ""}
                onChange={(e) => setPw((p) => ({ ...p, [u.id]: e.target.value }))}
                placeholder="New password"
                style={{ ...inputStyle, width: 220 }}
              />
              <Button variant="gold" onClick={() => changePassword(u)} disabled={!(pw[u.id] || "").trim()}>Update</Button>
              {pwMsg[u.id] && (
                <span style={{ fontSize: 12, color: pwMsg[u.id].ok ? "#3F6B45" : "#C1521F" }}>{pwMsg[u.id].text}</span>
              )}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
