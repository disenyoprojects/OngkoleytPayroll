import { useEffect, useState } from "react";
import { apiClient } from "../../api/client";
import { Button, inputStyle, ModalShell, Pill } from "../../components/ui";
import { formatPHP, phToday as today } from "../../theme";
import AttendanceLogModal from "../../components/AttendanceLogModal";

const EMPLOYMENT_TYPES = ["regular", "probationary", "fixed_term", "seasonal"];

// Monday first — how the roster is read here. Values match Carbon/date('w').
const WEEK = [
  [1, "Monday"], [2, "Tuesday"], [3, "Wednesday"], [4, "Thursday"],
  [5, "Friday"], [6, "Saturday"], [0, "Sunday"],
];
const BLANK_WEEK = Object.fromEntries(WEEK.map(([d]) => [d, { start: "", end: "" }]));

const BLANK = {
  employee_code: "", full_name: "", short_name: "", role: "",
  branch_id: "", employment_type: "regular", hire_date: "",
  resignation_date: "", shift_start: "08:00", shift_end: "17:00",
  daily_basic_rate: "", day_shifts: BLANK_WEEK,
};

export default function EmployeesView({ isAdmin = true, myBranchId = null }) {
  const [view, setView] = useState("active"); // "active" | "separated"
  const [employees, setEmployees] = useState([]);
  const [separated, setSeparated] = useState([]);
  const [sepFilter, setSepFilter] = useState("all"); // all | proper | improper
  const [branchFilter, setBranchFilter] = useState(""); // "" = all branches
  const [branches, setBranches] = useState([]);
  const [editing, setEditing] = useState(null); // add/edit form state
  const [error, setError] = useState(null);
  const [removing, setRemoving] = useState(null); // employee being separated
  const [logEmployee, setLogEmployee] = useState(null); // employee whose attendance log is open
  const [sepForm, setSepForm] = useState({ separation_type: "proper", resignation_date: "", reason: "" });
  const [sepError, setSepError] = useState(null);

  function loadActive() {
    apiClient.get("/api/admin/employees").then((res) => setEmployees(res.data));
  }

  function loadSeparated(filter = sepFilter) {
    const q = filter === "all" ? "" : `?type=${filter}`;
    apiClient.get(`/api/admin/employees/separated${q}`).then((res) => setSeparated(res.data));
  }

  useEffect(() => {
    loadActive();
    apiClient.get("/api/admin/branches").then((res) => setBranches(res.data));
  }, []);

  useEffect(() => {
    if (view === "separated") loadSeparated();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [view, sepFilter]);

  function openAdd() {
    setError(null);
    setEditing({ ...BLANK, id: null, branch_id: isAdmin ? "" : myBranchId });
  }

  function openEdit(emp) {
    setError(null);
    const rate = emp.daily_basic_rate == null ? "" : String(emp.daily_basic_rate);
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
      shift_start: emp.shift_start ? String(emp.shift_start).slice(0, 5) : "08:00",
      shift_end: emp.shift_end ? String(emp.shift_end).slice(0, 5) : "17:00",
      day_shifts: {
        ...BLANK_WEEK,
        ...Object.fromEntries((emp.day_shifts ?? []).map((s) => [s.day_of_week, {
          start: String(s.shift_start).slice(0, 5),
          end: String(s.shift_end).slice(0, 5),
        }])),
      },
      daily_basic_rate: rate,
      reason: "",
    });
  }

  function set(field, value) {
    setEditing((e) => ({ ...e, [field]: value }));
  }

  function setDayShift(day, side, value) {
    setEditing((e) => ({
      ...e,
      day_shifts: { ...e.day_shifts, [day]: { ...e.day_shifts[day], [side]: value } },
    }));
  }

  async function save() {
    setError(null);
    const payload = { ...editing };
    if (payload.daily_basic_rate === "") payload.daily_basic_rate = null;
    if (payload.resignation_date === "") payload.resignation_date = null;
    // Only days with both times set are sent; the rest fall back to the default
    // shift, and the API removes any row they previously had.
    payload.day_shifts = WEEK
      .filter(([d]) => editing.day_shifts?.[d]?.start && editing.day_shifts?.[d]?.end)
      .map(([d]) => ({
        day_of_week: d,
        shift_start: editing.day_shifts[d].start,
        shift_end: editing.day_shifts[d].end,
      }));
    try {
      if (editing.id) {
        await apiClient.put(`/api/admin/employees/${editing.id}`, payload);
      } else {
        await apiClient.post("/api/admin/employees", payload);
      }
      setEditing(null);
      loadActive();
    } catch (err) {
      setError(err.response?.data?.message || "Could not save employee.");
    }
  }

  function openRemove(emp) {
    setSepError(null);
    setSepForm({ separation_type: "proper", resignation_date: today(), reason: "" });
    setRemoving(emp);
  }

  async function submitRemove() {
    setSepError(null);
    try {
      await apiClient.post(`/api/admin/employees/${removing.id}/separate`, sepForm);
      setRemoving(null);
      loadActive();
    } catch (err) {
      setSepError(err.response?.data?.message || "Could not remove employee.");
    }
  }

  async function restore(emp) {
    try {
      await apiClient.post(`/api/admin/employees/${emp.id}/restore`);
      loadSeparated();
      loadActive();
    } catch (err) {
      window.alert(err.response?.data?.message || "Could not restore employee.");
    }
  }

  const activeEmployees = branchFilter
    ? employees.filter((e) => String(e.branch_id) === String(branchFilter))
    : employees;

  const switchStyle = (active) => ({
    padding: "6px 14px", borderRadius: 7, border: "1px solid #E7DCC6",
    background: active ? "#2E2118" : "white", color: active ? "#FAF6EC" : "#221A13",
    fontSize: 13, fontWeight: 600, cursor: "pointer",
  });

  return (
    <div>
      <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 16, flexWrap: "wrap" }}>
        <button style={switchStyle(view === "active")} onClick={() => setView("active")}>Active</button>
        <button style={switchStyle(view === "separated")} onClick={() => setView("separated")}>Separated</button>
        {view === "active" && (
          <>
            <Button variant="gold" onClick={openAdd}>+ Add Employee</Button>
            <select
              value={branchFilter}
              onChange={(e) => setBranchFilter(e.target.value)}
              style={{ ...inputStyle, width: "auto", marginLeft: "auto" }}
            >
              <option value="">All branches</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </>
        )}
      </div>

      {view === "active" && (
        <table style={{ width: "100%", borderCollapse: "collapse", background: "white", border: "1px solid #E7DCC6", borderRadius: 10 }}>
          <thead>
            <tr style={{ textAlign: "left", fontSize: 12, color: "#7A6A57" }}>
              <th style={{ padding: 10 }}>Code</th>
              <th style={{ padding: 10 }}>Name</th>
              <th style={{ padding: 10 }}>Branch</th>
              <th style={{ padding: 10 }}>Type</th>
              <th style={{ padding: 10 }}>Daily Rate</th>
              <th style={{ padding: 10 }}></th>
            </tr>
          </thead>
          <tbody>
            {activeEmployees.length === 0 && (
              <tr><td style={{ padding: 12, fontSize: 13, color: "#7A6A57" }} colSpan={6}>No employees in this branch.</td></tr>
            )}
            {activeEmployees.map((emp) => (
              <tr key={emp.id} style={{ borderTop: "1px solid #E7DCC6", fontSize: 13 }}>
                <td style={{ padding: 10 }}>{emp.employee_code}</td>
                <td style={{ padding: 10 }}>{emp.full_name}</td>
                <td style={{ padding: 10 }}>{emp.branch?.name}</td>
                <td style={{ padding: 10 }}>{emp.employment_type.replace("_", " ")}</td>
                <td style={{ padding: 10 }}>{emp.daily_basic_rate == null ? "— (global)" : formatPHP(emp.daily_basic_rate)}</td>
                <td style={{ padding: 10, textAlign: "right", whiteSpace: "nowrap" }}>
                  <Button small onClick={() => setLogEmployee(emp)}>Log</Button>{" "}
                  <Button small onClick={() => openEdit(emp)}>Edit</Button>{" "}
                  <Button small variant="danger" onClick={() => openRemove(emp)}>Remove</Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {view === "separated" && (
        <div>
          <div style={{ display: "flex", gap: 8, marginBottom: 12, fontSize: 13, alignItems: "center" }}>
            <span style={{ color: "#7A6A57" }}>Filter:</span>
            {["all", "proper", "improper"].map((f) => (
              <button key={f} style={switchStyle(sepFilter === f)} onClick={() => setSepFilter(f)}>
                {f === "all" ? "All" : f.charAt(0).toUpperCase() + f.slice(1)}
              </button>
            ))}
          </div>
          <table style={{ width: "100%", borderCollapse: "collapse", background: "white", border: "1px solid #E7DCC6", borderRadius: 10 }}>
            <thead>
              <tr style={{ textAlign: "left", fontSize: 12, color: "#7A6A57" }}>
                <th style={{ padding: 10 }}>Code</th>
                <th style={{ padding: 10 }}>Name</th>
                <th style={{ padding: 10 }}>Branch</th>
                <th style={{ padding: 10 }}>Resignation</th>
                <th style={{ padding: 10 }}>Removed On</th>
                <th style={{ padding: 10 }}>Reason</th>
                <th style={{ padding: 10 }}></th>
              </tr>
            </thead>
            <tbody>
              {separated.length === 0 && (
                <tr><td colSpan={7} style={{ padding: 14, fontSize: 13, color: "#7A6A57" }}>No separated employees.</td></tr>
              )}
              {separated.map((emp) => (
                <tr key={emp.id} style={{ borderTop: "1px solid #E7DCC6", fontSize: 13 }}>
                  <td style={{ padding: 10 }}>{emp.employee_code}</td>
                  <td style={{ padding: 10 }}>{emp.full_name}</td>
                  <td style={{ padding: 10 }}>{emp.branch?.name}</td>
                  <td style={{ padding: 10 }}>
                    <Pill tone={emp.separation_type === "proper" ? "approved" : "locked"}>
                      {emp.separation_type}
                    </Pill>
                  </td>
                  <td style={{ padding: 10 }}>{emp.deleted_at ? String(emp.deleted_at).slice(0, 10) : "—"}</td>
                  <td style={{ padding: 10 }}>{emp.separation_reason}</td>
                  <td style={{ padding: 10, textAlign: "right" }}>
                    <Button small onClick={() => setLogEmployee(emp)}>Log</Button>{" "}
                    <Button small onClick={() => restore(emp)}>Restore</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {logEmployee && <AttendanceLogModal employee={logEmployee} onClose={() => setLogEmployee(null)} />}

      {editing && (
        <ModalShell width={520} onClose={() => setEditing(null)}>
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
            {/* A branch login only ever gets its own branches back from the
                API, so the same picker serves both — it just has fewer
                options. A single-branch login sees its one branch fixed. */}
            {isAdmin || branches.length > 1 ? (
              <select value={editing.branch_id} onChange={(e) => set("branch_id", e.target.value)} style={inputStyle}>
                <option value="">Select branch…</option>
                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
              </select>
            ) : (
              <input type="text" value={branches.find((b) => String(b.id) === String(editing.branch_id))?.name ?? "Your branch"} disabled style={inputStyle} />
            )}
          </div>
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>Employment Type</div>
            <select value={editing.employment_type} onChange={(e) => set("employment_type", e.target.value)} style={inputStyle}>
              {EMPLOYMENT_TYPES.map((t) => <option key={t} value={t}>{t.replace("_", " ")}</option>)}
            </select>
          </div>
          <div style={{ display: "flex", gap: 12, marginBottom: 12 }}>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 12, marginBottom: 4 }}>Default Shift Start</div>
              <input type="time" value={editing.shift_start} onChange={(e) => set("shift_start", e.target.value)} style={inputStyle} />
            </div>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 12, marginBottom: 4 }}>Default Shift End</div>
              <input type="time" value={editing.shift_end} onChange={(e) => set("shift_end", e.target.value)} style={inputStyle} />
            </div>
          </div>

          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 2 }}>Shift Per Day</div>
            <div style={{ fontSize: 11.5, color: "#7A6A57", marginBottom: 8 }}>
              For staff whose hours differ by day. Leave a day blank to use the default shift above.
            </div>
            {WEEK.map(([day, name]) => {
              const row = editing.day_shifts?.[day] ?? { start: "", end: "" };
              const partial = Boolean(row.start) !== Boolean(row.end);
              return (
                <div key={day} style={{ display: "flex", gap: 8, alignItems: "center", marginBottom: 6 }}>
                  <div style={{ width: 84, fontSize: 12.5 }}>{name}</div>
                  <input
                    type="time" value={row.start} aria-label={`${name} shift start`}
                    onChange={(e) => setDayShift(day, "start", e.target.value)}
                    style={{ ...inputStyle, flex: 1, marginBottom: 0 }}
                  />
                  <span style={{ fontSize: 12, color: "#7A6A57" }}>–</span>
                  <input
                    type="time" value={row.end} aria-label={`${name} shift end`}
                    onChange={(e) => setDayShift(day, "end", e.target.value)}
                    style={{ ...inputStyle, flex: 1, marginBottom: 0 }}
                  />
                  <button
                    type="button" title={`Clear ${name}`} aria-label={`Clear ${name}`}
                    onClick={() => { setDayShift(day, "start", ""); setDayShift(day, "end", ""); }}
                    style={{
                      width: 26, height: 26, lineHeight: 1, border: "1px solid #E3D9C6",
                      background: "transparent", borderRadius: 6, cursor: "pointer",
                      color: row.start || row.end ? "#7A6A57" : "#CFC4B0",
                    }}
                  >×</button>
                  {partial && <span style={{ fontSize: 11, color: "#C1521F", whiteSpace: "nowrap" }}>set both</span>}
                </div>
              );
            })}
          </div>
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>Daily Basic Rate (₱) — blank = use global rate</div>
            <input type="number" step="0.01" value={editing.daily_basic_rate} onChange={(e) => set("daily_basic_rate", e.target.value)} style={inputStyle} />
          </div>
          {error && <div style={{ color: "#C1521F", fontSize: 12, marginBottom: 12 }}>{error}</div>}
          <Button variant="gold" onClick={save}>Save</Button>{" "}
          <Button onClick={() => setEditing(null)}>Cancel</Button>
        </ModalShell>
      )}

      {removing && (
        <ModalShell width={460} onClose={() => setRemoving(null)}>
          <h3 style={{ marginTop: 0 }}>Remove {removing.short_name}</h3>
          <p style={{ fontSize: 13, color: "#7A6A57", marginTop: 0 }}>
            This moves the employee to Separated. Their records are kept and they can be restored later.
          </p>
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>Resignation Type</div>
            <select value={sepForm.separation_type} onChange={(e) => setSepForm((f) => ({ ...f, separation_type: e.target.value }))} style={inputStyle}>
              <option value="proper">Proper (gave notice / formal)</option>
              <option value="improper">Improper (AWOL / no notice)</option>
            </select>
          </div>
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>Resignation Date</div>
            <input type="date" value={sepForm.resignation_date} onChange={(e) => setSepForm((f) => ({ ...f, resignation_date: e.target.value }))} style={inputStyle} />
          </div>
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 12, marginBottom: 4 }}>Reason (required)</div>
            <input type="text" value={sepForm.reason} onChange={(e) => setSepForm((f) => ({ ...f, reason: e.target.value }))} style={inputStyle} />
          </div>
          {sepError && <div style={{ color: "#C1521F", fontSize: 12, marginBottom: 12 }}>{sepError}</div>}
          <Button variant="danger" onClick={submitRemove}>Remove Employee</Button>{" "}
          <Button onClick={() => setRemoving(null)}>Cancel</Button>
        </ModalShell>
      )}
    </div>
  );
}
