<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendMagicLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $config = config('auth.throttle.magic_link');
        $decayMinutes = (int) $config['decay_minutes'];

        $this->hitOrLockout($this->emailKey(), (int) $config['max_per_email'], $decayMinutes);
        $this->hitOrLockout($this->ipKey(), (int) $config['max_per_ip'], $decayMinutes);
    }

    private function hitOrLockout(string $key, int $maxAttempts, int $decayMinutes): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => __('Too many requests. Please try again in :minutes minute(s).', [
                    'minutes' => (int) ceil($seconds / 60),
                ]),
            ]);
        }

        RateLimiter::hit($key, $decayMinutes * 60);
    }

    private function emailKey(): string
    {
        return 'magic-link:email:'.Str::lower((string) $this->input('email'));
    }

    private function ipKey(): string
    {
        return 'magic-link:ip:'.$this->ip();
    }
}
