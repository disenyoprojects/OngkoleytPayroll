// Local queue of Clock In/Out actions taken while offline. Each entry
// captures `clocked_at` at the moment the button was tapped — that's the
// real time the action happened, and must survive until it's synced,
// however long that takes. The server (see ClockController::resolveMoment)
// uses this to derive the correct work_date and clock time, not the sync time.
const QUEUE_KEY = "ongkoleyt_clock_queue";
const CHANGE_EVENT = "ongkoleyt:clockqueue";

function read() {
  try {
    const raw = localStorage.getItem(QUEUE_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch {
    return [];
  }
}

function write(items) {
  localStorage.setItem(QUEUE_KEY, JSON.stringify(items));
  window.dispatchEvent(new CustomEvent(CHANGE_EVENT));
}

export function getQueue() {
  return read();
}

export function onQueueChange(handler) {
  window.addEventListener(CHANGE_EVENT, handler);
  return () => window.removeEventListener(CHANGE_EVENT, handler);
}

/** The queued-but-not-yet-synced action for an employee, if any. */
export function pendingActionFor(employeeId) {
  return read().find((item) => String(item.employee_id) === String(employeeId)) || null;
}

export function enqueueClock({ employeeId, employeeName, action }) {
  const items = read();
  const entry = {
    localId: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    employee_id: employeeId,
    employee_name: employeeName,
    action, // "in" | "out"
    clocked_at: new Date().toISOString(),
  };
  items.push(entry);
  write(items);
  return entry;
}

export function removeFromQueue(localId) {
  write(read().filter((item) => item.localId !== localId));
}

export function queueLength() {
  return read().length;
}
