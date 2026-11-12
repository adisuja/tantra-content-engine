<?php

declare(strict_types=1);

namespace App\Tantra\Ai;

use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * tantra: one connector on the Connectors tab, shared by every workspace of
 * the Account. The secret never leaves the server; the page only sees its
 * last four characters.
 *
 * @property string $id
 * @property string $account_id
 * @property string $provider
 * @property string|null $secret
 */
class AiConnector extends Model
{
    use HasUuids;

    protected $table = 'tantra_ai_connectors';

    protected $fillable = [
        'account_id', 'provider', 'secret', 'secret_last_four', 'base_url', 'selected_model',
        'is_default', 'is_active', 'last_verified_at', 'last_failed_at', 'last_error', 'updated_by',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'last_verified_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeForAi(Builder $query): void
    {
        $query->where('is_active', true)->whereIn('provider', ProviderCatalog::aiProviders());
    }

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        return ProviderCatalog::find($this->provider) ?? [];
    }

    public function isSubscription(): bool
    {
        return ($this->catalog()['kind'] ?? null) === 'subscription';
    }

    public function effectiveBaseUrl(): ?string
    {
        return filled($this->base_url) ? rtrim((string) $this->base_url, '/') : ($this->catalog()['base_url'] ?? null);
    }

    public function effectiveModel(): ?string
    {
        return filled($this->selected_model) ? $this->selected_model : ($this->catalog()['default_model'] ?? null);
    }

    /** The name this connector is registered under in config('ai.providers'). */
    public function providerName(): string
    {
        return 'tantra_'.$this->id;
    }

    public static function lastFour(?string $secret): ?string
    {
        if (blank($secret)) {
            return null;
        }

        $secret = trim($secret);

        return Str::startsWith($secret, '{') ? 'json' : Str::substr($secret, -4);
    }
}
