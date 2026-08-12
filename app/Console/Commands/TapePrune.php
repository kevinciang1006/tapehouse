<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;

final class TapePrune extends Command
{
    protected $signature = 'tape:prune';

    protected $description = 'Delete ticks older than the retention window';

    public function handle(Config $config, ConnectionInterface $db): int
    {
        $hours = (int) $config->get('tapehouse.ticks.retention_hours');
        $cutoff = CarbonImmutable::now()->subHours($hours);

        $deleted = $db->table('ticks')
            ->where('quoted_at', '<', $cutoff->format('Y-m-d H:i:s.uP'))
            ->delete();

        $this->info(sprintf('pruned %d ticks older than %dh', $deleted, $hours));

        return self::SUCCESS;
    }
}
