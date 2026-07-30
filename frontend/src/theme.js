export const COLOR = {
  espresso: "#2E2118",
  espressoSoft: "#4A3728",
  gold: "#C9A227",
  cream: "#FAF6EC",
  parchment: "#F3EAD3",
  ink: "#221A13",
  inkSoft: "#7A6A57",
  rust: "#C1521F",
  rustSoft: "#F7E2D0",
  green: "#3F6B45",
  greenSoft: "#DDEBDD",
  amber: "#9A6B12",
  amberSoft: "#F3E7C8",
  line: "#E7DCC6",
};
export const FONT_DISPLAY = "'Fraunces', serif";
export const FONT_BODY = "'Inter', sans-serif";
export const FONT_MONO = "'IBM Plex Mono', monospace";

export function formatPHP(amount) {
  if (amount == null) return "—";
  return "₱" + Number(amount).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
export function formatTime12(hhmm24) {
  if (!hhmm24) return "—";
  let [h, m] = hhmm24.split(":").map(Number);
  const ampm = h >= 12 ? "PM" : "AM";
  let h12 = h % 12; if (h12 === 0) h12 = 12;
  return `${String(h12).padStart(2, "0")}:${String(m).padStart(2, "0")} ${ampm}`;
}
export function formatHoursLabel(totalHours) {
  const h = Math.floor(totalHours); const m = Math.round((totalHours - h) * 60);
  return `${h}h ${m}m`;
}
export function formatLateLabel(minutes) {
  const mins = Math.round(Number(minutes) || 0);
  if (mins <= 0) return "";
  if (mins < 60) return `${mins} min late`;
  const h = Math.floor(mins / 60); const m = mins % 60;
  return m === 0 ? `${h}h late` : `${h}h ${m}m late`;
}
