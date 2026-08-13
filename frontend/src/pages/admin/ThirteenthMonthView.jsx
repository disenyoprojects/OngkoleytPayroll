import { useEffect, useState } from "react";
import { apiClient, openAuthedPdf } from "../../api/client";
import { formatPHP, FONT_DISPLAY } from "../../theme";
import { Button, Pill, StatCard, tableWrap, tableStyle, thStyle, tdStyle } from "../../components/ui";
import TmAdjustModal from "../../components/TmAdjustModal";
import TmUnlockModal from "../../components/TmUnlockModal";
import TmHistoryModal from "../../components/TmHistoryModal";

export default function ThirteenthMonthView() {
  const [records, setRecords] = useState([]);
  const [adjustRow, setAdjustRow] = useState(null);
  const [unlockRow, setUnlockRow] = useState(null);
  const [historyRow, setHistoryRow] = useState(null);

  function load() {
    apiClient.get("/api/admin/thirteenth-month").then((res) => setRecords(res.data.records));
  }
  useEffect(load, []);

  async function act(employeeId, action) {
    await apiClient.post(`/api/admin/thirteenth-month/${employeeId}/${action}`);
    load();
  }

  function downloadPayslip(employeeId) {
    openAuthedPdf(`/api/admin/thirteenth-month/${employeeId}/payslip`);
  }

  const pending = records.filter((r) => r.status === "pending").length;
  const totalLiability = records.reduce((s, r) => s + r.adjusted_amount, 0);

  return (
    <div>
      <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 18 }}>
        <h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 26, margin: 0 }}>13th Month Pay</h1>
        <Button variant="gold" disabled={pending === 0} onClick={() => apiClient.post("/api/admin/thirteenth-month/compute-all").then(load)}>Compute All Pending ({pending})</Button>
      </div>
      <div style={{ display: "flex", gap: 16, marginBottom: 22, flexWrap: "wrap" }}>
        <StatCard label="Eligible Employees" value={records.length} />
        <StatCard label="Total Liability" value={formatPHP(totalLiability)} />
        <StatCard label="Pending" value={pending} />
      </div>
      <div style={tableWrap}>
        <table style={tableStyle}>
          <thead>
            <tr>
              <th style={thStyle}>Employee</th>
              <th style={thStyle}>Role</th>
              <th style={thStyle}>Branch</th>
              <th style={{ ...thStyle, textAlign: "right" }}>13th Month Amount</th>
              <th style={thStyle}>Status</th>
              <th style={{ ...thStyle, textAlign: "right" }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {records.length === 0 && (
              <tr><td style={{ ...tdStyle, textAlign: "center", color: "#7A6A57" }} colSpan={6}>No eligible employees.</td></tr>
            )}
            {records.map((r) => (
              <tr key={r.employee.id}>
                <td style={{ ...tdStyle, fontWeight: 600 }}>{r.employee.short_name}</td>
                <td style={tdStyle}>{r.employee.role}</td>
                <td style={tdStyle}>{r.employee.branch.name}</td>
                <td style={{ ...tdStyle, textAlign: "right", fontWeight: 600 }}>{formatPHP(r.adjusted_amount)}</td>
                <td style={tdStyle}><Pill tone={r.status}>{r.status}</Pill></td>
                <td style={{ ...tdStyle, textAlign: "right", whiteSpace: "nowrap" }}>
                  {r.status === "pending" && <Button small variant="gold" onClick={() => act(r.employee.id, "compute")}>Compute</Button>}
                  {r.status === "computed" && <Button small variant="outline" onClick={() => act(r.employee.id, "recompute")}>Recompute</Button>}{" "}
                  {r.status === "computed" && <Button small variant="outline" onClick={() => setAdjustRow(r)}>Adjust</Button>}{" "}
                  {r.status === "computed" && <Button small variant="primary" onClick={() => act(r.employee.id, "release")}>Release</Button>}{" "}
                  {(r.status === "computed" || r.status === "released") && <Button small variant="danger" onClick={() => act(r.employee.id, "lock")}>Lock</Button>}{" "}
                  {r.status === "locked" && <Button small variant="outline" onClick={() => setUnlockRow(r)}>Unlock</Button>}{" "}
                  {r.status !== "pending" && <Button small variant="ghost" onClick={() => downloadPayslip(r.employee.id)}>Payslip</Button>}{" "}
                  <Button small variant="ghost" onClick={() => setHistoryRow(r)}>History</Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {adjustRow && <TmAdjustModal row={adjustRow} onCancel={() => setAdjustRow(null)} onSaved={() => { setAdjustRow(null); load(); }} />}
      {unlockRow && <TmUnlockModal row={unlockRow} onCancel={() => setUnlockRow(null)} onSaved={() => { setUnlockRow(null); load(); }} />}
      {historyRow && <TmHistoryModal row={historyRow} onCancel={() => setHistoryRow(null)} />}
    </div>
  );
}
