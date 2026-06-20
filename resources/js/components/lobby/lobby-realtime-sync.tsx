import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

interface Props {
    listingId: number;
}

/**
 * Subscribes to `private-lobby.{id}` and triggers an Inertia partial reload
 * of the `lobby` prop on every `.lobby.updated` event. Rendered as a tiny
 * leaf component so the parent (`TeamPlayLobbyView`) can mount/unmount it
 * based on auth + live-state gates without violating React's
 * hooks-must-be-unconditional rule.
 *
 * Why partial reload + not a state patch from the event payload: the
 * `LobbyResource` builds derived data (pot, fee, per-team skill averages,
 * trust aggregates) that's expensive to re-derive client-side and would
 * drift if we tried. The broadcast is a trigger; the server stays the
 * source of truth for payload shape.
 */
export function LobbyRealtimeSync({ listingId }: Props) {
    useEcho(`lobby.${listingId}`, '.lobby.updated', () => {
        router.reload({ only: ['lobby'] });
    });

    return null;
}
