import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { Button, inputStyle } from "../../components/ui";

const EARNING_CODES = { BASIC: "Basic Salary", OVERTIME: "Overtime Pay", NIGHT_DIFF: "Night Differential", HOLIDAY_PREMIUM: "Holiday Premium", ALLOWANCE: "Allowances", BONUS: "Bonuses", INCENTIVE: "Incentives", COMMISSION: "Commissions", LEAVE_CONVERSION: "Leave Conversion" };
const EMPLOYMENT_TYPES = ["regular", "probationary", "fixed_term", "seasonal"];

export default function SettingsView() {
  const [settings, setSettings] = useState(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    apiClient.get("/api/admin/settings").then((res) => setSettings(res.data));
  }, []);

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
      <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 20 }}>
        <h3>Basic Pay Rate & Overtime</h3>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Daily Basic Rate (₱)</div>
          <input type="number" value={settings.daily_basic_rate} onChange={(e) => set("daily_basic_rate", e.target.value)} style={inputStyle} />
        </div>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Overtime Multiplier</div>
          <input type="number" step="0.05" value={settings.overtime_multiplier} onChange={(e) => set("overtime_multiplier", e.target.value)} style={inputStyle} />
        </div>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Night Differential Multiplier</div>
          <input type="number" step="0.01" value={settings.night_diff_multiplier} onChange={(e) => set("night_diff_multiplier", e.target.value)} style={inputStyle} />
        </div>
        <Button variant="gold" onClick={save}>{saved ? "Saved ✓" : "Save Settings"}</Button>
      </div>

      <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 20 }}>
        <h3>13th Month Period & Release</h3>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Release Date</div>
          <input type="date" value={settings.release_date} onChange={(e) => set("release_date", e.target.value)} style={inputStyle} />
        </div>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, marginBottom: 4 }}>Minimum Months of Service</div>
          <input type="number" value={settings.minimum_months} onChange={(e) => set("minimum_months", e.target.value)} style={inputStyle} />
        </div>
      </div>

      <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 20 }}>
        <h3>Included Earnings (13th Month Base)</h3>
        {Object.entries(EARNING_CODES).map(([code, label]) => (
          <label key={code} style={{ display: "flex", gap: 10, padding: "7px 0", fontSize: 13 }}>
            <input type="checkbox" checked={settings.included_earnings.includes(code)} disabled={code === "BASIC"} onChange={() => toggleEarning(code)} />
            {label}
          </label>
        ))}
      </div>

      <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 20 }}>
        <h3>Employment Type Eligibility</h3>
        {EMPLOYMENT_TYPES.map((type) => (
          <label key={type} style={{ display: "flex", gap: 8, fontSize: 13, marginBottom: 8 }}>
            <input type="checkbox" checked={settings.employment_types_included.includes(type)} onChange={() => toggleType(type)} />
            {type.replace("_", " ")}
          </label>
        ))}
      </div>
    </div>
  );
}
