import { useEffect, useState } from "react";
import { apiClient, clearAdminToken, getAdminToken } from "../../api/client";
import { tabBtnStyle } from "../../components/ui";
import AdminLoginPage from "./AdminLoginPage";
import ClockView from "./ClockView";
import AttendanceView from "./AttendanceView";
import PayrollView from "./PayrollView";
import ThirteenthMonthView from "./ThirteenthMonthView";
import SettingsView from "./SettingsView";
import AuditLogView from "./AuditLogView";
import EmployeesView from "./EmployeesView";

// adminOnly tabs are hidden from branch logins.
const TABS = [
  ["clock", "Clock In/Out"],
  ["attendance", "Attendance"],
  ["payroll", "Payroll"],
  ["thirteenth-month", "13th Month", true],
  ["settings", "Settings", true],
  ["audit", "Audit Log", true],
  ["employees", "Employees"],
];

export default function AdminApp() {
  const [admin, setAdmin] = useState(undefined); // undefined = loading, null = logged out
  const [tab, setTab] = useState("clock");

  useEffect(() => {
    if (!getAdminToken()) {
      setAdmin(null);
      return;
    }
    apiClient.get("/api/admin/me").then((res) => setAdmin(res.data)).catch(() => {
      clearAdminToken();
      setAdmin(null);
    });
  }, []);

  if (admin === undefined) return <div style={{ padding: 32 }}>Loading...</div>;
  if (admin === null) return <AdminLoginPage onLoggedIn={() => apiClient.get("/api/admin/me").then((res) => setAdmin(res.data))} />;

  const isAdmin = admin.role !== "branch";
  const tabs = TABS.filter(([, , adminOnly]) => isAdmin || !adminOnly);
  const activeTab = tabs.some(([k]) => k === tab) ? tab : "clock";

  function logout() {
    apiClient.post("/api/admin/logout").finally(() => {
      clearAdminToken();
      setAdmin(null);
    });
  }

  return (
    <div style={{ maxWidth: 1180, margin: "0 auto", padding: "28px 32px" }}>
      <div style={{ display: "flex", gap: 8, marginBottom: 20, flexWrap: "wrap", alignItems: "center" }}>
        {tabs.map(([key, label]) => (
          <button key={key} onClick={() => setTab(key)} style={tabBtnStyle(activeTab === key)}>{label}</button>
        ))}
        <span style={{ marginLeft: "auto", display: "flex", gap: 10, alignItems: "center" }}>
          {!isAdmin && admin.branch && (
            <span style={{ fontSize: 12.5, color: "#7A6A57" }}>{admin.branch} branch</span>
          )}
          <button onClick={logout} style={tabBtnStyle(false)}>Log Out</button>
        </span>
      </div>
      {activeTab === "clock" && <ClockView />}
      {activeTab === "attendance" && <AttendanceView />}
      {activeTab === "payroll" && <PayrollView isAdmin={isAdmin} />}
      {activeTab === "thirteenth-month" && <ThirteenthMonthView />}
      {activeTab === "settings" && <SettingsView />}
      {activeTab === "audit" && <AuditLogView />}
      {activeTab === "employees" && <EmployeesView canEdit={isAdmin} />}
    </div>
  );
}
