<?php

namespace App\Console\Commands;

use App\Services\IdempotencyPruner;
use Illuminate\Console\Command;

class PrunePlatformIdempotencies extends Command
{
    protected $signature = 'platform:prune-idempotencies
        {--apply : Delete only completed records expired at the fixed invocation cutoff}
        {--batch-size=100 : Maximum rows per short transaction, 1-1000}
        {--max-batches=10 : Maximum transactions per invocation, 1-1000}
        {--scheduled : Noninteractive apply only when explicitly enabled in deployment configuration}';

    protected $description = 'Preview or prune expired platform idempotency responses with platform-first SKIP LOCKED batches';

    public function handle(IdempotencyPruner $pruner): int
    {
        $size = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $batches = filter_var($this->option('max-batches'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($size === false || $batches === false) {
            $this->error('Batch size and max batches must each be 1-1000.');
            return self::FAILURE;
        }
        if ($this->option('scheduled') && (! $this->option('apply') || config('platform_api.prune_scheduled') !== true)) {
            $this->error('Scheduled apply requires explicit deployment opt-in and --apply.');
            return self::FAILURE;
        }
        if ($this->option('apply') && ! $this->option('scheduled') && ! $this->input->isInteractive()) {
            $this->error('Manual apply requires interactive confirmation.');
            return self::FAILURE;
        }
        $connection = $pruner->connection();
        $cutoff = $pruner->cutoff();
        $preview = $pruner->preview($cutoff);
        $database = $connection->getDatabaseName();
        $this->line('Environment: ' . app()->environment() . '; connection: ' . $connection->getName()
            . '; host: ' . $connection->getConfig('host') . '; database: ' . $database);
        $this->line('UTC cutoff: ' . $cutoff->toISOString() . '; eligible: ' . $preview['count'] . '; maximum deletions: ' . ($size * $batches));
        $this->table(['Record ID', 'Platform ID', 'Expires at UTC'], $preview['sample']);
        if (! $this->option('apply')) {
            $this->info('Dry run. No records deleted.');
            return self::SUCCESS;
        }
        if (! $this->option('scheduled')) {
            $this->warn('Only expired completed response records will be deleted; deleted snapshots require a backup to recover.');
            $confirmation = 'DELETE EXPIRED ' . $database;
            if ($this->ask('Type exactly: ' . $confirmation) !== $confirmation) {
                $this->error('Confirmation did not match. Nothing deleted.');
                return self::FAILURE;
            }
        }
        $deleted = 0;
        for ($batch = 0; $batch < $batches; $batch++) {
            $count = $pruner->batch($cutoff, $size);
            $deleted += $count;
            if ($count === 0) {
                break;
            }
        }
        $this->info('Deleted ' . $deleted . ' expired records. Busy platforms/records were skipped; unexpired and processing records were preserved.');
        return self::SUCCESS;
    }
}
