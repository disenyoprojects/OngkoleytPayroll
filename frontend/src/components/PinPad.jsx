import { useState } from "react";
import { COLOR } from "../theme";

export default function PinPad({ onSubmit, onBack, error }) {
  const [pin, setPin] = useState("");

  function pressDigit(d) {
    if (pin.length >= 4) return;
    const next = pin + d;
    setPin(next);
    if (next.length === 4) {
      setTimeout(() => {
        onSubmit(next);
        setPin("");
      }, 150);
    }
  }

  return (
    <div>
      <div style={{ display: "flex", gap: 10, marginBottom: 22, justifyContent: "center" }}>
        {[0, 1, 2, 3].map((i) => (
          <div key={i} style={{ width: 14, height: 14, borderRadius: "50%", background: i < pin.length ? (error ? COLOR.rust : COLOR.espresso) : COLOR.line }} />
        ))}
      </div>
      {error && <div style={{ color: COLOR.rust, fontSize: 12, textAlign: "center", marginBottom: 14 }}>Incorrect PIN — try again</div>}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 90px)", gap: 12 }}>
        {["1", "2", "3", "4", "5", "6", "7", "8", "9"].map((d) => (
          <button key={d} onClick={() => pressDigit(d)} style={{ height: 64, borderRadius: 10, border: `1px solid ${COLOR.line}`, background: "white", fontSize: 20, fontWeight: 700, cursor: "pointer" }}>{d}</button>
        ))}
        <button onClick={() => setPin(pin.slice(0, -1))} style={{ height: 64, borderRadius: 10, border: `1px solid ${COLOR.line}`, background: COLOR.parchment, fontSize: 13, fontWeight: 700, color: COLOR.inkSoft, cursor: "pointer" }}>DEL</button>
        <button onClick={() => pressDigit("0")} style={{ height: 64, borderRadius: 10, border: `1px solid ${COLOR.line}`, background: "white", fontSize: 20, fontWeight: 700, cursor: "pointer" }}>0</button>
        <button onClick={() => pin.length === 4 && onSubmit(pin)} style={{ height: 64, borderRadius: 10, border: "none", background: COLOR.greenSoft, fontSize: 20, color: COLOR.green, cursor: "pointer" }}>✓</button>
      </div>
      <button onClick={onBack} style={{ marginTop: 18, background: "none", border: "none", color: COLOR.inkSoft, fontSize: 13, textDecoration: "underline", cursor: "pointer" }}>← Back</button>
    </div>
  );
}
