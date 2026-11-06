<?php

namespace App\Http\Requests;

use App\Domain\Identity\AdvertisementPayload;
use App\Domain\Identity\AdvertisementText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class SaveAdvertisementRequest extends FormRequest
{
    private const BASE64_WRITEUPS = [
        'writeup_language' => 'language_writeup',
        'writeup_age' => 'age_writeup',
        'writeup_essential_qualification' => 'essential_writeup',
        'writeup_desirable_qualification' => 'desirable_writeup',
        'experience_writeup' => 'marks_writeup',
        'writeup_experience' => 'marks_writeup',
        'writeup_miscellaneous' => 'miscellaneous_writeup',
        'writeup_disabilities' => 'disability_writeup',
    ];

    protected function prepareForValidation(): void
    {
        $input = AdvertisementText::normalize($this->all());
        foreach (self::BASE64_WRITEUPS as $source => $target) {
            if (! array_key_exists($source, $input)) {
                continue;
            }
            $decoded = AdvertisementText::normalize([$target => $this->decodeWriteup($source, $input[$source])])[$target];
            if (array_key_exists($target, $input) && $input[$target] !== $decoded) {
                throw ValidationException::withMessages([
                    $source => 'Conflicting write-up values. Send one value for '.$target.'.',
                ]);
            }
            $input[$target] = $decoded;
            unset($input[$source]);
        }
        $this->replace($input);
    }

    private function decodeWriteup(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = is_string($value) && strlen($value) <= 350000 ? base64_decode($value, true) : false;
        if ($decoded === false || ! mb_check_encoding($decoded, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $decoded)
            || ! in_array($value, [base64_encode($decoded), rtrim(base64_encode($decoded), '=')], true)) {
            throw ValidationException::withMessages([
                $field => 'Provide valid Base64-encoded UTF-8 write-up content.',
            ]);
        }

        return $decoded;
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return AdvertisementPayload::rules(false, true) + [
            'version' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_merge(AdvertisementPayload::FIELDS, ['version'])) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
        }];
    }
}
