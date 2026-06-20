<?php

namespace App\Enums;

/**
 * Type of a chat message on `messages.type`.
 *
 * System messages are posted by internal Actions only (never the HTTP path)
 * with `user_id` null — used for dispute prompts and evidence hints. Cannot
 * be impersonated because the HTTP path always writes `Text` and sets the
 * auth user.
 */
enum MessageType: string
{
    case Text = 'text';
    case System = 'system';
}
