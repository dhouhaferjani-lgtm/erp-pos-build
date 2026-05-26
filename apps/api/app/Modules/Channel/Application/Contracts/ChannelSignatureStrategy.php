<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\Contracts;

use App\Modules\Channel\Domain\Models\Channel;
use Illuminate\Http\Request;

interface ChannelSignatureStrategy
{
    public function verify(Request $request, Channel $channel): bool;
}
