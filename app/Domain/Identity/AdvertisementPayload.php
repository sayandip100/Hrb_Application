<?php

namespace App\Domain\Identity;

use App\Models\AgeRelaxation;
use App\Models\Discipline;
use App\Models\Experience;
use App\Models\Qualification;
use App\Models\Recruitment;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdvertisementPayload
{
    public const VACANCIES = ['ur', 'sc', 'st', 'obc', 'obc_a', 'obc_b', 'pwd', 'scpwd', 'ews',
        'ews_ec', 'ews_g_c', 'ews_g_d', 'ur_ec', 'ur_g_c', 'ur_g_d', 'ur_sp', 'sc_ec', 'sc_g_c',
        'sc_g_d', 'st_ec', 'st_g_d', 'obc_a_ec', 'obc_a_g_d', 'obc_b_ec', 'obc_b_g_d'];

    public const FIELDS = ['recruitment_id', 'advertisement_number', 'type', 'starts_at', 'ends_at',
        'old_starts_at', 'old_ends_at', 'temporary_appointment', 'pay_scale', 'total_vacancies',
        'dob_from', 'dob_to', 'age_relaxation_years', 'age_writeup', 'fee', 'pwd_percent',
        'has_experience', 'dictation', 'language_writeup', 'essential_writeup', 'desirable_writeup',
        'marks_writeup', 'miscellaneous_writeup', 'disability_writeup', 'disciplines', 'qualifications',
        'experiences', 'age_fee_rules', 'marks'];

    public static function rules(bool $complete, bool $publicIds = false): array
    {
        $presence = $complete ? 'required' : 'sometimes';
        $value = $complete ? 'required' : 'nullable';
        $id = $publicIds ? ['string', 'max:1024'] : ['integer', 'min:1'];
        $rules = [
            'recruitment_id' => [$presence, $value, ...$id],
            'advertisement_number' => [$presence, $value, 'string', 'max:650'],
            'type' => [$presence, $value, Rule::in(['New', 'Old'])],
            'starts_at' => [$presence, $value, 'date_format:Y-m-d H:i:s'],
            'ends_at' => [$presence, $value, 'date_format:Y-m-d H:i:s'],
            'old_starts_at' => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s'],
            'old_ends_at' => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s'],
            'temporary_appointment' => [$presence, $value, 'string', 'max:65000'],
            'pay_scale' => [$presence, $value, 'string', 'max:65000'],
            'total_vacancies' => [$presence, $value, 'integer', 'min:1', 'max:2147483647'],
            'dob_from' => [$presence, $value, 'date_format:Y-m-d', 'before:today'],
            'dob_to' => [$presence, $value, 'date_format:Y-m-d', 'before:today'],
            'age_relaxation_years' => [$presence, $value, 'integer', 'between:0,127'],
            'fee' => [$presence, $value, 'numeric', 'decimal:0,2', 'between:0,10000000'],
            'pwd_percent' => [$presence, $value, 'integer', 'between:0,100'],
            'has_experience' => [$presence, $value, Rule::in(['Yes', 'No'])],
            'dictation' => [$presence, $value, Rule::in(['Yes', 'No'])],
            'disciplines' => [$presence, 'array', 'list', 'max:200', ...($complete ? ['min:1'] : [])],
            'qualifications' => [$presence, 'array', 'list', 'max:200', ...($complete ? ['min:1'] : [])],
            'experiences' => ['sometimes', 'array', 'list', 'max:200'],
            'age_fee_rules' => ['sometimes', 'array', 'list', 'max:200'],
            'marks' => [$presence, 'array:academic,experience,interview,written'],
        ];
        foreach (['age_writeup', 'language_writeup', 'essential_writeup', 'desirable_writeup',
            'marks_writeup', 'miscellaneous_writeup', 'disability_writeup'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:65000'];
        }
        foreach (['academic', 'experience', 'interview', 'written'] as $field) {
            $rules['marks.'.$field] = [$presence, $value, 'numeric', 'decimal:0,2', 'between:0,100'];
        }
        $rules['disciplines.*'] = ['array:discipline_id,gender,marital_status,vacancies'];
        $rules['disciplines.*.discipline_id'] = [$presence, $value, ...$id];
        foreach (['gender', 'marital_status'] as $field) {
            $rules['disciplines.*.'.$field] = [$presence, $value, 'string', 'max:200'];
        }
        $rules['disciplines.*.vacancies'] = [$presence, 'array:'.implode(',', self::VACANCIES)];
        foreach (self::VACANCIES as $category) {
            $rules['disciplines.*.vacancies.'.$category] = ['sometimes', 'nullable', 'integer', 'between:0,2147483647'];
        }
        $rules['qualifications.*'] = ['array:qualification_id,type,relationship,maximum_marks,distribution,final_qualification,allow_pursuing,attempt_type,attempt_marks,slabs,deductions'];
        $rules['experiences.*'] = ['array:experience_id,type,relationship,maximum_marks,distribution,minimum_months,slabs'];
        foreach (['qualifications' => 'qualification_id', 'experiences' => 'experience_id'] as $section => $field) {
            $rules[$section.'.*.'.$field] = [$presence, $value, ...$id];
            $rules[$section.'.*.type'] = [$presence, $value, Rule::in(['Essential', 'Desirable'])];
            $rules[$section.'.*.relationship'] = [$presence, $value, Rule::in(['AND', 'OR', 'END'])];
            $rules[$section.'.*.maximum_marks'] = [$presence, $value, 'numeric', 'decimal:0,2', 'between:0,100'];
            $rules[$section.'.*.distribution'] = [$presence, $value, Rule::in($section === 'qualifications' ? ['Full', 'Percent', 'Slab'] : ['Full', 'Slab'])];
            $rules[$section.'.*.slabs'] = ['sometimes', 'array', 'list', 'max:100'];
            $rules[$section.'.*.slabs.*'] = [$section === 'qualifications' ? 'array:threshold,marks' : 'array:operator,months,marks'];
            $rules[$section.'.*.slabs.*.marks'] = [$presence, $value, 'numeric', 'decimal:0,2', 'between:0,100'];
        }
        foreach (['final_qualification', 'allow_pursuing'] as $field) {
            $rules['qualifications.*.'.$field] = [$presence, $value, Rule::in(['Yes', 'No'])];
        }
        $rules['qualifications.*.attempt_type'] = [$presence, $value, Rule::in(['No', 'Full', 'Percent', 'Slab'])];
        $rules['qualifications.*.attempt_marks'] = ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,100'];
        $rules['qualifications.*.slabs.*.threshold'] = [$presence, $value, 'integer', 'between:0,100'];
        $rules['qualifications.*.deductions'] = ['sometimes', 'array', 'list', 'max:100'];
        $rules['qualifications.*.deductions.*'] = ['array:attempt,marks'];
        $rules['qualifications.*.deductions.*.attempt'] = [$presence, $value, 'integer', 'between:1,2147483647'];
        $rules['qualifications.*.deductions.*.marks'] = [$presence, $value, 'numeric', 'decimal:0,2', 'between:0,100'];
        $rules['experiences.*.minimum_months'] = [$presence, $value, 'integer', 'between:0,2147483647'];
        $rules['experiences.*.slabs.*.operator'] = [$presence, $value, Rule::in(['UPTO', 'GT'])];
        $rules['experiences.*.slabs.*.months'] = [$presence, $value, 'integer', 'between:0,2147483647'];
        $rules['age_fee_rules.*'] = ['array:section_id,relationship,years,fee_type,partial_fee'];
        $rules['age_fee_rules.*.section_id'] = [$presence, $value, ...$id];
        $rules['age_fee_rules.*.relationship'] = [$presence, $value, Rule::in(['AND', 'OR', 'END'])];
        $rules['age_fee_rules.*.years'] = [$presence, $value, 'integer', 'between:0,2147483647'];
        $rules['age_fee_rules.*.fee_type'] = [$presence, $value, Rule::in(['Full', 'Part', 'No', 'NA'])];
        $rules['age_fee_rules.*.partial_fee'] = ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,10000000'];

        if (! $publicIds) {
            foreach (['recruitment_id', 'disciplines.*.discipline_id', 'qualifications.*.qualification_id', 'age_fee_rules.*.section_id'] as $field) {
                $rules[$field][] = 'max:2147483647';
            }
        }

        return $rules;
    }

    public static function decodeIncoming(array $data): array
    {
        return self::restoreListOrder(self::mapIds($data, false));
    }

    public static function publicPayload(array $data): array
    {
        return self::mapIds($data, true);
    }

    private static function mapIds(array $data, bool $encode): array
    {
        $mappings = ['recruitment_id' => 'recruitment', 'discipline_id' => 'discipline',
            'qualification_id' => 'qualification', 'experience_id' => 'experience', 'section_id' => 'age-relaxation'];
        foreach ($data as $key => $value) {
            if (isset($mappings[$key]) && $value !== null) {
                try {
                    $data[$key] = $encode ? PublicId::encode($mappings[$key], $value) : PublicId::decode($mappings[$key], $value);
                } catch (DecryptException) {
                    throw ValidationException::withMessages([$key => 'Select a valid encrypted identifier.']);
                }
            } elseif (is_array($value)) {
                $data[$key] = self::mapIds($value, $encode);
            }
        }

        return $data;
    }

    public static function validate(array $payload, bool $complete = false): array
    {
        $payload = Validator::make(AdvertisementText::normalize($payload), self::rules($complete))->validate();
        $errors = [];
        $add = function (string $key, string $message) use (&$errors): void {
            $errors[$key] = $message;
        };
        foreach ([['starts_at', 'ends_at'], ['old_starts_at', 'old_ends_at'], ['dob_from', 'dob_to']] as [$from, $to]) {
            if (isset($payload[$from], $payload[$to]) && $payload[$from] >= $payload[$to]) {
                $add($to, 'The end must be after the start.');
            }
        }
        $recruitmentId = $payload['recruitment_id'] ?? null;
        if ($recruitmentId !== null && ! Recruitment::query()->whereKey($recruitmentId)->where('rm_status', 1)->exists()) {
            $add('recruitment_id', 'Select an active recruitment.');
        }
        $references = [
            'disciplines' => [Discipline::class, 'discipline_id', 'catm_status'],
            'qualifications' => [Qualification::class, 'qualification_id', 'qm_status'],
            'experiences' => [Experience::class, 'experience_id', 'expset_status'],
            'age_fee_rules' => [AgeRelaxation::class, 'section_id', 'caste_status'],
        ];
        foreach ($references as $section => [$model, $key, $status]) {
            $seen = [];
            $items = $payload[$section] ?? [];
            $records = $model::query()->whereKey(array_values(array_filter(array_column($items, $key))))->get()->keyBy(fn ($record) => $record->getKey());
            $hasSpecificDisciplines = $section === 'disciplines' && $recruitmentId !== null
                && Discipline::query()->where('catm_master', $recruitmentId)->where('catm_status', 1)->exists();
            foreach ($items as $index => $row) {
                if (! isset($row[$key])) {
                    continue;
                }
                $id = $row[$key];
                $record = $records->get($id);
                if (isset($seen[$id])) {
                    $add("$section.$index.$key", 'This entry already exists in the advertisement.');
                }
                $seen[$id] = true;
                if ($record === null || $record->$status !== 1) {
                    $add("$section.$index.$key", 'Select an active master record.');

                    continue;
                }
                if ($recruitmentId !== null && $section === 'disciplines') {
                    $expected = $hasSpecificDisciplines ? $recruitmentId : null;
                    if ($record->catm_master !== $expected) {
                        $add("$section.$index.$key", 'This discipline is not available for the selected recruitment.');
                    }
                }
                if ($recruitmentId !== null && $section === 'qualifications'
                    && $record->qm_r_master !== null && $record->qm_r_master !== $recruitmentId) {
                    $add("$section.$index.$key", 'This qualification belongs to another recruitment.');
                }
            }
        }
        foreach (['qualifications', 'experiences'] as $section) {
            foreach ($payload[$section] ?? [] as $index => $row) {
                if ($complete && ($row['distribution'] ?? null) === 'Slab' && empty($row['slabs'])) {
                    $add("$section.$index.slabs", 'Add at least one scoring slab.');
                }
                if (($row['distribution'] ?? null) !== 'Slab' && ! empty($row['slabs'])) {
                    $add("$section.$index.slabs", 'Scoring slabs require Slab distribution.');
                }
                $thresholds = [];
                foreach ($row['slabs'] ?? [] as $slabIndex => $slab) {
                    $threshold = $section === 'qualifications' ? ($slab['threshold'] ?? null) : (($slab['operator'] ?? '').':'.($slab['months'] ?? ''));
                    if ($threshold !== null && isset($thresholds[$threshold])) {
                        $add("$section.$index.slabs.$slabIndex", 'Duplicate scoring slab.');
                    }
                    $thresholds[$threshold ?? 'missing'] = true;
                    if (isset($slab['marks'], $row['maximum_marks']) && $slab['marks'] > $row['maximum_marks']) {
                        $add("$section.$index.slabs.$slabIndex.marks", 'Slab marks cannot exceed maximum marks.');
                    }
                }
                if ($section === 'qualifications') {
                    $attemptType = $row['attempt_type'] ?? null;
                    if ($complete && in_array($attemptType, ['Full', 'Percent'], true) && ! isset($row['attempt_marks'])) {
                        $add("$section.$index.attempt_marks", 'Attempt deduction marks are required.');
                    }
                    if ($complete && $attemptType === 'Slab' && empty($row['deductions'])) {
                        $add("$section.$index.deductions", 'Add at least one deduction slab.');
                    }
                    if ($attemptType !== 'Slab' && ! empty($row['deductions'])) {
                        $add("$section.$index.deductions", 'Deduction slabs require Slab attempt type.');
                    }
                    $attempts = array_column($row['deductions'] ?? [], 'attempt');
                    if (count($attempts) !== count(array_unique($attempts))) {
                        $add("$section.$index.deductions", 'Duplicate attempt deduction slab.');
                    }
                }
            }
        }
        if ($complete) {
            if (($payload['type'] ?? null) === 'Old') {
                foreach (['old_starts_at', 'old_ends_at'] as $key) {
                    if (! isset($payload[$key])) {
                        $add($key, 'Previous application dates are required for an Old advertisement.');
                    }
                }
            }
            if (($payload['dictation'] ?? null) === 'Yes' && empty($payload['language_writeup'])) {
                $add('language_writeup', 'Language writeup is required when dictation is enabled.');
            }
            foreach (['qualifications', 'experiences'] as $section) {
                foreach (['Essential', 'Desirable'] as $type) {
                    $chain = array_filter($payload[$section] ?? [], fn (array $row): bool => $row['type'] === $type);
                    self::validateChain($chain, $section, $add);
                }
            }
            self::validateChain($payload['age_fee_rules'] ?? [], 'age_fee_rules', $add);
            if (! collect($payload['qualifications'])->contains(fn (array $row): bool => $row['type'] === 'Essential')) {
                $add('qualifications', 'Add at least one Essential qualification.');
            }
            if ($payload['has_experience'] === 'Yes' && empty($payload['experiences'])) {
                $add('experiences', 'Add experience requirements when experience is enabled.');
            }
            if ($payload['has_experience'] === 'No' && (! empty($payload['experiences']) || (float) $payload['marks']['experience'] !== 0.0)) {
                $add('experiences', 'Remove experience requirements and set experience marks to zero when experience is disabled.');
            }
            $total = 0;
            foreach ($payload['disciplines'] as $index => $row) {
                $count = array_sum($row['vacancies']);
                if ($count < 1 || $count > 2147483647) {
                    $add("disciplines.$index.vacancies", 'Discipline vacancy total must be between 1 and 2147483647.');
                }
                $total += $count;
            }
            if ($total !== (int) $payload['total_vacancies']) {
                $add('total_vacancies', 'Total vacancies must equal the sum of all discipline vacancy counts.');
            }
            $marks = array_sum(array_map(fn ($mark): int => (int) round((float) $mark * 100), $payload['marks']));
            if ($marks !== 10000) {
                $add('marks', 'Academic, experience, interview and written marks must total 100.');
            }
            foreach ($payload['age_fee_rules'] ?? [] as $index => $row) {
                if ($row['fee_type'] === 'Part' && (! isset($row['partial_fee']) || $row['partial_fee'] > $payload['fee'])) {
                    $add("age_fee_rules.$index.partial_fee", 'Provide a partial fee no greater than the base fee.');
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return self::restoreListOrder($payload);
    }

    /** Wildcard validation may rebuild integer keys out of insertion order. */
    private static function restoreListOrder(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::restoreListOrder($value);
            }
        }
        if ($data !== [] && count(array_filter(array_keys($data), 'is_int')) === count($data)) {
            ksort($data, SORT_NUMERIC);
            $data = array_values($data);
        }

        return $data;
    }

    private static function validateChain(array $rows, string $section, callable $add): void
    {
        $last = array_key_last($rows);
        foreach ($rows as $index => $row) {
            if (($index === $last) !== ($row['relationship'] === 'END')) {
                $add("$section.$index.relationship", 'Only the final row of each requirement type must use END; earlier rows use AND or OR.');
            }
        }
    }
}
