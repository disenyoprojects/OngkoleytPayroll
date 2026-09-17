<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Every day's pay with the arithmetic left in, for checking by hand.
 *
 * The payroll summary workbook gives totals. This one exists because totals
 * cannot be argued with — four payslips were queried in a week and each had to
 * be reverse-engineered from its total before anyone could say whether it was
 * right. Twice the answer was a mis-keyed clock time, not a fault in the
 * computation, and there was no artefact to show that with.
 *
 * So the money columns here are live Excel formulas, not numbers. Every rate
 * and every hour count that feeds a peso figure is its own visible cell, and
 * the peso figure multiplies those cells. A reviewer can click Basic and see
 * "=ROUND(N7*M7,2)", change a rate, and watch the sheet move. The second clock
 * pair — the field that explained three of the four disputes and appears on no
 * screen the client uses — has its own two columns.
 */
class PayComputationWorkbook {
    private const MONEY = '#,##0.00;[Red](#,##0.00);"-"';
    private const HOURS = '#,##0.00;[Red](#,##0.00);"-"';
    private const RATE = '#,##0.0000;[Red](#,##0.0000);"-"';
    private const MINUTES = '#,##0;[Red](#,##0);"-"';

    private const NAVY = '1F3864';
    private const NAVY_SOFT = '2E4B7C';
    private const BAND_CLOCK = '44546A';
    private const BAND_RATE = '7F6000';
    private const BAND_EARN = '31593F';
    private const BAND_DEDUCT = '953735';
    private const ROW_ALT = 'EDF2F9';
    private const SUBTOTAL = 'DCE4F2';
    private const FLAG = 'FFF2CC';

    /** Column letter => [heading, band colour, width, number format]. */
    private const COLUMNS = [
        'A' => ['Employee', self::NAVY, 24, null],
        'B' => ['Code', self::NAVY, 11, null],
        'C' => ['Date', self::NAVY, 11, null],
        'D' => ['Day Type', self::NAVY, 17, null],
        'E' => ['Shift', self::BAND_CLOCK, 13, null],
        'F' => ['Clock In', self::BAND_CLOCK, 9.5, null],
        'G' => ['Clock Out', self::BAND_CLOCK, 9.5, null],
        'H' => ['OT In', self::BAND_CLOCK, 9.5, null],
        'I' => ['OT Out', self::BAND_CLOCK, 9.5, null],
        'J' => ['Daily Rate', self::BAND_RATE, 10, self::MONEY],
        'K' => ['Hourly = Rate/8', self::BAND_RATE, 11, self::RATE],
        'L' => ['Day Mult.', self::BAND_RATE, 9, '0.00'],
        'M' => ['Paid /hr', self::BAND_RATE, 10, self::RATE],
        'N' => ['Reg Hrs', self::BAND_EARN, 8.5, self::HOURS],
        'O' => ['Basic', self::BAND_EARN, 11, self::MONEY],
        'P' => ['OT Hrs', self::BAND_EARN, 8.5, self::HOURS],
        'Q' => ['OT /hr', self::BAND_EARN, 10, self::RATE],
        'R' => ['OT Pay', self::BAND_EARN, 11, self::MONEY],
        'S' => ['ND Hrs', self::BAND_EARN, 8.5, self::HOURS],
        'T' => ['ND /hr', self::BAND_EARN, 10, self::RATE],
        'U' => ['ND Pay', self::BAND_EARN, 10, self::MONEY],
        'V' => ['Late Min', self::BAND_DEDUCT, 9, self::MINUTES],
        'W' => ['Late Ded.', self::BAND_DEDUCT, 10, self::MONEY],
        'X' => ['UT Min', self::BAND_DEDUCT, 8.5, self::MINUTES],
        'Y' => ['Overbreak Hrs', self::BAND_DEDUCT, 11, self::HOURS],
        'Z' => ['UT Ded.', self::BAND_DEDUCT, 10, self::MONEY],
        'AA' => ['DAY TOTAL', self::NAVY, 12.5, self::MONEY],
    ];

    private const LAST = 'AA';

    /**
     * @param array<int, array{employee: \App\Models\Employee, record: \App\Models\AttendanceRecord, pay: array}> $rows
     * @param array{label: string, from: string, to: string} $window
     */
    public function __construct(
        private array $rows,
        private array $window,
        private float $nightDiffMultiplier,
        private float $overtimeMultiplier,
        private float $minimumOtMinutes,
        private float $defaultDailyRate,
    ) {}

