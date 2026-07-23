import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { formatPHP } from "../../theme";
import { Button, Pill, StatCard } from "../../components/ui";
import TmAdjustModal from "../../components/TmAdjustModal";
import TmUnlockModal from "../../components/TmUnlockModal";

export default function ThirteenthMonthView() {
  const [records, setRecords] = useState([]);
  const [adjustRow, setAdjustRow] = useState(null);
  const [unlockRow, setUnlockRow] = useState(null);

  function load() {
    apiClient.get("/api/admin/thirteenth-month").then((res) => setRecords(res.data.records));
  }
  useEffect(load, []);

  async function act(employeeId, action) {
    await apiClient.post(`/api/admin/thirteenth-month/${employeeId}/${action}`);
    load();
  }

  function downloadPayslip(employeeId) {
    window.open(`${apiClient.defaults.baseURL}/api/admin/thirteenth-month/${employeeId}/payslip`, "_blank");
  }

  const pending = records.filter((r) => r.status === "pending").length;
  const totalLiability = records.reduce((s, r) => s + r.adjusted_amount, 0);

  return (
    <div>
      <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 18 }}>
        <h1>13th Month Pay</h1>
        <Button variant="gold" disabled={pending === 0} onClick={() => apiClient.post("/api/admin/thirteenth-month/compute-all").then(load)}>Compute All Pending ({pending})</Button>
      </div>
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard label="Eligible Employees" value={records.length} />
        <StatCard label="Total Liability" value={formatPHP(totalLiability)} />
        <StatCard label="Pending" value={pending} />
      </div>
      <table style={{ width: "100%", borderCollapse: "collapse" }}>
        <thead><tr><th>Employee</th><th>Role</th><th>Branch</th><th>13th Month Amount</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          {records.map((r) => (
            <tr key={r.employee.id}>
              <td>{r.employee.short_name}</td>
              <td>{r.employee.role}</td>
              <td>{r.employee.branch.name}</td>
              <td>{formatPHP(r.adjusted_amount)}</td>
              <td><Pill tone={r.status}>{r.status}</Pill></td>
              <td>
                {r.status === "pending" && <Button small variant="gold" onClick={() => act(r.employee.id, "compute")}>Compute</Button>}
                {r.status !== "pending" && r.status !== "locked" && <Button small variant="outline" onClick={() => act(r.employee.id, "recompute")}>Recompute</Button>}
                {r.status !== "pending" && r.status !== "locked" && <Button small variant="outline" onClick={() => setAdjustRow(r)}>Adjust</Button>}
                {r.status === "computed" && <Button small variant="primary" onClick={() => act(r.employee.id, "release")}>Release</Button>}
                {(r.status === "computed" || r.status === "released") && <Button small variant="danger" onClick={() => act(r.employee.id, "lock")}>Lock</Button>}
                {r.status === "locked" && <Button small variant="outline" onClick={() => setUnlockRow(r)}>Unlock</Button>}
                {r.status !== "pending" && <Button small variant="ghost" onClick={() => downloadPayslip(r.employee.id)}>Payslip</Button>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {adjustRow && <TmAdjustModal row={adjustRow} onCancel={() => setAdjustRow(null)} onSaved={() => { setAdjustRow(null); load(); }} />}
      {unlockRow && <TmUnlockModal row={unlockRow} onCancel={() => setUnlockRow(null)} onSaved={() => { setUnlockRow(null); load(); }} />}
    </div>
  );
}
