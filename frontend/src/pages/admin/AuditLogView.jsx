import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP } from "../../theme";
import { Pill, tableWrap, tableStyle, thStyle, tdStyle } from "../../components/ui";

export default function AuditLogView() {
  const [log, setLog] = useState([]);

  useEffect(() => {
    apiClient.get("/api/admin/audit-log").then((res) => setLog(res.data));
  }, []);

  if (log.length === 0) {
    return <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 40, textAlign: "center", color: "#7A6A57" }}>No actions recorded yet.</div>;
  }

  const areaLabel = (type) => (type === "attendance" ? "Attendance" : type === "employee" ? "Employee" : "13th Month");

  return (
    <div style={tableWrap}>
      <table style={tableStyle}>
        <thead>
          <tr>
            <th style={thStyle}>Timestamp</th>
            <th style={thStyle}>Staff</th>
            <th style={thStyle}>Area</th>
            <th style={thStyle}>Action</th>
            <th style={thStyle}>Detail / Old → New</th>
            <th style={thStyle}>Reason</th>
          </tr>
        </thead>
        <tbody>
          {log.map((entry) => (
            <tr key={entry.id}>
              <td style={{ ...tdStyle, whiteSpace: "nowrap", color: "#7A6A57" }}>{new Date(entry.created_at).toLocaleString()}</td>
              <td style={{ ...tdStyle, fontWeight: 600 }}>{entry.employee ? entry.employee.short_name : "All eligible employees"}</td>
              <td style={tdStyle}><Pill>{areaLabel(entry.type)}</Pill></td>
              <td style={tdStyle}><Pill tone="neutral">{entry.action.replace(/_/g, " ")}</Pill></td>
              <td style={tdStyle}>{entry.detail || (entry.old_amount != null ? `${formatPHP(entry.old_amount)} → ${formatPHP(entry.new_amount)}` : "—")}</td>
              <td style={{ ...tdStyle, color: "#7A6A57" }}>{entry.reason || "—"}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
