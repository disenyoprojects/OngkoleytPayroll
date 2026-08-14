import { useEffect, useState } from "react";
import { apiClient, clearAdminToken, getAdminToken, getCachedMe, setCachedMe } from "../../api/client";
import { tabBtnStyle } from "../../components/ui";
import { useOnlineStatus } from "../../offline/useOnlineStatus";
import { onQueueChange, queueLength } from "../../offline/clockQueue";
import { syncClockQueue } from "../../offline/syncClockQueue";
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
  const [offlineSession, setOfflineSession] = useState(false);
  const [tab, setTab] = useState("clock");
  const online = useOnlineStatus();
  const [pendingSync, setPendingSync] = useState(0);
  const [syncNotice, setSyncNotice] = useState(null);

  // Keep the pending-sync count live as actions are queued/synced from anywhere.
  useEffect(() => {
    setPendingSync(queueLength());
    return onQueueChange(() => setPendingSync(queueLength()));
  }, []);

  // Try to sync on load, whenever the browser reports we're back online, and
  // on a periodic fallback (the 'online' event isn't always reliable — e.g.
  // a wifi network with no real internet still fires it).
  useEffect(() => {
    function trySync() {
      syncClockQueue().then(({ synced, dropped }) => {
        if (synced.length || dropped.length) {
          const parts = [];
          if (synced.length) parts.push(`${synced.length} clock action${synced.length === 1 ? "" : "s"} synced`);
          if (dropped.length) parts.push(`${dropped.length} couldn't be saved — check Attendance`);
          setSyncNotice(parts.join(", "));
          setTimeout(() => setSyncNotice(null), 5000);
        }
      });
    }
    trySync();
    window.addEventListener("online", trySync);
    const interval = setInterval(trySync, 20000);
    return () => {
      window.removeEventListener("online", trySync);
      clearInterval(interval);
    };
  }, []);

  useEffect(() => {
    if (!getAdminToken()) {
      setAdmin(null);
      return;
    }
    apiClient.get("/api/admin/me").then((res) => {
      setCachedMe(res.data);
      setOfflineSession(false);
      setAdmin(res.data);
    }).catch((err) => {
      // A real auth failure (bad/expired token) — the session is genuinely
      // invalid, so sign out. A network error (offline, DNS, timeout — no
      // response reached us) does NOT mean the token is bad: fall back to
      // the last-known session so the app (and offline Clock In/Out) still
      // works without a connection.
      if (err?.response && [401, 403].includes(err.response.status)) {
        clearAdminToken();
        setAdmin(null);
        return;
      }
      const cached = getCachedMe();
      if (cached) {
        setOfflineSession(true);
        setAdmin(cached);
      } else {
        // Never successfully signed in on this device — nothing to fall back to.
        setAdmin(null);
      }
    });
  }, []);

  if (admin === undefined) return <div style={{ padding: 32 }}>Loading...</div>;
  if (admin === null) return (
    <AdminLoginPage onLoggedIn={() => apiClient.get("/api/admin/me").then((res) => {
      setCachedMe(res.data);
      setOfflineSession(false);
      setAdmin(res.data);
    })} />
  );

  const isAdmin = admin.role !== "branch";
  const tabs = TABS.filter(([, , adminOnly]) => isAdmin || !adminOnly);
  const activeTab = tabs.some(([k]) => k === tab) ? tab : "clock";

  function logout() {
    apiClient.post("/api/admin/logout").finally(() => {
      clearAdminToken();
      setAdmin(null);
    });
  }

  const showOfflineBar = !online || offlineSession || pendingSync > 0 || syncNotice;

  return (
    <div style={{ maxWidth: 1180, margin: "0 auto", padding: "28px 32px" }}>
      {showOfflineBar && (
        <div style={{
          display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap",
          background: online ? "#EFEADA" : "#3a2c1f", color: online ? "#5A4A32" : "#F3EAD3",
          borderRadius: 8, padding: "8px 14px", marginBottom: 14, fontSize: 12.5,
        }}>
          {!online && <span>📴 Offline — Clock In/Out still works and will sync automatically.</span>}
          {online && offlineSession && <span>Reconnected — refresh the page to load your latest session.</span>}
          {pendingSync > 0 && <span>⏳ {pendingSync} clock action{pendingSync === 1 ? "" : "s"} waiting to sync.</span>}
          {syncNotice && <span>✓ {syncNotice}</span>}
        </div>
      )}
      <div style={{ display: "flex", gap: 8, marginBottom: 20, flexWrap: "wrap", alignItems: "center" }}>
        {tabs.map(([key, label]) => (
          <button key={key} onClick={() => setTab(key)} style={tabBtnStyle(activeTab === key)}>{label}</button>
        ))}
        <span style={{ marginLeft: "auto", display: "flex", gap: 10, alignItems: "center" }}>
          {!isAdmin && (admin.branches?.length || admin.branch) && (
            <span style={{ fontSize: 12.5, color: "#7A6A57" }}>
              {admin.branches?.length > 1
                ? `${admin.branches.join(" · ")} branches`
                : `${admin.branches?.[0] ?? admin.branch} branch`}
            </span>
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
      {activeTab === "employees" && <EmployeesView isAdmin={isAdmin} myBranchId={admin.branch_id} />}
    </div>
  );
}
