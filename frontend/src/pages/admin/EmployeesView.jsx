import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { Button, inputStyle } from "../../components/ui";
import { formatPHP } from "../../theme";

const EMPLOYMENT_TYPES = ["regular", "probationary", "fixed_term", "seasonal"];

const BLANK = {
  employee_code: "", full_name: "", short_name: "", role: "",
  branch_id: "", employment_type: "regular", hire_date: "",
  resignation_date: "", pin: "", daily_basic_rate: "",
};

function isResigned(emp) {
  return emp.resignation_date && new Date(emp.resignation_date) < new Date();
}

export default function EmployeesView() {
  const [employees, setEmployees] = useState([]);
  const [branches, setBranches] = useState([]);
  const [hideResigned, setHideResigned] = useState(true);
  const [editing, setEditing] = useState(null); // null = closed; {} = form state
  const [originalRate, setOriginalRate] = useState("");
  const [error, setError] = useState(null);

  function load() {
    apiClient.get("/api/admin/employees").then((res) => setEmployees(res.data));
  }

  useEffect(() => {
    load();
    apiClient.get("/api/admin/branches").then((res) => setBranches(res.data));
  }, []);

  function openAdd() {
    setError(null);
    setOriginalRate("");
    setEditing({ ...BLANK, id: null });
  }

  function openEdit(emp) {
    setError(null);
    const rate = emp.daily_basic_rate == null ? "" : String(emp.daily_basic_rate);
    setOriginalRate(rate);
    setEditing({
      id: emp.id,
      employee_code: emp.employee_code,
      full_name: emp.full_name,
      short_name: emp.short_name,
      role: emp.role,
      branch_id: emp.branch_id,
      employment_type: emp.employment_type,
      hire_date: emp.hire_date ? String(emp.hire_date).slice(0, 10) : "",
      resignation_date: emp.resignation_date ? String(emp.resignation_date).slice(0, 10) : "",
      pin: "",
      daily_basic_rate: rate,
      reason: "",
    });
  }

  function set(field, value) {
    setEditing((e) => ({ ...e, [field]: value }));
  }

  const rateChanged = editing && String(editing.daily_basic_rate) !== String(originalRate);

  async function save() {
    setError(null);
    const payload = { ...editing };
    if (payload.daily_basic_rate === "") payload.daily_basic_rate = null;
    if (payload.resignation_date === "") payload.resignation_date = null;
    if (!payload.pin) delete payload.pin;
    try {
      if (editing.id) {
        await apiClient.put(`/api/admin/employees/${editing.id}`, payload);
      } else {
        await apiClient.post("/api/admin/employees", payload);
      }
      setEditing(null);
      load();
    } catch (err) {
      setError(err.response?.data?.message || "Could not save employee.");
    }
  }

  async function remove(emp) {
    if (!window.confirm(`Delete ${emp.short_name}? This cannot be undone.`)) return;
    try {
      await apiClient.delete(`/api/admin/employees/${emp.id}`);
      load();
    } catch (err) {
      window.alert(err.response?.data?.message || "Could not delete employee.");
    }
  }

  const visible = hideResigned ? employees.filter((e) => !isResigned(e)) : employees;

  return (
    <div>
      <div style={{ display: "flex", alignItems: "center", gap: 16, marginBottom: 16 }}>
        <Button variant="gold" onClick={openAdd}>+ Add Employee</Button>
        <label style={{ fontSize: 13, display: "flex", gap: 6, alignItems: "center" }}>
          <input type="checkbox" checked={hideResigned} onChange={(e) => setHideResigned(e.target.checked)} />
          Hide resigned
        </label>
      </div>

      <table style={{ width: "100%", borderCollapse: "collapse", background: "white", border: "1px solid #E7DCC6", borderRadius: 10 }}>
        <thead>
          <tr style={{ textAlign: "left", fontSize: 12, color: "#7A6A57" }}>
            <th style={{ padding: 10 }}>Code</th>
            <th style={{ padding: 10 }}>Name</th>
            <th style={{ padding: 10 }}>Branch</th>
            <th style={{ padding: 10 }}>Type</th>
            <th style={{ padding: 10 }}>Daily Rate</th>
            <th style={{ padding: 10 }}>Status</th>
            <th style={{ padding: 10 }}></th>
          </tr>
        </thead>
        <tbody>
          {visible.map((emp) => (
            <tr key={emp.id} style={{ borderTop: "1px solid #E7DCC6", fontSize: 13 }}>
              <td style={{ padding: 10 }}>{emp.employee_code}</td>
              <td style={{ padding: 10 }}>{emp.full_name}</td>
              <td style={{ padding: 10 }}>{emp.branch?.name}</td>
              <td style={{ padding: 10 }}>{emp.employment_type.replace("_", " ")}</td>
              <td style={{ padding: 10 }}>{emp.daily_basic_rate == null ? "— (global)" : formatPHP(emp.daily_basic_rate)}</td>
              <td style={{ padding: 10 }}>{isResigned(emp) ? "Resigned" : "Active"}</td>
              <td style={{ padding: 10, textAlign: "right", whiteSpace: "nowrap" }}>
                <Button small onClick={() => openEdit(emp)}>Edit</Button>{" "}
                <Button small variant="danger" onClick={() => remove(emp)}>Delete</Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {editing && (
        <div style={{ background: "white", border: "1px solid #E7DCC6", borderRadius: 10, padding: 20, marginTop: 16, maxWidth: 520 }}>
          <h3 style={{ marginTop: 0 }}>{editing.id ? "Edit Employee" : "Add Employee"}</h3>
          {[
            ["employee_code", "Employee Code", "text"],
            ["full_name", "Full Name", "text"],
            ["short_name", "Short Name", "text"],
            ["role", "Role", "text"],
            ["hire_date", "Hire Date", "date"],
            ["resignation_date", "Resignation Date (optional)", "date"],
          ].map(([field, label, type]) => (
            <div key={field} style={{ marginBottom: 12 }}>
              <div style={{ fontSize: 12, marginBottom: 4 }}>{label}</div>
              <input type={type} value={editing[field]} onChange={(e) => set(field, e.target.value)} style={inputStyle} />
            </div>
          ))}
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>Branch</div>
            <select value={editing.branch_id} onChange={(e) => set("branch_id", e.target.value)} style={inputStyle}>
              <option value="">Select branch…</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>Employment Type</div>
            <select value={editing.employment_type} onChange={(e) => set("employment_type", e.target.value)} style={inputStyle}>
              {EMPLOYMENT_TYPES.map((t) => <option key={t} value={t}>{t.replace("_", " ")}</option>)}
            </select>
          </div>
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>PIN {editing.id ? "(leave blank to keep current)" : "(4 digits)"}</div>
            <input type="text" value={editing.pin} onChange={(e) => set("pin", e.target.value)} style={inputStyle} />
          </div>
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>Daily Basic Rate (₱) — blank = use global rate</div>
            <input type="number" step="0.01" value={editing.daily_basic_rate} onChange={(e) => set("daily_basic_rate", e.target.value)} style={inputStyle} />
          </div>
          {rateChanged && (
            <div style={{ marginBottom: 12 }}>
              <div style={{ fontSize: 12, marginBottom: 4, color: "#C1521F" }}>Reason (required — the daily rate changed)</div>
              <input type="text" value={editing.reason || ""} onChange={(e) => set("reason", e.target.value)} style={inputStyle} />
            </div>
          )}
          {error && <div style={{ color: "#C1521F", fontSize: 12, marginBottom: 12 }}>{error}</div>}
          <Button variant="gold" onClick={save}>Save</Button>{" "}
          <Button onClick={() => setEditing(null)}>Cancel</Button>
        </div>
      )}
    </div>
  );
}
