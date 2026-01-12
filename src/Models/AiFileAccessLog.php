<?php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;

class AiFileAccessLog extends Model
{
    protected $fillable = [
        'user_id',
        'file_id',
        'access_granted',
        'access_method',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'access_granted' => 'boolean',
    ];

    public static function log(
        mixed $user,
        int|string $fileId,
        bool $granted,
        ?string $method = null
    ): self {
        $userId = null;
        if ($user !== null) {
            $userId = $user->id ?? (method_exists($user, 'getKey') ? $user->getKey() : null);
        }

        return self::create([
            'user_id' => $userId,
            'file_id' => (string) $fileId,
            'access_granted' => $granted,
            'access_method' => $method,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