    public function build(): Spreadsheet {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Computation');
        $this->buildComputation($sheet);

        $rules = $book->createSheet();
        $rules->setTitle('How It Is Computed');
        $this->buildRules($rules);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ------------------------------------------------------------ computation

    private function buildComputation(Worksheet $sheet): void {
        $this->banner($sheet, sprintf(
            'Pay period: %s     |     %d day records     |     every peso column is a live formula — click it to see the arithmetic',
            $this->window['label'],
            count($this->rows),
        ));

        $sheet->getRowDimension(3)->setRowHeight(5);

        foreach ([
            ['A', 'D', 'WHO AND WHEN', self::NAVY],
            ['E', 'I', 'AS ENCODED', self::BAND_CLOCK],
            ['J', 'M', 'RATES USED', self::BAND_RATE],
            ['N', 'U', 'EARNINGS', self::BAND_EARN],
            ['V', 'Z', 'TIME CHARGED BACK', self::BAND_DEDUCT],
            [self::LAST, self::LAST, '', self::NAVY],
        ] as [$from, $to, $label, $colour]) {
            $range = "{$from}4:{$to}4";
            $sheet->mergeCells($range);
            $sheet->setCellValue("{$from}4", $label);
            $this->fill($sheet, $range, $colour);
            $sheet->getStyle($range)->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($range)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        }

        foreach (self::COLUMNS as $letter => [$heading, $colour, , ]) {
            $sheet->setCellValue("{$letter}5", $heading);
            $this->fill($sheet, "{$letter}5", $colour);
            $sheet->getStyle("{$letter}5")->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("{$letter}5")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        }
        $sheet->getRowDimension(4)->setRowHeight(18);
        $sheet->getRowDimension(5)->setRowHeight(28);

        $row = 6;
        $employeeFirst = $row;
        $previous = null;

        foreach ($this->rows as $entry) {
            $code = $entry['employee']->employee_code;

            // A subtotal closes each employee before the next one opens, so the
            // sheet ties to that person's payslip without anyone filtering.
            if ($previous !== null && $code !== $previous) {
                $this->subtotal($sheet, $row, $employeeFirst, $row - 1);
                ++$row;
                $employeeFirst = $row;
            }

            $this->writeDay($sheet, $row, $entry);
            if (($row - $employeeFirst) % 2 === 1) {
                $this->fill($sheet, "A{$row}:" . self::LAST . $row, self::ROW_ALT);
            }
            $previous = $code;
            ++$row;
        }

        if ($previous !== null) {
            $this->subtotal($sheet, $row, $employeeFirst, $row - 1);
            ++$row;
        }

        $this->styleBody($sheet, 6, $row - 1);

        foreach (self::COLUMNS as $letter => [, , $width, ]) {
            $sheet->getColumnDimension($letter)->setWidth($width);
        }

        $sheet->freezePane('E6');
        $sheet->setAutoFilter('A5:' . self::LAST . '5');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setPrintArea('A1:' . self::LAST . ($row - 1));
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4, 5);
    }

