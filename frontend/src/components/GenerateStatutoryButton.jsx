import { useState } from "react";
import { apiClient } from "../api/client";
import { Button } from "./ui";

const sum = (bucket) => Object.values(bucket ?? {}).reduce((t, n) => t + (n ?? 0), 0);

/**
 * Runs the deductions generator for a month + period — SSS, Pag-IBIG,
 * PhilHealth and the late penalty — and reports what it did underneath itself.
 * Re-running corrects amounts it wrote earlier, so this is also the button to
 * press after a rate or rule change, or after correcting attendance.
 */
export default function GenerateStatutoryButton({ month, period, onGenerated }) {
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState(null);

  async function run() {
    setBusy(true);
    setNotice(null);
    try {
      const res = await apiClient.post(`/api/admin/payroll/period/statutory?month=${month}&period=${period}`);
      const { generated, updated, skipped } = res.data;
      const parts = [];
      if (sum(generated)) parts.push(`added ${sum(generated)}`);
      if (sum(updated)) parts.push(`corrected ${sum(updated)}`);
      if (sum(skipped)) parts.push(`${sum(skipped)} already up to date`);
      setNotice(parts.length ? `Statutory deductions: ${parts.join(", ")}.` : "Nothing to generate for this period.");
      await onGenerated?.();
    } catch (e) {
      setNotice(e?.response?.data?.message || "Couldn't generate the statutory deductions.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <span style={{ display: "inline-flex", flexDirection: "column", gap: 5 }}>
      <Button variant="outline" disabled={busy} onClick={run}>
        {busy ? "Generating…" : "Generate Deductions"}
      </Button>
      {notice && <span style={{ fontSize: 12, color: "#7A6A57" }}>{notice}</span>}
    </span>
  );
}
