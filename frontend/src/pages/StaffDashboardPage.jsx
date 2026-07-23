import { useEffect, useState } from "react";
import { apiClient } from "../api/client";
import { COLOR, FONT_DISPLAY, formatHoursLabel, formatPHP, formatTime12 } from "../theme";

function MiniStat({ label, value }) {
  return (
    <div>
      <div style={{ fontSize: 11, color: COLOR.inkSoft, textTransform: "uppercase", marginBottom: 4 }}>{label}</div>
      <div style={{ fontWeight: 700, fontSize: 15 }}>{value}</div>
    </div>
  );
}

export default function StaffDashboardPage({ staff, token, onLogout }) {
  const [data, setData] = useState(null);

  useEffect(() => {
    apiClient.get("/api/kiosk/dashboard", { headers: { Authorization: `Bearer ${token}` } }).then((res) => setData(res.data));
  }, [token]);

  if (!data) return <div style={{ padding: 32 }}>Loading...</div>;

  return (
    <div style={{ maxWidth: 900, margin: "0 auto", padding: "32px 24px" }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 20 }}>
        <h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 24, margin: 0 }}>Hi, {staff.full_name.split(" ")[0]}</h1>
        <button onClick={onLogout} style={{ padding: "9px 18px", borderRadius: 7, border: `1px solid ${COLOR.line}`, background: "white", cursor: "pointer" }}>Log Out</button>
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20, marginBottom: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 12px" }}>Today</h3>
        {!data.today ? <div style={{ fontSize: 13, color: COLOR.inkSoft }}>You haven't clocked in yet today.</div> : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 16 }}>
            <MiniStat label="Clock In" value={formatTime12(data.today.record.clock_in)} />
            <MiniStat label="Clock Out" value={data.today.record.clock_out ? formatTime12(data.today.record.clock_out) : "Still working"} />
            <MiniStat label="Hours" value={data.today.pay ? formatHoursLabel(data.today.pay.total_hours) : "—"} />
            <MiniStat label="Total Pay" value={data.today.pay ? formatPHP(data.today.pay.total) : "—"} />
          </div>
        )}
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20, marginBottom: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 12px" }}>This Week</h3>
        {!data.week ? <div style={{ fontSize: 13, color: COLOR.inkSoft }}>No hours logged yet this week.</div> : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 16 }}>
            <MiniStat label="Days Worked" value={data.week.days_worked} />
            <MiniStat label="Total Hours" value={`${data.week.total_hours}h`} />
            <MiniStat label="Overtime" value={formatPHP(data.week.ot)} />
            <MiniStat label="Total Pay" value={formatPHP(data.week.total)} />
          </div>
        )}
      </div>

      <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: 20 }}>
        <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: 15, margin: "0 0 12px" }}>13th Month Pay (estimated to date)</h3>
        {!data.thirteenth_month ? <div style={{ fontSize: 13, color: COLOR.inkSoft }}>Not currently eligible under this year's settings.</div> : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(2, 1fr)", gap: 16 }}>
            <MiniStat label="Basic Earned (Annual)" value={formatPHP(data.thirteenth_month.total_basic_earned)} />
            <MiniStat label="Estimated 13th Month" value={formatPHP(data.thirteenth_month.estimated_amount)} />
          </div>
        )}
      </div>
    </div>
  );
}
