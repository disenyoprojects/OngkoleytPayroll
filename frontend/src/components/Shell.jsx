import { Link, useLocation } from "react-router-dom";
import { COLOR, FONT_DISPLAY } from "../theme";

const TABS = [
  ["/kiosk", "Kiosk · Clock In/Out"],
  ["/staff-login", "Staff Timesheet Login"],
  ["/admin", "Admin"],
];

export default function Shell({ children }) {
  const location = useLocation();
  return (
    <div style={{ fontFamily: "'Inter', sans-serif", background: COLOR.cream, minHeight: "100vh", color: COLOR.ink }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "12px 24px", borderBottom: `1px solid ${COLOR.line}`, background: "white" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <div style={{ width: 22, height: 22, borderRadius: 4, background: COLOR.gold }} />
          <div style={{ fontFamily: FONT_DISPLAY, fontWeight: 700, fontSize: 15 }}>Ongkoleyt</div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          {TABS.map(([path, label]) => {
            const active = location.pathname.startsWith(path);
            return (
              <Link key={path} to={path} style={{ padding: "7px 14px", borderRadius: 999, border: `1px solid ${active ? COLOR.espresso : COLOR.line}`, background: active ? COLOR.espresso : "white", color: active ? COLOR.cream : COLOR.ink, fontSize: 12.5, fontWeight: 600, textDecoration: "none" }}>
                {label}
              </Link>
            );
          })}
        </div>
      </div>
      {children}
    </div>
  );
}
