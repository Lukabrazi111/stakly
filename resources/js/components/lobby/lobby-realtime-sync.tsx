import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { toast } from 'sonner';
import { useT } from '@/lib/i18n';
import { index as listingsIndex } from '@/routes/listings';

interface Props {
    listingId: number;
    /** Current viewer's user id — matched against the event's `kicked_user_id`
     *  so a removed player recognises the kick was theirs. */
    viewerId: number | null;
    /** Public lobbies stay viewable after a kick (spectator); private lobbies
     *  bar the kicked user, so a reload would 404 — redirect instead. */
    isPublic: boolean;
}

interface LobbyUpdatedPayload {
    listing_id: number;
    kicked_user_id: number | null;
}

/**
 * Subscribes to `private-lobby.{id}` and reacts to `.lobby.updated`:
 *   - if the payload's `kicked_user_id` is the current viewer → toast that
 *     they were removed, then reload as a spectator (public lobby) or redirect
 *     to the marketplace (private lobby, where a reload would 404 them).
 *   - otherwise → partial reload of the `lobby` prop (roster/state changed).
 *
 * Rendered as a tiny leaf so the parent (`TeamPlayLobbyView`) can mount/unmount
 * it on auth + live-state gates without violating the hooks-unconditional rule.
 *
 * Why partial reload + not a state patch from the payload: `LobbyResource`
 * derives data (pot, fee, per-team skill averages, trust aggregates) that's
 * expensive to re-derive client-side and would drift. The broadcast is a
 * trigger; the server stays the source of payload truth.
 */
export function LobbyRealtimeSync({ listingId, viewerId, isPublic }: Props) {
    const t = useT();

    useEcho<LobbyUpdatedPayload>(
        `lobby.${listingId}`,
        '.lobby.updated',
        (payload) => {
            const wasKicked =
                payload.kicked_user_id !== null &&
                payload.kicked_user_id === viewerId;

            if (wasKicked) {
                if (isPublic) {
                    // Public lobby stays viewable — they can watch and rejoin
                    // once the 5-min cooldown passes.
                    toast.info(
                        t(
                            'The host removed you from the lobby. You can rejoin in 5 minutes.',
                        ),
                    );
                    router.reload({ only: ['lobby'] });
                } else {
                    // Private lobby bars a kicked viewer (reload would 404), and
                    // re-entry needs a fresh invite — so no rejoin promise here.
                    toast.info(t('The host removed you from the lobby.'));
                    router.visit(listingsIndex().url);
                }

                return;
            }

            router.reload({ only: ['lobby'] });
        },
        [viewerId, isPublic],
    );

    return null;
}