    /** One attendance day, with the peso columns written as formulas. */
    private function writeDay(Worksheet $sheet, int $row, array $entry): void {
        $employee = $entry['employee'];
        $record = $entry['record'];
        $pay = $entry['pay'];

        $sheet->setCellValue("A{$row}", $employee->short_name ?: $employee->full_name);
        $sheet->setCellValue("B{$row}", $employee->employee_code);
        $sheet->setCellValue("C{$row}", $record->work_date?->format('Y-m-d'));
        $sheet->setCellValue("D{$row}", $pay['premium_label'] ?? 'Ordinary');

        $sheet->setCellValue("E{$row}", $this->hhmm($record->shift_start) . '-' . $this->hhmm($record->shift_end));
        $sheet->setCellValue("F{$row}", $this->hhmm($record->clock_in));
        $sheet->setCellValue("G{$row}", $this->hhmm($record->clock_out));
        $sheet->setCellValue("H{$row}", $record->ot_in ? $this->hhmm($record->ot_in) : '');
        $sheet->setCellValue("I{$row}", $record->ot_out ? $this->hhmm($record->ot_out) : '');

        // Rates: the daily rate is the only typed-in figure; the rest derive
        // from it in the sheet exactly as they do in the calculator.
        $sheet->setCellValue("J{$row}", $this->dailyRate($employee));
        $sheet->setCellValue("K{$row}", "=J{$row}/8");
        $sheet->setCellValue("L{$row}", (float) ($pay['premium_multiplier'] ?? 1.0));
        $sheet->setCellValue("M{$row}", "=K{$row}*L{$row}");

        $sheet->setCellValue("N{$row}", (float) $pay['regular_hours']);
        $sheet->setCellValue("O{$row}", "=ROUND(N{$row}*M{$row},2)");

        $sheet->setCellValue("P{$row}", (float) $pay['ot_hours']);
        $sheet->setCellValue("Q{$row}", (float) ($pay['ot_rate'] ?? 0.0));
        $sheet->setCellValue("R{$row}", "=ROUND(P{$row}*Q{$row},2)");

        $sheet->setCellValue("S{$row}", (float) $pay['night_diff_hours']);
        $sheet->setCellValue("T{$row}", "=M{$row}*{$this->nightDiffMultiplier}");
        $sheet->setCellValue("U{$row}", "=ROUND(S{$row}*T{$row},2)");

        $sheet->setCellValue("V{$row}", (int) $pay['late_minutes']);
        $sheet->setCellValue("W{$row}", "=ROUND(V{$row}/60*M{$row},2)");

        $sheet->setCellValue("X{$row}", (int) $pay['undertime_minutes']);
        $sheet->setCellValue("Y{$row}", (float) $pay['overbreak_hours']);
        $sheet->setCellValue("Z{$row}", "=ROUND((X{$row}/60+Y{$row})*M{$row},2)");

        $sheet->setCellValue(self::LAST . $row, "=O{$row}+R{$row}+U{$row}-W{$row}-Z{$row}");

        // An overtime pair longer than the shift it follows is the shape of an
        // AM/PM slip, and it has been the true cause more than once. Tint it so
        // the reviewer's eye lands there instead of on the arithmetic.
        if ($this->overtimeLooksMisKeyed($pay)) {
            $this->fill($sheet, "H{$row}:I{$row}", self::FLAG);
            $sheet->getStyle("H{$row}:I{$row}")->getFont()->setBold(true);
            $sheet->getComment("H{$row}")->getText()->createTextRun(
                "Overtime here is longer than a full shift. Worth confirming the OT Out time "
                . 'is PM/AM as intended before this figure is relied on.',
            );
        }
    }

    /** More overtime in a day than a whole shift is worth a second look. */
    private function overtimeLooksMisKeyed(array $pay): bool {
        return (float) $pay['ot_hours'] > 8.0;
    }

    private function subtotal(Worksheet $sheet, int $row, int $first, int $last): void {
        $sheet->setCellValue("A{$row}", 'TOTAL');
        $sheet->setCellValue("D{$row}", ($last - $first + 1) . ' day(s)');

        foreach (['N', 'O', 'P', 'R', 'S', 'U', 'V', 'W', 'X', 'Y', 'Z', self::LAST] as $letter) {
            $sheet->setCellValue("{$letter}{$row}", "=SUM({$letter}{$first}:{$letter}{$last})");
        }

        $range = "A{$row}:" . self::LAST . $row;
        $this->fill($sheet, $range, self::SUBTOTAL);
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
    }

