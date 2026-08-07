<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Entities;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

final class ImpersonationSessionPermission extends Model
{
    use CentralConnection;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['session_id', 'permission'];
}
