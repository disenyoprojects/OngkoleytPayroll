import { useEffect, useState } from "react";
import { apiClient } from "../api/client";
import { formatPHP, formatPHDateTime } from "../theme";
import { Button, ModalShell, Pill } from "./ui";

export default function TmHistoryModal({ row, onCancel }) {
  const [entries, setEntries] = useState(null);

  useEffect(() => {
    apiClient.get(`/api/admin/audit-log?type=13th_month&employee_id=${row.employee.id}`)
      .then((res) => setEntries(res.data));
  }, [row.employee.id]);

  return (
    <ModalShell onClose={onCancel} width={560}>
      <h3 style={{ margin: "0 0 2px" }}>Adjustment History</h3>
      <div style={{ fontSize: 12.5, color: "#7A6A57", marginBottom: 16 }}>{row.employee.short_name}</div>
      {!entries ? (
        <div>Loading...</div>
      ) : entries.length === 0 ? (
        <div style={{ color: "#7A6A57", fontSize: 13 }}>No 13th month actions recorded yet for this employee.</div>
      ) : (
        <div style={{ display: "flex", flexDirection: "column", gap: 12, maxHeight: "55vh", overflow: "auto" }}>
          {entries.map((entry) => (
            <div key={entry.id} style={{ borderBottom: "1px solid #E7DCC6", paddingBottom: 10 }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 4 }}>
                <Pill tone="neutral">{entry.action.replace(/_/g, " ")}</Pill>
                <span style={{ fontSize: 11.5, color: "#7A6A57" }}>{formatPHDateTime(entry.created_at)}</span>
              </div>
              {entry.old_amount != null && (
                <div style={{ fontSize: 13, fontWeight: 600 }}>{formatPHP(entry.old_amount)} → {formatPHP(entry.new_amount)}</div>
              )}
              {entry.detail && <div style={{ fontSize: 13 }}>{entry.detail}</div>}
              {entry.reason && <div style={{ fontSize: 12.5, color: "#7A6A57" }}>{entry.reason}</div>}
              {entry.performed_by && <div style={{ fontSize: 11.5, color: "#7A6A57", marginTop: 2 }}>by {entry.performed_by.name}</div>}
            </div>
          ))}
        </div>
      )}
      <div style={{ marginTop: 18 }}>
        <Button variant="ghost" onClick={onCancel}>Close</Button>
      </div>
    </ModalShell>
  );
}