    private function styleBody(Worksheet $sheet, int $first, int $last): void {
        if ($last < $first) {
            return;
        }

        foreach (self::COLUMNS as $letter => [, , , $format]) {
            if ($format !== null) {
                $sheet->getStyle("{$letter}{$first}:{$letter}{$last}")
                    ->getNumberFormat()->setFormatCode($format);
            }
        }

        $sheet->getStyle("C{$first}:I{$last}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A' . $first . ':' . self::LAST . $last)
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('BFCBDD');
        $sheet->getStyle(self::LAST . $first . ':' . self::LAST . $last)->getFont()->setBold(true);
    }

    // ----------------------------------------------------------------- rules

    /**
     * The rules in words, on their own sheet.
     *
     * The figures are only checkable against a stated rule, and the rules have
     * moved — the daily rate became flat in September 2026 — so a sheet sent
     * for confirmation has to carry the rule it was computed under.
     */
    private function buildRules(Worksheet $sheet): void {
        $ot = rtrim(rtrim(number_format($this->overtimeMultiplier, 2), '0'), '.');
        $nd = rtrim(rtrim(number_format($this->nightDiffMultiplier * 100, 2), '0'), '.');

        $lines = [
            ['HOW EACH DAY IS COMPUTED', ''],
            ['', ''],
            ['Basic wage', 'The daily rate is FIXED. A day worked pays the rate in full whether the shift is scheduled 8, 9 or 10 hours. The hourly rate is the daily rate divided by 8.'],
            ['', 'Column O  =  Reg Hrs (N)  x  Paid /hr (M)'],
            ['', ''],
            ['Overtime', 'Overtime starts at the SCHEDULED SHIFT END, and is paid at ' . $ot . 'x the hourly rate on an ordinary day. On a holiday or rest day it is 130% of that day\'s premium hourly rate.'],
            ['', 'Column R  =  OT Hrs (P)  x  OT /hr (Q)'],
            ['', 'Overtime under ' . (int) $this->minimumOtMinutes . ' minutes in a day is not paid at all.'],
            ['', ''],
            ['OT In / OT Out', 'A SECOND clock pair, used when staff clock out at shift end and clock back in later — unloading a delivery, for example. Its hours are added to the overtime and can run past midnight. This pair does not appear on the payslip screen, which is why it has its own columns here.'],
            ['', ''],
            ['Night differential', 'Paid on hours falling between 22:00 and 06:00, from both the main span and the overtime pair, at +' . $nd . '% of the day\'s hourly rate.'],
            ['', 'Column U  =  ND Hrs (S)  x  ND /hr (T)'],
            ['', ''],
            ['Late and undertime', 'The day is paid in full and the time missed is charged back separately, so the deduction is itemised instead of quietly shrinking the basic. A break taken longer than the standard one is charged the same way, as Overbreak.'],
            ['', 'Column W  =  Late Min (V) / 60  x  Paid /hr (M)'],
            ['', 'Column Z  =  (UT Min (X) / 60 + Overbreak Hrs (Y))  x  Paid /hr (M)'],
            ['', ''],
            ['Day multiplier', 'Ordinary 1.00  |  Rest day 1.30  |  Special holiday 1.30  |  Special holiday on a rest day 1.50  |  Regular holiday 2.00  |  Regular holiday on a rest day 2.60'],
            ['', ''],
            ['Day total', 'Column AA  =  Basic + OT Pay + ND Pay  -  Late Ded.  -  UT Ded.'],
            ['', 'This is earnings only. Cash advances, late penalties, SSS, PhilHealth and Pag-IBIG are deducted on the payslip, not here.'],
            ['', ''],
            ['Highlighted cells', 'A tinted OT In / OT Out means that day records more than 8 hours of overtime. That is usually a mis-keyed AM/PM rather than real work, and is worth confirming before the figure is relied on.'],
        ];

        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(112);

        $row = 1;
        foreach ($lines as [$label, $text]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $text);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("B{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            ++$row;
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::NAVY);
        // The formula lines read as code, so they get a fixed-width face.
        $sheet->getStyle("B1:B{$row}")->getFont()->setSize(10);
    }

    // ---------------------------------------------------------------- helpers

    /** The employee's own rate, or the payroll default — the same fallback the calculator applies. */
    private function dailyRate(\App\Models\Employee $employee): float {
        return $employee->daily_basic_rate === null
            ? $this->defaultDailyRate
            : (float) $employee->daily_basic_rate;
    }

    private function hhmm(mixed $time): string {
        return $time === null ? '' : substr((string) $time, 0, 5);
    }

    private function banner(Worksheet $sheet, string $subtitle): void {
        $last = self::LAST;

        $sheet->mergeCells("A1:{$last}1");
        $sheet->setCellValue('A1', 'PAY COMPUTATION — DAY BY DAY');
        $this->fill($sheet, "A1:{$last}1", self::NAVY);
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$last}1")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $sheet->mergeCells("A2:{$last}2");
        $sheet->setCellValue('A2', $subtitle);
        $this->fill($sheet, "A2:{$last}2", self::NAVY_SOFT);
        $sheet->getStyle("A2:{$last}2")->getFont()->setSize(9)->getColor()->setRGB('DCE4F2');
        $sheet->getStyle("A2:{$last}2")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(17);

        $sheet->getStyle("A1:{$last}2")->getAlignment()->setIndent(1);
    }

    private function fill(Worksheet $sheet, string $range, string $rgb): void {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }
}
