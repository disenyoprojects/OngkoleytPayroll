<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The payroll summary workbook the client keeps: a Dashboard sheet of headline
 * figures and per-branch totals, and a Payroll Summary sheet listing every
 * employee under the banded headings they read it by — earnings, time
 * adjustments, deductions, statutory, reference, then the net.
 *
 * The layout is deliberate, not decorative: the client prints these and reads
 * them side by side, so the column order, the banding and the "-" for nothing
 * all have to survive the round trip into Excel.
 */
class PayrollSummaryWorkbook {
    /** Zero reads as "-" and negatives as red parentheses, the way the client writes them by hand. */
    private const MONEY = '#,##0.00;[Red](#,##0.00);"-"';
    private const COUNT = '#,##0.##;[Red](#,##0.##);"-"';
    private const PERCENT = '0.0%';

    private const NAVY = '1F3864';
    private const NAVY_SOFT = '2E4B7C';
    private const ROW_ALT = 'EDF2F9';
    private const BRANCH_TEXT = '8497B0';
    private const RULE = 'BFCBDD';

    /** Heading band => fill colour, in the order the client groups the columns. */
    private const BAND_TIME = '7F6000';
    private const BAND_DEDUCTION = '953735';
    private const BAND_STATUTORY = '31593F';
    private const BAND_REFERENCE = '44546A';

    /**
     * @param array<int, array> $slips payslips as built by PayslipController::buildPayslip
     * @param array{label: string, from: string, to: string} $window
     */
    public function __construct(private array $slips, private array $window) {}

