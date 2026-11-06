<?php

namespace App\Domain\Identity;

use App\Models\AdvertisementDraft;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdvertisementDraftService
{
    public function create(array $data, int $staffId): AdvertisementDraft
    {
        $payload = AdvertisementPayload::validate(AdvertisementPayload::decodeIncoming($data));
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $reference = $this->generateReference();
            if (AdvertisementDraft::query()->where('reference', $reference)->exists()
                || DB::table('advertisement_master')->where('adv_auto_genno', $reference)->exists()) {
                continue;
            }
            $draft = new AdvertisementDraft;
            $draft->reference = $reference;
            $draft->state = 'draft';
            $draft->payload = $payload;
            $draft->version = 1;
            $draft->created_by = $staffId;
            try {
                DB::transaction(function () use ($draft): void {
                    $draft->save();
                });

                return $draft;
            } catch (UniqueConstraintViolationException $error) {
                if (! AdvertisementDraft::query()->where('reference', $reference)->exists()) {
                    throw $error;
                }
            }
        }
        abort(503, 'Could not generate a unique advertisement reference. Please retry.');
    }

    protected function generateReference(): string
    {
        return 'A'.now('Asia/Kolkata')->format('dmYhis').str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function update(AdvertisementDraft $draft, array $data, int $version, int $staffId): AdvertisementDraft
    {
        $incoming = AdvertisementPayload::decodeIncoming($data);

        return DB::transaction(function () use ($draft, $incoming, $version, $staffId): AdvertisementDraft {
            $record = $this->editable($draft, $version);
            $payload = array_replace($record->payload, $incoming);
            if (isset($incoming['marks'])) {
                $payload['marks'] = array_replace($record->payload['marks'] ?? [], $incoming['marks']);
            }
            $record->payload = AdvertisementPayload::validate($payload);
            $this->touchDraft($record, $staffId);

            return $record;
        });
    }

    public function upload(AdvertisementDraft $draft, UploadedFile $file, int $version, int $staffId): AdvertisementDraft
    {
        $path = $file->store('advertisements/'.$draft->reference, 'local');
        if ($path === false) {
            throw new RuntimeException('Advertisement document could not be stored.');
        }
        $oldPath = null;
        try {
            $record = DB::transaction(function () use ($draft, $path, $version, $staffId, &$oldPath): AdvertisementDraft {
                $record = $this->editable($draft, $version);
                $oldPath = $record->document_path;
                $record->document_path = $path;
                $this->touchDraft($record, $staffId);

                return $record;
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        if ($oldPath !== null) {
            Storage::disk('local')->delete($oldPath);
        }

        return $record;
    }

    public function submit(AdvertisementDraft $draft, int $version, int $staffId, AdvertisementSubmission $submission): AdvertisementDraft
    {
        return DB::transaction(function () use ($draft, $version, $staffId, $submission): AdvertisementDraft {
            $record = AdvertisementDraft::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();
            if ($record->state === 'submitted') {
                return $record;
            }
            $this->assertEditable($record, $version);
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement('LOCK TABLE recruitment_master_tab, category_master, qualification_master, experience_master_tab, caste_tab IN SHARE MODE');
            }
            $payload = AdvertisementPayload::validate($record->payload, true);
            $record->payload = $payload;
            if ($record->document_path === null || ! Storage::disk('local')->exists($record->document_path)) {
                throw ValidationException::withMessages(['document' => 'Upload the advertisement document before submission.']);
            }
            $record->submitted_advertisement_id = $submission->write($record, $payload, $staffId);
            $record->state = 'submitted';
            $record->submitted_at = now();
            $this->touchDraft($record, $staffId);

            return $record;
        });
    }

    private function editable(AdvertisementDraft $draft, int $version): AdvertisementDraft
    {
        $record = AdvertisementDraft::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();
        $this->assertEditable($record, $version);

        return $record;
    }

    private function assertEditable(AdvertisementDraft $record, int $version): void
    {
        abort_unless($record->state === 'draft', 409, 'Submitted advertisements cannot be edited through draft endpoints.');
        abort_unless($record->version === $version, 409, 'This draft has changed. Reload it before saving again.');
    }

    private function touchDraft(AdvertisementDraft $record, int $staffId): void
    {
        $record->updated_by = $staffId;
        $record->version++;
        $record->save();
    }
}
