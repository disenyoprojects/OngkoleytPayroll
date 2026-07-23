import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP } from "../../theme";
import { Pill } from "../../components/ui";

export default function AuditLogView() {
  const [log, setLog] = useState([]);

  useEffect(() => {
    apiClient.get("/api/admin/audit-log").then((res) => setLog(res.data));
  }, []);

  if (log.length === 0) {
    return <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 40, textAlign: "center", color: "#7A6A57" }}>No actions recorded yet.</div>;
  }

  return (
    <table style={{ width: "100%", borderCollapse: "collapse" }}>
      <thead><tr><th>Timestamp</th><th>Staff</th><th>Area</th><th>Action</th><th>Detail / Old → New</th><th>Reason</th></tr></thead>
      <tbody>
        {log.map((entry) => (
          <tr key={entry.id}>
            <td>{new Date(entry.created_at).toLocaleString()}</td>
            <td>{entry.employee ? entry.employee.short_name : "All eligible employees"}</td>
            <td><Pill>{entry.type === "attendance" ? "Attendance" : "13th Month"}</Pill></td>
            <td><Pill tone="neutral">{entry.action.replace("_", " ")}</Pill></td>
            <td>{entry.detail || (entry.old_amount != null ? `${formatPHP(entry.old_amount)} → ${formatPHP(entry.new_amount)}` : "—")}</td>
            <td>{entry.reason}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