    public function build(): Spreadsheet {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $dashboard = $book->getActiveSheet();
        $dashboard->setTitle('Dashboard');
        $this->buildDashboard($dashboard);

        $summary = $book->createSheet();
        $summary->setTitle('Payroll Summary');
        $this->buildSummary($summary);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ---------------------------------------------------------------- summary

    /** Employee rows, in the client's column order. Column letter => [heading, band colour]. */
    private function summaryColumns(): array {
        return [
            'A' => ['Employee', self::NAVY],
            'B' => ['Branch', self::NAVY],
            'C' => ['NSD', self::NAVY],
            'D' => ['Late', self::BAND_TIME],
            'E' => ['SH', self::BAND_TIME],
            'F' => ['RH', self::BAND_TIME],
            'G' => ['UT', self::BAND_TIME],
            // The total sits to the RIGHT of the two columns it sums, the way
            // the client reads the sheet: late deduction, CA, then the total.
            'H' => ['Penalty Lates', self::BAND_DEDUCTION],
            'I' => ['CA etc', self::BAND_DEDUCTION],
            'J' => ['Total Auth. Ded.', self::BAND_DEDUCTION],
            'K' => ['SSS', self::BAND_STATUTORY],
            'L' => ['PhilHealth', self::BAND_STATUTORY],
            'M' => ['Pag-IBIG', self::BAND_STATUTORY],
            'N' => ['Days Worked', self::BAND_REFERENCE],
            'O' => ['Rice Allowance', self::BAND_REFERENCE],
            'P' => ['Daily Rate', self::BAND_REFERENCE],
            'Q' => ['Gross', self::NAVY],
            'R' => ['Net Pay', self::NAVY],
        ];
    }

    private function buildSummary(Worksheet $sheet): void {
        $columns = $this->summaryColumns();
        $gross = $this->sum('gross');
        $net = $this->sum('net_to_release');

        $this->banner(
            $sheet,
            'A1:R2',
            'PAYROLL SUMMARY',
            sprintf(
                'Pay period: %s     |     %d employees     |     Gross PHP %s     |     Net PHP %s',
                $this->window['label'],
                count($this->slips),
                number_format($gross, 2),
                number_format($net, 2),
            ),
        );

        $sheet->getRowDimension(3)->setRowHeight(5);

        // Row 4 carries the group bands, row 5 the column headings under them.
        foreach ([
            ['A', 'B', 'EMPLOYEE', self::NAVY],
            ['C', 'C', '', self::NAVY],
            ['D', 'G', 'TIME ADJUSTMENTS', self::BAND_TIME],
            ['H', 'J', 'DEDUCTIONS', self::BAND_DEDUCTION],
            ['K', 'M', 'STATUTORY', self::BAND_STATUTORY],
            ['N', 'P', 'REFERENCE', self::BAND_REFERENCE],
            ['Q', 'R', 'SUMMARY', self::NAVY],
        ] as [$from, $to, $label, $colour]) {
            $range = "{$from}4:{$to}4";
            $sheet->mergeCells($range);
            $sheet->setCellValue("{$from}4", $label);
            $this->fill($sheet, $range, $colour);
            $sheet->getStyle($range)->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($range)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        }

        foreach ($columns as $letter => [$heading, $colour]) {
            $sheet->setCellValue("{$letter}5", $heading);
            $this->fill($sheet, "{$letter}5", $colour);
            $sheet->getStyle("{$letter}5")->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("{$letter}5")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
        }
        $sheet->getRowDimension(4)->setRowHeight(18);
        $sheet->getRowDimension(5)->setRowHeight(26);

        $row = 6;
        foreach ($this->slips as $slip) {
            foreach (array_values($this->summaryRow($slip)) as $offset => $value) {
                $sheet->setCellValue(array_keys($columns)[$offset] . $row, $value);
            }
            // Banding starts on the second employee, so the first row reads clean under the headings.
            if ($row % 2 === 1) {
                $this->fill($sheet, "A{$row}:R{$row}", self::ROW_ALT);
            }
            ++$row;
        }
        $first = 6;
        $last = $row - 1;

        $this->summaryTotals($sheet, $row, $first, $last);
        $this->styleSummaryBody($sheet, $first, $row);

        foreach ([
            'A' => 26, 'B' => 19, 'C' => 9.5, 'D' => 9.5, 'E' => 8, 'F' => 8, 'G' => 10,
            'H' => 11, 'I' => 9.5, 'J' => 12.5, 'K' => 10, 'L' => 11, 'M' => 10,
            'N' => 11.5, 'O' => 11.5, 'P' => 10.5, 'Q' => 12, 'R' => 12,
        ] as $letter => $width) {
            $sheet->getColumnDimension($letter)->setWidth($width);
        }

        $sheet->freezePane('C6');
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setPrintArea("A1:R{$row}");
        $sheet->setPrintGridlines(false);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4, 5);
        $sheet->setShowGridlines(false);
    }

    /** One employee's row, keyed by column letter, in the order summaryColumns() lists them. */
    private function summaryRow(array $slip): array {
        $t = $slip['totals'];

        return [
            'A' => $slip['employee']['full_name'],
            'B' => $slip['employee']['branch'] ?? '',
            'C' => $t['night_diff'],
            'D' => $t['tardiness'],
            'E' => $t['sh'],
            'F' => $t['rh'],
            'G' => $t['undertime'],
            'H' => $t['penalty_late'],
            'I' => $t['cash_advance'],
            'J' => $t['auth_deductions'],
            'K' => $t['sss'],
            'L' => $t['philhealth'],
            'M' => $t['pagibig'],
            'N' => $slip['slip']['days_worked'],
            'O' => $t['rice_allowance'],
            'P' => (float) $slip['employee']['daily_rate'],
            'Q' => $t['gross'],
            'R' => $t['net_to_release'],
        ];
    }

