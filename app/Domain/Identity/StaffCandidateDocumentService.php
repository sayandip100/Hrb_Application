<?php

namespace App\Domain\Identity;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StaffCandidateDocumentService
{
    public const PERSONAL = [
        'photo' => 'fu_photo_doc', 'signature' => 'fu_signature_doc', 'dob' => 'fu_dob_doc', 'address' => 'fu_address_doc',
        'caste' => 'fu_caste_doc', 'pwd' => 'fu_pwd_doc', 'exempted' => 'fu_exc_doc', 'exservice' => 'fu_exs_doc',
        'ews' => 'fu_ews_doc', 'council' => 'fu_ext_council_reg_certificate',
        'father-change' => 'f_c_r_doc', 'mother-change' => 'm_c_r_doc',
    ];

    public function catalog(object $candidate, object $details): array
    {
        $items = [];
        foreach (self::PERSONAL as $kind => $column) {
            if (filled($details->$column ?? null)) {
                $items[] = $this->item($kind, $candidate->f_uid);
            }
        }
        if (filled($candidate->f_rename_doc ?? null)) {
            $items[] = $this->item('profile-change', $candidate->f_uid);
        }
        foreach (StaffCandidateActionService::SECTIONS as $kind => $meta) {
            foreach (DB::table($meta['table'])->where($meta['owner'], $candidate->f_uid)->whereNotNull($meta['doc'])->get([$meta['key'], $meta['doc']]) as $row) {
                if (filled($row->{$meta['doc']})) {
                    $items[] = $this->item($kind, $row->{$meta['key']});
                }
            }
        }
        foreach (DB::table('f_user_extraage')->where('fu_ext_masteruser', $candidate->f_uid)->whereNotNull('fu_ext_doc')->get(['fu_ext_id', 'fu_ext_doc']) as $row) {
            if (filled($row->fu_ext_doc)) {
                $items[] = $this->item('age-relaxation', $row->fu_ext_id);
            }
        }
        foreach (DB::table('candidate_result_tab')->where('cr_application_master', $candidate->f_application_no)->whereNotNull('cr_reject_document')->get(['cr_id', 'cr_reject_document']) as $row) {
            if (filled($row->cr_reject_document)) {
                $items[] = $this->item('rejection', $row->cr_id);
            }
        }

        return $items;
    }

    private function item(string $kind, int $id): array
    {
        return ['id' => PublicId::encode('staff-candidate-document:'.$kind, $id), 'kind' => $kind];
    }

    public function download(object $candidate, object $details, string $token): BinaryFileResponse
    {
        $path = null;
        $kinds = [...array_keys(self::PERSONAL), ...array_keys(StaffCandidateActionService::SECTIONS), 'profile-change', 'age-relaxation', 'rejection'];
        foreach ($kinds as $kind) {
            try {
                $id = PublicId::decode('staff-candidate-document:'.$kind, $token);
            } catch (DecryptException) {
                continue;
            }
            if (isset(self::PERSONAL[$kind]) && $id === (int) $candidate->f_uid) {
                $path = $details->{self::PERSONAL[$kind]};
            } elseif ($kind === 'profile-change' && $id === (int) $candidate->f_uid) {
                $path = $candidate->f_rename_doc;
            } elseif (isset(StaffCandidateActionService::SECTIONS[$kind])) {
                $m = StaffCandidateActionService::SECTIONS[$kind];
                $path = DB::table($m['table'])->where($m['key'], $id)->where($m['owner'], $candidate->f_uid)->value($m['doc']);
            } elseif ($kind === 'age-relaxation') {
                $path = DB::table('f_user_extraage')->where('fu_ext_id', $id)->where('fu_ext_masteruser', $candidate->f_uid)->value('fu_ext_doc');
            } elseif ($kind === 'rejection') {
                $path = DB::table('candidate_result_tab')->where('cr_id', $id)->where('cr_application_master', $candidate->f_application_no)->value('cr_reject_document');
            }
            break;
        }
        abort_unless(is_string($path) && $path !== '', 404);
        if (str_starts_with($path, 'candidate-documents/') || str_starts_with($path, 'candidate-admin/')) {
            $root = realpath(Storage::disk('local')->path(''));
            $full = realpath(Storage::disk('local')->path($path));
        } else {
            $base = config('candidate_admin.legacy_upload_root');
            abort_unless(is_string($base) && $base !== '', 404, 'Legacy upload storage is not configured.');
            abort_unless(preg_match('/\A[A-Za-z0-9]+\z/', $candidate->f_applied_for) && preg_match('/\A[A-Za-z0-9]+\z/', $candidate->f_application_no), 404);
            $baseRoot = realpath($base);
            $root = realpath($base.'/'.$candidate->f_applied_for.'/candidates/'.$candidate->f_application_no);
            abort_unless($baseRoot && $root && str_starts_with(strtolower(str_replace('\\', '/', $root)), rtrim(strtolower(str_replace('\\', '/', $baseRoot)), '/').'/'), 404);

            $full = $root ? realpath($root.'/'.basename(str_replace('\\', '/', $path))) : false;
        }
        abort_unless($root && $full && str_starts_with(strtolower(str_replace('\\', '/', $full)), rtrim(strtolower(str_replace('\\', '/', $root)), '/').'/') && is_file($full), 404);
        abort_unless(in_array(strtolower(pathinfo($full, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png'], true), 404);

        return response()->download($full, 'candidate-document.'.strtolower(pathinfo($full, PATHINFO_EXTENSION)), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
