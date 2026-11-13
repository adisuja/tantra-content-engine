<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    // tantra: emails are stored lowercase and unique whatever their case (Clerk sign in matches on them).
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    // tantra: also refuse an email another user holds in a different case.
    public function after(): array
    {
        return [function (Validator $validator): void {
            $email = $this->input('email');
            if (is_string($email) && User::query()->whereRaw('lower(email) = ?', [$email])->whereKeyNot($this->user()->id)->exists()) {
                $validator->errors()->add('email', __('validation.unique', ['attribute' => 'email']));
            }
        }];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->profileRules($this->user()->id);
    }
}
