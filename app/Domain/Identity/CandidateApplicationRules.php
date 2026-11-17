<?php

namespace App\Domain\Identity;

use App\Rules\EncryptedEntityId;

class CandidateApplicationRules
{
    public const PURPOSES = ['photo', 'signature', 'address', 'dob', 'caste', 'pwd', 'exempted', 'exservice', 'ews', 'age-relaxation', 'qualification', 'experience', 'council'];

    public static function section(string $section, bool $complete = false): array
    {
        $p = $complete ? 'required' : 'sometimes';
        $id = fn (string $type): array => [$p, 'bail', 'string', 'max:1024', new EncryptedEntityId($type)];
        $optional = fn (string $type): array => ['sometimes', 'nullable', 'bail', 'string', 'max:1024', new EncryptedEntityId($type)];

        $rules = match ($section) {
            'post' => ['post_id' => $id('advertisement-post')],
            'personal-details' => [
                'dob' => [$p, 'date_format:Y-m-d', 'before_or_equal:today'],
                'gender' => [$p, 'in:Male,Female,Other'], 'marital_status' => [$p, 'in:Married,Unmarried'],
                'father_name' => [$p, 'string', 'max:250'], 'mother_name' => [$p, 'string', 'max:250'],
                'present_address' => [$p, 'string', 'max:400'], 'permanent_address' => [$p, 'string', 'max:250'],
                'present_pincode' => [$p, 'string', 'regex:/\A[0-9]{6}\z/'], 'permanent_pincode' => [$p, 'string', 'regex:/\A[0-9]{6}\z/'],
                'read_write_bengali' => [$p, 'in:Yes,No'],
                'photo_document_id' => $id('candidate-document'), 'signature_document_id' => $id('candidate-document'),
                'address_document_id' => $id('candidate-document'), 'dob_document_id' => $id('candidate-document'),
                'council_name' => ['sometimes', 'nullable', 'string', 'max:250'], 'council_number' => ['sometimes', 'nullable', 'string', 'max:150'],
                'council_document_id' => $optional('candidate-document'),
            ],
            'category-relaxations' => [
                'caste_id' => $id('age-relaxation'), 'caste_community_id' => $optional('caste-community'),
                'caste_certificate_number' => ['sometimes', 'nullable', 'string', 'max:150'],
                'caste_certificate_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'issuing_authority_id' => $optional('caste-issuing-authority'),
                'pwd' => [$p, 'in:Yes,No'], 'exempted' => [$p, 'in:Yes,No'], 'exservice' => [$p, 'in:Yes,No'], 'ews' => [$p, 'in:Yes,No'],
                'pwd_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
                'caste_document_id' => $optional('candidate-document'), 'pwd_document_id' => $optional('candidate-document'),
                'exempted_document_id' => $optional('candidate-document'), 'exservice_document_id' => $optional('candidate-document'), 'ews_document_id' => $optional('candidate-document'),
                'age_relaxations' => ['sometimes', 'array', 'max:30'], 'age_relaxations.*' => ['array:section_id,document_id,reason'],
                'age_relaxations.*.section_id' => ['required', 'bail', 'string', new EncryptedEntityId('age-relaxation')],
                'age_relaxations.*.document_id' => ['required', 'bail', 'string', new EncryptedEntityId('candidate-document')],
                'age_relaxations.*.reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ],
            'qualifications' => [
                'items' => [$complete ? 'present' : 'sometimes', 'array', 'max:50'], 'items.*' => ['array:option_id,board,full_marks,marks_obtained,issue_date,pursuing,attempts,document_id'],
                'items.*.option_id' => ['required', 'bail', 'distinct', 'string', new EncryptedEntityId('advertisement-qualification')],
                'items.*.board' => [$p, 'string', 'max:200'], 'items.*.full_marks' => [$p, 'numeric', 'gt:0', 'max:100000'],
                'items.*.marks_obtained' => [$p, 'numeric', 'min:0', 'lte:items.*.full_marks'],
                'items.*.issue_date' => [$p, 'date_format:Y-m-d', 'before_or_equal:today'],
                'items.*.pursuing' => [$p, 'in:Yes,No'], 'items.*.attempts' => [$p, 'integer', 'min:1', 'max:100'],
                'items.*.document_id' => [$p, 'bail', 'string', new EncryptedEntityId('candidate-document')],
            ],
            'experiences' => [
                'items' => [$complete ? 'present' : 'sometimes', 'array', 'max:50'], 'items.*' => ['array:option_id,organization,years,months,document_id'],
                'items.*.option_id' => ['required', 'bail', 'string', new EncryptedEntityId('advertisement-experience')],
                'items.*.organization' => [$p, 'string', 'max:200'], 'items.*.years' => [$p, 'integer', 'min:0', 'max:80'],
                'items.*.months' => [$p, 'integer', 'min:0', 'max:11'], 'items.*.document_id' => [$p, 'bail', 'string', new EncryptedEntityId('candidate-document')],
            ],default => [],
        };
        if ($section === 'personal-details') {
            foreach (['present', 'permanent'] as $address) {
                foreach (['state', 'district', 'subdivision', 'block-municipality', 'police-station'] as $type) {
                    $rules[$address.'_'.str_replace('-', '_', $type).'_id'] = $optional($type);
                }
                $rules[$address.'_post_office'] = ['sometimes', 'nullable', 'string', 'max:200'];
                $rules[$address.'_ward_gp'] = ['sometimes', 'nullable', 'string', 'max:200'];
            }
        }

        return $rules;
    }
}
