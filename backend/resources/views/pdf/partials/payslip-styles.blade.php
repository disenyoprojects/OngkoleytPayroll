<style>
    @page { margin: 12mm 16mm; }
    body { font-family: DejaVu Sans, sans-serif; color: #1c1c1c; font-size: 10.5px; }
    /* One employee per page, the two copies stacked on it. */
    .pair { page-break-after: always; }
    .pair:last-child { page-break-after: auto; }
    /* A copy that will not fit under the first moves whole to the next page
       rather than splitting across two. */
    .sheet { border: 1px solid #3a3a3a; page-break-inside: avoid; }
    .cut { border-top: 1px dashed #888; margin: 14px 0; text-align: center; font-size: 8.5px; color: #888; }
    .copy { text-align: right; padding: 4px 10px 0; font-size: 9.5px; font-weight: bold; letter-spacing: 1px; color: #6b3410; }
    .band-name { background: #6b3410; color: #fff; text-align: center; font-size: 15px; font-weight: bold; padding: 6px 0; letter-spacing: .5px; }
    .band-addr { background: #dfe8cf; color: #222; text-align: center; font-size: 9.5px; font-style: italic; padding: 4px 0; border-bottom: 1px solid #3a3a3a; }
    .title { text-align: center; font-size: 13.5px; font-weight: bold; padding: 2px 0 3px; letter-spacing: 1px; }
    .meta { width: 100%; border-collapse: collapse; }
    .meta td { padding: 1px 12px; font-size: 10.5px; }
    .meta .lbl { font-weight: bold; }
    .cols { width: 100%; border-collapse: collapse; margin-top: 6px; table-layout: fixed; }
    .cols th { background: #f2f2f2; border-top: 1px solid #3a3a3a; border-bottom: 1px solid #3a3a3a; padding: 4px 10px; font-size: 11px; }
    .cols th.l { text-align: center; }
    .cols td { padding: 2px 10px; font-size: 10.5px; }
    .cols .item { color: #1f3a6b; }
    .cols .amt { text-align: right; }
    .cols .mid { border-left: 1px solid #3a3a3a; }
    .totrow td { border-top: 1px solid #3a3a3a; border-bottom: 1px solid #3a3a3a; font-weight: bold; padding: 5px 10px; }
    .net { text-align: center; padding: 10px 0 4px; font-size: 12.5px; font-weight: bold; }
    .net .val { color: #C00000; margin-left: 30px; }
    .confirm { font-style: italic; color: #333; font-size: 9.5px; padding: 12px 12px 2px; }
    .sign { width: 100%; border-collapse: collapse; }
    .sign td { padding: 20px 12px 6px; font-size: 9.5px; vertical-align: bottom; }
    .sign .line { border-top: 1px solid #555; padding-top: 3px; font-weight: bold; }
</style>