    private function summaryTotals(Worksheet $sheet, int $row, int $first, int $last): void {
        $sheet->setCellValue("A{$row}", 'TOTAL');

        foreach (range('C', 'R') as $letter) {
            // Daily Rate is a rate, not a quantity: totalling it is meaningless,
            // so the client's sheet shows the rate itself when everyone is on
            // one, and leaves the cell empty when they are not.
            if ($letter === 'P') {
                $rates = array_unique(array_map(fn ($s) => (float) $s['employee']['daily_rate'], $this->slips));
                if (count($rates) === 1) {
                    $sheet->setCellValue("P{$row}", reset($rates));
                }
                continue;
            }
            if ($last >= $first) {
                $sheet->setCellValue("{$letter}{$row}", "=SUM({$letter}{$first}:{$letter}{$last})");
            }
        }

        $this->fill($sheet, "A{$row}:R{$row}", self::NAVY);
        $sheet->getStyle("A{$row}:R{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("C{$row}:R{$row}")->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle("N{$row}")->getNumberFormat()->setFormatCode(self::COUNT);
        $sheet->getRowDimension($row)->setRowHeight(18);
    }

    private function styleSummaryBody(Worksheet $sheet, int $first, int $totalRow): void {
        $last = $totalRow - 1;
        if ($last < $first) {
            return;
        }

        $sheet->getStyle("C{$first}:R{$last}")->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle("N{$first}:N{$last}")->getNumberFormat()->setFormatCode(self::COUNT);
        $sheet->getStyle("C{$first}:R{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("A{$first}:A{$last}")->getFont()->setBold(true)->getColor()->setRGB('203864');
        $sheet->getStyle("B{$first}:B{$last}")->getFont()->getColor()->setRGB(self::BRANCH_TEXT);
        $sheet->getStyle("A{$first}:R{$last}")->getBorders()->getVertical()
            ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB(self::RULE);
        $sheet->getStyle("A{$first}:R{$last}")->getBorders()->getHorizontal()
            ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB(self::RULE);
    }

    // -------------------------------------------------------------- dashboard

    private function buildDashboard(Worksheet $sheet): void {
        $branches = $this->branchTotals();
        $gross = $this->sum('gross');
        $net = $this->sum('net_to_release');

        $this->banner($sheet, 'A1:L2', 'PAYROLL DASHBOARD', 'Pay period: ' . $this->window['label'] . '     |     Semi-monthly');
        $sheet->getRowDimension(3)->setRowHeight(8);

        foreach ([
            ['B', 'C', 'TOTAL GROSS', $gross, '217346', 'E4F0E8', '1B6B3A', self::MONEY],
            ['E', 'F', 'TOTAL NET PAY', $net, self::NAVY, 'E7EDF7', self::NAVY, self::MONEY],
            ['H', 'I', 'TOTAL DEDUCTIONS', $gross - $net, 'A5342C', 'FAE7E5', 'B03A2E', self::MONEY],
            ['K', 'L', 'HEADCOUNT', count($this->slips), '5B3E8E', 'EEE9F6', '5B3E8E', '0'],
        ] as [$from, $to, $label, $value, $accent, $body, $text, $format]) {
            $head = "{$from}4:{$to}4";
            $sheet->mergeCells($head);
            $sheet->setCellValue("{$from}4", $label);
            $this->fill($sheet, $head, $accent);
            $sheet->getStyle($head)->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($head)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

            $cell = "{$from}5:{$to}5";
            $sheet->mergeCells($cell);
            $sheet->setCellValue("{$from}5", $value);
            $this->fill($sheet, $cell, $body);
            $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(16)->getColor()->setRGB($text);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle($cell)->getBorders()->getOutline()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($accent);
        }
        $sheet->getRowDimension(4)->setRowHeight(20);
        $sheet->getRowDimension(5)->setRowHeight(34);
        $sheet->getRowDimension(6)->setRowHeight(8);

        $this->branchTable($sheet, $branches, $net);
        $this->chartSource($sheet, $branches);
        $this->netPayChart($sheet, count($branches));

        foreach ([
            'A' => 2, 'B' => 17, 'C' => 13, 'D' => 2, 'E' => 15, 'F' => 15, 'G' => 2,
            'H' => 15, 'I' => 15, 'J' => 2, 'K' => 15, 'L' => 15, 'M' => 3, 'N' => 20, 'O' => 14,
        ] as $letter => $width) {
            $sheet->getColumnDimension($letter)->setWidth($width);
        }

        $sheet->setShowGridlines(false);
        $sheet->setSelectedCell('A1');
    }

    /**
     * The seven table columns are merged across the thin gap columns that give
     * the cards above their spacing, so the table still reads as one grid.
     *
     * @return array<int, string> merge range per column, left to right
     */
    private function tableRanges(int $row): array {
        return ["B{$row}:C{$row}", "D{$row}:E{$row}", "F{$row}:G{$row}", "H{$row}", "I{$row}:J{$row}", "K{$row}", "L{$row}"];
    }

    private function branchTable(Worksheet $sheet, array $branches, float $net): void {
        $header = 7;
        $headings = ['Branch', 'Headcount', 'Days Worked', 'Gross Pay', 'Deductions', 'Net Pay', '% of Net'];

        foreach ($this->tableRanges($header) as $i => $range) {
            $this->put($sheet, $range, $headings[$i]);
        }
        $this->fill($sheet, "B{$header}:L{$header}", self::NAVY);
        $sheet->getStyle("B{$header}:L{$header}")->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("B{$header}:L{$header}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($header)->setRowHeight(22);

        $row = $header + 1;
        foreach ($branches as $branch) {
            $share = $net == 0.0 ? 0.0 : $branch['net'] / $net;
            foreach ($this->tableRanges($row) as $i => $range) {
                $this->put($sheet, $range, [
                    $branch['name'], $branch['headcount'], $branch['days'],
                    $branch['gross'], $branch['gross'] - $branch['net'], $branch['net'], $share,
                ][$i]);
            }
            if ($row % 2 === 1) {
                $this->fill($sheet, "B{$row}:L{$row}", self::ROW_ALT);
            }
            ++$row;
        }
        $last = $row - 1;

        foreach ($this->tableRanges($row) as $i => $range) {
            $this->put($sheet, $range, [
                'TOTAL',
                array_sum(array_column($branches, 'headcount')),
                array_sum(array_column($branches, 'days')),
                array_sum(array_column($branches, 'gross')),
                array_sum(array_column($branches, 'gross')) - $net,
                $net,
                $net == 0.0 ? 0.0 : 1.0,
            ][$i]);
        }
        $this->fill($sheet, "B{$row}:L{$row}", self::NAVY);
        $sheet->getStyle("B{$row}:L{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getRowDimension($row)->setRowHeight(20);

        if ($last >= $header + 1) {
            $sheet->getStyle("B" . ($header + 1) . ":C{$last}")->getFont()->getColor()->setRGB('203864');
            $sheet->getStyle("B" . ($header + 1) . ":L{$last}")->getBorders()->getHorizontal()
                ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB(self::RULE);
        }
        // Formats run from the first body row through the TOTAL row.
        $body = $header + 1;
        $sheet->getStyle("D{$body}:G{$row}")->getNumberFormat()->setFormatCode(self::COUNT);
        $sheet->getStyle("H{$body}:K{$row}")->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle("L{$body}:L{$row}")->getNumberFormat()->setFormatCode(self::PERCENT);
        $sheet->getStyle("D{$body}:L{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("B{$body}:L{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("B{$header}:L{$row}")->getBorders()->getOutline()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::NAVY);
    }

    /** The chart has to read from real cells, so the series sits beside the table. */
    private function chartSource(Worksheet $sheet, array $branches): void {
        $sheet->setCellValue('N6', 'Chart source data');
        $sheet->getStyle('N6')->getFont()->setItalic(true)->setSize(8)->getColor()->setRGB('8497B0');
        $sheet->setCellValue('N7', 'Branch');
        $sheet->setCellValue('O7', 'Net Pay');
        $sheet->getStyle('N7:O7')->getFont()->setBold(true)->getColor()->setRGB(self::BRANCH_TEXT);

        $row = 8;
        foreach ($branches as $branch) {
            $sheet->setCellValue("N{$row}", $branch['name']);
            $sheet->setCellValue("O{$row}", $branch['net']);
            ++$row;
        }
        $sheet->getStyle('N8:O' . max(8, $row - 1))->getFont()->getColor()->setRGB(self::BRANCH_TEXT);
        $sheet->getStyle('O8:O' . max(8, $row - 1))->getNumberFormat()->setFormatCode(self::MONEY);
    }

    private function netPayChart(Worksheet $sheet, int $count): void {
        if ($count === 0) {
            return;
        }
        $last = 7 + $count;

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Dashboard'!\$O\$7", null, 1)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Dashboard'!\$N\$8:\$N\${$last}", null, $count)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Dashboard'!\$O\$8:\$O\${$last}", null, $count, null, null, '2E5F9E')],
            DataSeries::DIRECTION_BAR,
        );

        $labels = new Layout();
        $labels->setShowVal(true);
        $labels->setShowSerName(false);
        $labels->setShowCatName(false);
        $labels->setShowLegendKey(false);
        $labels->setShowPercent(false);

        $title = new Title('Net Pay by Branch');
        $chart = new Chart('net-pay-by-branch', $title, null, new PlotArea($labels, [$series]));

        // Anchored two rows under the table so the chart never covers the totals.
        $top = $last + 3;
        $chart->setTopLeftPosition("B{$top}");
        $chart->setBottomRightPosition('L' . ($top + 17));

        $sheet->addChart($chart);
    }

    /** Per-branch headcount, days and money, in the order the branches first appear. */
    private function branchTotals(): array {
        $branches = [];

        foreach ($this->slips as $slip) {
            $name = $slip['employee']['branch'] ?? 'Unassigned';
            $branches[$name] ??= ['name' => $name, 'headcount' => 0, 'days' => 0.0, 'gross' => 0.0, 'net' => 0.0];
            ++$branches[$name]['headcount'];
            $branches[$name]['days'] += (float) $slip['slip']['days_worked'];
            $branches[$name]['gross'] += (float) $slip['totals']['gross'];
            $branches[$name]['net'] += (float) $slip['totals']['net_to_release'];
        }

        return array_values($branches);
    }

    // ----------------------------------------------------------------- helpers

    private function sum(string $key): float {
        return round(array_sum(array_map(fn ($slip) => (float) $slip['totals'][$key], $this->slips)), 2);
    }

    /** The dark title block both sheets open with: name on top, pay period under it. */
    private function banner(Worksheet $sheet, string $range, string $title, string $subtitle): void {
        [$topLeft, $bottomRight] = explode(':', $range);
        $left = preg_replace('/\d+/', '', $topLeft);
        $right = preg_replace('/\d+/', '', $bottomRight);

        $sheet->mergeCells("{$left}1:{$right}1");
        $sheet->setCellValue("{$left}1", $title);
        $this->fill($sheet, "{$left}1:{$right}1", self::NAVY);
        $sheet->getStyle("{$left}1:{$right}1")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("{$left}1:{$right}1")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $sheet->mergeCells("{$left}2:{$right}2");
        $sheet->setCellValue("{$left}2", $subtitle);
        $this->fill($sheet, "{$left}2:{$right}2", self::NAVY_SOFT);
        $sheet->getStyle("{$left}2:{$right}2")->getFont()->setSize(9)->getColor()->setRGB('DCE4F2');
        $sheet->getStyle("{$left}2:{$right}2")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(17);

        $sheet->getStyle("{$left}1:{$right}2")->getAlignment()->setIndent(1);
    }

    private function fill(Worksheet $sheet, string $range, string $rgb): void {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }

    /** Set a value into a (possibly merged) range, merging it first. */
    private function put(Worksheet $sheet, string $range, mixed $value): void {
        if (str_contains($range, ':')) {
            $sheet->mergeCells($range);
        }
        $sheet->setCellValue(explode(':', $range)[0], $value);
    }
}
