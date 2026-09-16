<?php

namespace App\Console\Commands;

use App\Models\Branch;
use Illuminate\Console\Command;

/**
 * Sets the name and address a branch's payslips are headed with.
 *
 * Kanto Cravings trades under its own name at its own address, so its staff
 * are not headed as the Ongkoleyt branches are. There is no branch screen to
 * edit this from, and hardcoding the next one would put us back where we
 * started, so it lives here.
 *
 * With no options it lists what every branch currently prints.
 */
class SetBranchCompany extends Command {
    protected $signature = 'payroll:branch-company
                            {branch? : branch name, or part of one}
                            {--name= : the company name to print}
                            {--address= : the address to print}
                            {--reset : go back to the default heading}';

    protected $description = "Show or set the company heading printed on a branch's payslips";

    public function handle(): int {
        $needle = $this->argument('branch');

        if ($needle === null) {
            $this->listAll();

            return self::SUCCESS;
        }

        $matches = Branch::where('name', 'like', "%{$needle}%")->orderBy('name')->get();

        if ($matches->isEmpty()) {
            $this->error("No branch matched \"{$needle}\".");

            return self::FAILURE;
        }
        if ($matches->count() > 1) {
            $this->error("\"{$needle}\" matched " . $matches->count() . ' branches: ' . $matches->pluck('name')->join(', '));

            return self::FAILURE;
        }

        $branch = $matches->first();

        if ($this->option('reset')) {
            $branch->update(['company_name' => null, 'company_address' => null]);
            $this->info("{$branch->name} is back to the default heading.");
            $this->showOne($branch->fresh());

            return self::SUCCESS;
        }

        $name = $this->option('name');
        $address = $this->option('address');

        if ($name === null && $address === null) {
            $this->showOne($branch);
            $this->line('  Pass --name and --address to change it, or --reset for the default.');

            return self::SUCCESS;
        }

        // Each is set only when given, so an address can be corrected without
        // retyping the name.
        $branch->update(array_filter([
            'company_name' => $name,
            'company_address' => $address,
        ], fn ($value) => $value !== null));

        $this->info("Updated {$branch->name}.");
        $this->showOne($branch->fresh());

        return self::SUCCESS;
    }

    private function listAll(): void {
        $this->newLine();
        foreach (Branch::orderBy('name')->get() as $branch) {
            $this->showOne($branch);
        }
        $this->newLine();
        $this->line('  Branches with no heading of their own print the default:');
        $this->line('    ' . Branch::DEFAULT_COMPANY_NAME);
        $this->line('    ' . Branch::DEFAULT_COMPANY_ADDRESS);
    }

    private function showOne(Branch $branch): void {
        $heading = $branch->payslipHeading();
        $own = $branch->company_name !== null || $branch->company_address !== null;

        $this->line(sprintf('  <comment>%s</comment>%s', $branch->name, $own ? '' : '  (default)'));
        $this->line('    ' . $heading['name']);
        $this->line('    ' . $heading['address']);
    }
}
