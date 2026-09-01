import { useState } from "react";
import { apiClient } from "../api/client";
import { formatPHP } from "../theme";
import { Button } from "./ui";

/**
 * Charges the flat late penalty for every late day in the period, one row per
 * late day. The office still decides who actually pays: a generated row can be
 * set to 0 to excuse it, and re-running never touches a day that already has a
 * row, so pressing this again does not undo those decisions.
 */
export default function GeneratePenaltyLatesButton({ month, period, onGenerated }) {
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState(null);

  async function run() {
    setBusy(true);
    setNotice(null);
    try {
      const res = await apiClient.post(`/api/admin/payroll/period/penalty-lates?month=${month}&period=${period}`);
      const { generated, skipped, amount } = res.data;
      const parts = [];
      if (generated) parts.push(`charged ${generated} late day${generated === 1 ? "" : "s"} at ${formatPHP(amount)}`);
      if (skipped) parts.push(`${skipped} already decided`);
      setNotice(parts.length ? `Late penalties: ${parts.join(", ")}.` : "No late days to charge for this period.");
      await onGenerated?.();
    } catch (e) {
      setNotice(e?.response?.data?.message || "Couldn't generate the late penalties.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <span style={{ display: "inline-flex", flexDirection: "column", gap: 5 }}>
      <Button variant="outline" disabled={busy} onClick={run}>
        {busy ? "Generating…" : "Generate Penalty Lates"}
      </Button>
      {notice && <span style={{ fontSize: 12, color: "#7A6A57" }}>{notice}</span>}
    </span>
  );
}
