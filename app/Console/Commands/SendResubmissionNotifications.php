<?php

namespace App\Console\Commands;

use App\Domain\Identity\AdvertisementResubmissionService;
use App\Mail\ResubmissionInvitation;
use App\Models\ResubmissionCandidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendResubmissionNotifications extends Command
{
    protected $signature = 'resubmissions:send-notifications {--limit=100 : Maximum pending notifications per run}';

    protected $description = 'Send pending resubmission emails using the configured SMTP mailer';

    public function handle(AdvertisementResubmissionService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 1000.');

            return self::FAILURE;
        }
        if (config('mail.default') !== 'smtp' || config('mail.mailers.smtp.transport') !== 'smtp'
            || blank(config('mail.mailers.smtp.host')) || filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Configure the SMTP mailer before sending resubmission notifications.');

            return self::FAILURE;
        }
        $ids = ResubmissionCandidate::query()->whereIn('notification_status', ['pending', 'failed'])
            ->where('attempts', '<', 3)->orderBy('id')->limit($limit)->pluck('id');
        $sent = $failed = 0;
        foreach ($ids as $id) {
            $status = DB::transaction(function () use ($id, $service): string {
                $candidate = ResubmissionCandidate::query()->whereKey($id)->lockForUpdate()->first();
                if (! $candidate || ! in_array($candidate->notification_status, ['pending', 'failed'], true) || $candidate->attempts >= 3) {
                    return 'unchanged';
                }
                $window = $service->windows()->whereKey($candidate->resubmission_id)->first();
                if (! $window || $window->adv_resub_status !== 1 || $window->adv_resub_chk_stat === 1
                    || $window->adv_resub_endtime->format('Y-m-d H:i:s') < now('Asia/Kolkata')->format('Y-m-d H:i:s')) {
                    $candidate->notification_status = 'skipped';
                    $candidate->save();

                    return 'skipped';
                }
                if (filter_var($candidate->email, FILTER_VALIDATE_EMAIL) === false) {
                    $candidate->notification_status = 'invalid';
                    $candidate->save();

                    return 'invalid';
                }
                $candidate->attempts++;
                try {
                    Mail::mailer('smtp')->to($candidate->email)->send(new ResubmissionInvitation(
                        $window->advertisement?->adv_no ?? $window->adv_resub_master, $window->post_name ?? '',
                        $window->adv_resub_starttime->format('Y-m-d H:i:s'), $window->adv_resub_endtime->format('Y-m-d H:i:s'), $window->adv_resub_reasons,
                    ));
                    $candidate->notification_status = 'sent';
                    $candidate->notified_at = now('Asia/Kolkata')->format('Y-m-d H:i:s');
                } catch (Throwable) {
                    $candidate->notification_status = 'failed';
                }
                $candidate->save();

                return $candidate->notification_status;
            });
            $sent += (int) ($status === 'sent');
            $failed += (int) ($status === 'failed');
        }
        $this->info("Sent: {$sent}; failed: {$failed}. Failed deliveries are retried on a later run, up to three attempts.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
