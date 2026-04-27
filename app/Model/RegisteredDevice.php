<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Builder;

/**
 * @property int $id
 * @property string $fcm_token
 * @property string $email_address
 * @property string|null $display_name
 * @property string $imap_host
 * @property int $imap_port
 * @property bool $imap_ssl
 * @property string $imap_user
 * @property string $imap_password
 * @property int|null $last_seen_uid
 * @property bool $is_active
 */
class RegisteredDevice extends Model
{
    protected ?string $table = 'registered_devices';

    protected array $fillable = [
        'fcm_token',
        'email_address',
        'display_name',
        'imap_host',
        'imap_port',
        'imap_ssl',
        'imap_user',
        'imap_password',
        'last_seen_uid',
        'is_active',
    ];

    protected array $hidden = ['imap_password'];

    protected array $casts = [
        'imap_ssl' => 'boolean',
        'is_active' => 'boolean',
        'imap_port' => 'integer',
        'last_seen_uid' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1);
    }
}
