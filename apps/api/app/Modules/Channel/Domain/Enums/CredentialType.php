<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Enums;

enum CredentialType: string
{
    case OAuthToken = 'oauth_token';
    case ApiKey = 'api_key';
    case ConsumerKeySecret = 'consumer_key_secret';
    case Custom = 'custom';
}
