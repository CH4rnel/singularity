import { errorCode } from '@/lib/analytics/errors';
import type { AnalyticsErrorCode } from '@/lib/analytics/taxonomy';

/**
 * What a failure means, in words a person can act on.
 *
 * A node's error is written for the developer of the node: English only, full
 * of hex, and sometimes carrying the whole signed transaction. It used to be
 * printed as the reason under a failed send, so a Russian or Chinese reader
 * got several hundred characters of ethers diagnostics in place of an answer.
 * The classifier analytics already relies on decides what kind of failure it
 * was; this turns that kind into a dictionary key, and keeps the raw text only
 * for the collapsed "technical details" a support conversation needs.
 */
export type FailureReason = {
    /** A `walletMessages` key: `failReason_<code>`. */
    key: `failReason_${AnalyticsErrorCode}`;
    /** The original text, for the collapsed details block — never a headline. */
    detail: string;
};

export const failureReason = (error: unknown): FailureReason => ({
    key: `failReason_${errorCode(error)}`,
    detail:
        error instanceof Error
            ? error.message
            : typeof error === 'string'
              ? error
              : '',
});
