import { COLOR, FONT_DISPLAY } from "../theme";

export function Button({ children, onClick, variant = "primary", disabled, small }) {
  const base = { fontWeight: 600, fontSize: small ? 12 : 13, padding: small ? "5px 10px" : "9px 18px", borderRadius: 7, border: "1px solid transparent", cursor: disabled ? "not-allowed" : "pointer", opacity: disabled ? 0.45 : 1 };
  const variants = {
    primary: { background: COLOR.espresso, color: COLOR.cream },
    gold: { background: COLOR.gold, color: COLOR.espresso },
    outline: { background: "white", color: COLOR.espresso, border: `1px solid ${COLOR.line}` },
    ghost: { background: "transparent", color: COLOR.inkSoft },
    danger: { background: "transparent", color: COLOR.rust, border: `1px solid ${COLOR.rust}` },
  };
  return <button style={{ ...base, ...variants[variant] }} onClick={disabled ? undefined : onClick} disabled={disabled}>{children}</button>;
}

export function Pill({ children, tone = "neutral" }) {
  const tones = {
    neutral: { bg: COLOR.parchment, fg: COLOR.ink },
    approved: { bg: COLOR.greenSoft, fg: COLOR.green },
    pending: { bg: COLOR.amberSoft, fg: COLOR.amber },
    computed: { bg: "#DCE7F5", fg: "#2C5384" },
    released: { bg: COLOR.greenSoft, fg: COLOR.green },
    locked: { bg: COLOR.rustSoft, fg: COLOR.rust },
  };
  const t = tones[tone] || tones.neutral;
  return <span style={{ display: "inline-block", padding: "3px 10px", borderRadius: 999, fontSize: 11, fontWeight: 600, textTransform: "uppercase", background: t.bg, color: t.fg }}>{children}</span>;
}

export function StatCard({ label, value }) {
  return (
    <div style={{ background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, padding: "16px 20px", flex: "1 1 180px" }}>
      <div style={{ fontSize: 11, textTransform: "uppercase", color: COLOR.inkSoft, marginBottom: 6 }}>{label}</div>
      <div style={{ fontSize: 24, fontWeight: 700 }}>{value}</div>
    </div>
  );
}

export function ModalShell({ children, onClose, width = 460 }) {
  return (
    <div style={{ position: "fixed", inset: 0, background: "rgba(46,33,24,0.5)", display: "flex", alignItems: "center", justifyContent: "center", zIndex: 50 }} onClick={onClose}>
      <div style={{ background: "white", borderRadius: 12, width, maxWidth: "90vw", maxHeight: "88vh", overflow: "auto", padding: 24 }} onClick={(e) => e.stopPropagation()}>{children}</div>
    </div>
  );
}

// One box model for every control (number / time / date / select) so heights
// and edges line up exactly. box-sizing keeps width:100% consistent across
// native controls that otherwise render at slightly different sizes.
export const inputStyle = { width: "100%", boxSizing: "border-box", height: 40, padding: "0 12px", border: `1px solid ${COLOR.line}`, borderRadius: 8, fontSize: 13, background: "white", color: COLOR.ink, fontFamily: "inherit" };

// Multi-line variant (grows instead of a fixed height).
export const textareaStyle = { ...inputStyle, height: "auto", minHeight: 72, padding: "9px 12px", resize: "vertical" };

// Consistent field label + wrapper so every form spaces fields identically.
export const labelStyle = { display: "block", fontSize: 12, fontWeight: 600, color: COLOR.inkSoft, marginBottom: 6 };

export function Field({ label, children, style }) {
  return (
    <div style={{ marginBottom: 16, ...style }}>
      <label style={labelStyle}>{label}</label>
      {children}
    </div>
  );
}

// Shared table styling for the admin data tables (card look, aligned headers,
// row dividers). Wrap a <table style={tableStyle}> inside <div style={tableWrap}>.
export const tableWrap = { background: "white", border: `1px solid ${COLOR.line}`, borderRadius: 10, overflowX: "auto", WebkitOverflowScrolling: "touch" };
export const tableStyle = { width: "100%", borderCollapse: "collapse", fontSize: 13 };
export const thStyle = { textAlign: "left", fontSize: 11, textTransform: "uppercase", letterSpacing: "0.04em", color: COLOR.inkSoft, fontWeight: 700, padding: "11px 14px", background: COLOR.cream, borderBottom: `1px solid ${COLOR.line}`, whiteSpace: "nowrap" };
export const tdStyle = { padding: "11px 14px", borderBottom: `1px solid ${COLOR.line}`, color: COLOR.ink, verticalAlign: "middle" };

export function tabBtnStyle(active) {
  return { padding: "8px 16px", borderRadius: 8, border: `1px solid ${active ? COLOR.espresso : COLOR.line}`, background: active ? COLOR.espresso : "white", color: active ? COLOR.cream : COLOR.ink, fontSize: 13, fontWeight: 600, cursor: "pointer" };
}
