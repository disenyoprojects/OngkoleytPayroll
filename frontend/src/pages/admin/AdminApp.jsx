import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { tabBtnStyle } from "../../components/ui";
import AdminLoginPage from "./AdminLoginPage";
import AttendanceView from "./AttendanceView";
import PayrollView from "./PayrollView";
import ThirteenthMonthView from "./ThirteenthMonthView";
import SettingsView from "./SettingsView";
import AuditLogView from "./AuditLogView";

const TABS = [
  ["attendance", "Attendance"],
  ["payroll", "Payroll"],
  ["thirteenth-month", "13th Month"],
  ["settings", "Settings"],
  ["audit", "Audit Log"],
];

export default function AdminApp() {
  const [admin, setAdmin] = useState(undefined); // undefined = loading, null = logged out
  const [tab, setTab] = useState("attendance");

  useEffect(() => {
    apiClient.get("/api/admin/me").then((res) => setAdmin(res.data)).catch(() => setAdmin(null));
  }, []);

  if (admin === undefined) return <div style={{ padding: 32 }}>Loading...</div>;
  if (admin === null) return <AdminLoginPage onLoggedIn={() => apiClient.get("/api/admin/me").then((res) => setAdmin(res.data))} />;

  return (
    <div style={{ maxWidth: 1180, margin: "0 auto", padding: "28px 32px" }}>
      <div style={{ display: "flex", gap: 8, marginBottom: 20, flexWrap: "wrap" }}>
        {TABS.map(([key, label]) => (
          <button key={key} onClick={() => setTab(key)} style={tabBtnStyle(tab === key)}>{label}</button>
        ))}
      </div>
      {tab === "attendance" && <AttendanceView />}
      {tab === "payroll" && <PayrollView />}
      {tab === "thirteenth-month" && <ThirteenthMonthView />}
      {tab === "settings" && <SettingsView />}
      {tab === "audit" && <AuditLogView />}
    </div>
  );
}
