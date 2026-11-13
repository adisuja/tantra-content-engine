<?php

declare(strict_types=1);

namespace App\Tantra\Channels;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * tantra: the saved keys of one developer app (see ChannelApps::catalog()).
 *
 * @property string $app
 * @property array<string, string|bool> $credentials
 * @property string|null $updated_by
 */
class ChannelApp extends Model
{
    use HasUuids;

    protected $table = 'tantra_channel_apps';

    protected $fillable = ['app', 'credentials', 'updated_by'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }
}
