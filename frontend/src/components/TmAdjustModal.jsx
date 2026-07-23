import { useState } from "react";
import { apiClient } from "../api/client";
import { formatPHP } from "../theme";
import { Button, ModalShell, inputStyle } from "./ui";

export default function TmAdjustModal({ row, onCancel, onSaved }) {
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");

  async function confirm() {
    await apiClient.post(`/api/admin/thirteenth-month/${row.employee.id}/adjust`, { amount: Number(amount), reason });
    onSaved();
  }

  return (
    <ModalShell onClose={onCancel}>
      <h3>Manual Adjustment</h3>
      <p style={{ fontSize: 12, color: "#7A6A57" }}>{row.employee.short_name} · Current amount {formatPHP(row.adjusted_amount)}</p>
      <div style={{ marginBottom: 12 }}>
        <div style={{ fontSize: 12, marginBottom: 4 }}>Adjustment Amount (negative for deduction)</div>
        <input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} style={inputStyle} />
      </div>
      <div style={{ marginBottom: 12 }}>
        <div style={{ fontSize: 12, marginBottom: 4 }}>Reason</div>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </div>
      <div style={{ display: "flex", gap: 8 }}>
        <Button variant="gold" disabled={!amount || reason.length < 5} onClick={confirm}>Apply Adjustment</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </ModalShell>
  );
}
