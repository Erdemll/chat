<?php

namespace App\Console\Commands;

use App\Services\Notifications\MentionEmailDigestService;
use Illuminate\Console\Command;

class SendMentionEmailDigests extends Command
{
    protected $signature = 'mentions:send-email-digests';

    protected $description = 'Send one email digest per user for overdue unread mentions';

    public function handle(MentionEmailDigestService $service): int
    {
        $summary = $service->run();
        $this->info(sprintf(
            'users_processed=%d emails_sent=%d mentions_processed=%d failed=%d',
            $summary['users_processed'],
            $summary['emails_sent'],
            $summary['mentions_processed'],
            $summary['failed'],
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
