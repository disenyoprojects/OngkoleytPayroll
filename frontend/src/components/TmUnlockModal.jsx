import { useState } from "react";
import { apiClient } from "../api/client";
import { Button, ModalShell, textareaStyle } from "./ui";

export default function TmUnlockModal({ row, onCancel, onSaved }) {
  const [reason, setReason] = useState("");

  async function confirm() {
    await apiClient.post(`/api/admin/thirteenth-month/${row.employee.id}/unlock`, { reason });
    onSaved();
  }

  return (
    <ModalShell onClose={onCancel}>
      <h3>Unlock Record</h3>
      <p style={{ fontSize: 12, color: "#7A6A57" }}>{row.employee.short_name} — unlocking a released record requires an approval reason.</p>
      <div style={{ marginBottom: 12 }}>
        <div style={{ fontSize: 12, marginBottom: 4 }}>Reason for unlock</div>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={textareaStyle} />
      </div>
      <div style={{ display: "flex", gap: 8 }}>
        <Button variant="danger" disabled={reason.length < 5} onClick={confirm}>Confirm Unlock</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </ModalShell>
  );
}
