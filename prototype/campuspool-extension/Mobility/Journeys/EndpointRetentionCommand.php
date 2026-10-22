<?php

namespace STS\Mobility\Journeys;

use Illuminate\Console\Command;

final class EndpointRetentionCommand extends Command
{
    protected $signature = 'mobility:redact-private-endpoints {--limit=100}';
    protected $description = 'Erase expired private endpoint coordinates while retaining opaque identity and request hashes';
    public function handle(PrivateEndpointResolver $resolver): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) return self::INVALID;
        $count = $resolver->redactExpired($limit);
        $this->info('Redacted expired endpoints: '.$count);
        return self::SUCCESS;
    }
}
