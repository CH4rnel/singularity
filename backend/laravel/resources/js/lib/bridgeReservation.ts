import { bridgeIntentMessage } from '@/lib/bridgeIntent';
import { reserve } from '@/routes/bridge';

export type BridgeIntent = {
    direction: string;
    token: string;
    amount: string;
    senderAddress: string | null;
    recipientAddress: string;
};

export type BridgeReservationResult =
    | { ok: true; reference: string; expiresAt: string }
    | { ok: false; message: string; reason: string; capacity?: unknown };

/** Both bridge surfaces must claim destination inventory before signing. */
export const reserveBridgeCapacity = async (
    intent: BridgeIntent,
): Promise<BridgeReservationResult> => {
    try {
        const csrf =
            typeof document === 'undefined'
                ? ''
                : (document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
        const response = await fetch(reserve().url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(csrf),
            },
            body: JSON.stringify({
                direction: intent.direction,
                token: intent.token,
                sender_address: intent.senderAddress,
                recipient_address: intent.recipientAddress,
                amount: intent.amount,
            }),
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            return {
                ok: false,
                reason:
                    typeof body?.reason === 'string' ? body.reason : 'refused',
                message:
                    typeof body?.message === 'string'
                        ? body.message
                        : 'The bridge cannot take this transfer right now.',
                capacity: body?.capacity,
            };
        }

        const reservation = body?.reservation;

        if (
            typeof reservation?.reference !== 'string' ||
            !reservation.reference.trim() ||
            typeof reservation?.expires_at !== 'string' ||
            !Number.isFinite(Date.parse(reservation.expires_at)) ||
            Date.parse(reservation.expires_at) <= Date.now()
        ) {
            return {
                ok: false,
                reason: 'invalid_reservation',
                message:
                    'The liquidity hold could not be confirmed. Nothing was sent — try again.',
            };
        }

        return {
            ok: true,
            reference: reservation.reference,
            expiresAt: reservation.expires_at,
        };
    } catch {
        return {
            ok: false,
            reason: 'unreachable',
            message:
                'Could not reach the bridge to hold liquidity for this transfer. Nothing was sent — try again.',
        };
    }
};

/**
 * A failed or malformed claim can never reach the irreversible source leg.
 *
 * `authorize`, when given, is asked to sign the payout-recipient statement
 * for this claim (B03) between the claim and the transfer: a refusal there
 * still costs nothing, because nothing has been sent yet.
 */
export const withBridgeReservation = async <T>(
    intent: BridgeIntent,
    deposit: () => Promise<T>,
    authorize?: (message: string) => Promise<string | null>,
): Promise<{ deposit: T; reservation: string; intentSignature?: string }> => {
    const claim = await reserveBridgeCapacity(intent);

    if (!claim.ok) {
        throw new Error(claim.message);
    }

    let intentSignature: string | undefined;

    if (authorize) {
        const signature = await authorize(
            bridgeIntentMessage({
                direction: intent.direction,
                token: intent.token,
                sender: intent.senderAddress ?? '',
                recipient: intent.recipientAddress,
                reference: claim.reference,
            }),
        );

        if (!signature) {
            throw new Error(
                'The payout recipient was not signed. Nothing was sent.',
            );
        }

        intentSignature = signature;
    }

    return {
        deposit: await deposit(),
        reservation: claim.reference,
        intentSignature,
    };
};
