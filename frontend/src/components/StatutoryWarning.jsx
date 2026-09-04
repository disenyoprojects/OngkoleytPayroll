const LABELS = { sss: "SSS", philhealth: "PhilHealth", pagibig: "Pag-IBIG" };

/**
 * Says out loud that a period's statutory deductions were never generated.
 *
 * SSS, Pag-IBIG and PhilHealth are stored rows written by the Generate button,
 * not figures worked out when a payslip is drawn, so a period nobody generated
 * shows no deductions at all — and looks like a perfectly ordinary payslip
 * while doing it. This is the only thing that tells the two apart.
 *
 * Pass `missing` (a payslip's statutory_missing) or `count` (how many people on
 * a register are short). Renders nothing when there is nothing to warn about.
 */
export default function StatutoryWarning({ missing, count, children }) {
  const names = (missing ?? []).map((m) => LABELS[m] ?? m);
  if (!names.length && !count) return null;

  return (
    <div
      role="status"
      style={{
        display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap",
        background: "#FDF3E3", border: "1px solid #E8C98A", borderLeft: "4px solid #C1861F",
        borderRadius: 8, padding: "10px 14px", marginBottom: 14, fontSize: 13, color: "#6B4E12",
      }}
    >
      <span style={{ flex: 1, minWidth: 240 }}>
        <strong>Statutory deductions not yet generated for this period.</strong>{" "}
        {count
          ? `${count} employee${count === 1 ? "" : "s"} ${count === 1 ? "is" : "are"} missing SSS, Pag-IBIG or PhilHealth.`
          : `Missing: ${names.join(", ")}.`}
      </span>
      {children}
    </div>
  );
}
