<?php

namespace App\Enums;

/**
 * Type of a chat message on `messages.type`.
 *
 * Text   — sent by a match participant via `POST /matches/{match}/messages`.
 *          `user_id` is the authenticated user. Subject to rate limits +
 *          content cap.
 *
 * System — posted by an internal Action (never the HTTP path). `user_id`
 *          is null. Used in M8 Phase 5 for dispute-opened prompts and
 *          evidence-submission hints. Cannot be impersonated because the
 *          HTTP path always writes `Text` and sets the auth user.
 *
 * Future cases (Phase 3 `Image`, Phase 4 `Link`) will join here as the
 * smart-link enrichment lands.
 */
enum MessageType: string
{
    case Text = 'text';
    case System = 'system';
}
