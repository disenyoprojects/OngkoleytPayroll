import { COLOR, FONT_DISPLAY } from "../theme";

export default function Shell({ children }) {
  return (
    <div style={{ fontFamily: "'Inter', sans-serif", background: COLOR.cream, minHeight: "100vh", color: COLOR.ink }}>
      <div style={{ display: "flex", alignItems: "center", gap: 8, padding: "12px 24px", borderBottom: `1px solid ${COLOR.line}`, background: "white" }}>
        <div style={{ width: 22, height: 22, borderRadius: 4, background: COLOR.gold }} />
        <div style={{ fontFamily: FONT_DISPLAY, fontWeight: 700, fontSize: 15 }}>Ongkoleyt</div>
      </div>
      {children}
    </div>
  );
}
