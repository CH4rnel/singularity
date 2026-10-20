import { Contract, JsonRpcProvider, formatUnits } from 'ethers';
import { CYBERIA_PUBLIC_RPC, ensureCyberiaNetwork } from '@/lib/evmChains';
import { sessionCall } from '@/lib/wallet/session';

/**
 * The open-source reward engine from the browser: the board
 * (`/api/contributions`, ContributionsController) and the claim, which is one
 * call to CyberiaContributorRewards with the proof this server built.
 *
 * Whether a leaf is already claimed is read from the contract and never from
 * this server — the chain is the only party that knows.
 */
export type ContributionRow = {
    repository: string | null;
    kind: 'pull_request' | 'documentation' | 'issue';
    number: number;
    title: string;
    url: string;
    acceptedAt: string;
    points: number;
    status: 'counted' | 'rejected';
    reason: string | null;
    epoch: number | null;
};

export type ContributionBoard = {
    pool: {
        asset: string;
        symbol: string;
        decimals: number;
        perEpoch: string | null;
        maxShareBps: number;
        minPoints: number;
    };
    rules: {
        minAccountAgeDays: number;
        minChangedLines: number;
        maxPerDay: number;
        refuseSelfMerged: boolean;
        issueLabels: string[];
        verificationFile: string;
        points: {
            pullRequest: number;
            documentation: number;
            issue: number;
            maxSizeMultiplier: number;
        };
    };
    nextEpoch: { number: number; startsAt: string; endsAt: string };
    contract: { address: string | null; chainId: number };
    repositories: {
        name: string;
        url: string;
        verified: boolean;
        weight: number;
    }[];
    leaders: {
        login: string;
        points: number;
        contributions: number;
        walletLinked: boolean;
    }[];
    epochs: {
        number: number;
        startsAt: string;
        endsAt: string;
        status: 'built' | 'published';
        allocatedRaw: string;
        decimals: number;
        root: string | null;
        publishTx: string | null;
    }[];
    me: {
        github: { login: string | null } | null;
        linkUrl: string;
        wallet: string | null;
        pendingPoints: number;
        contributions: ContributionRow[];
    } | null;
};

export type ContributionClaim = {
    epoch: number;
    asset: string;
    decimals: number;
    amountRaw: string;
    points: number;
    root: string;
    proof: string[];
    claimedAt: string | null;
};

const ABI = [
    'function claim(uint256 epoch, address account, uint256 amount, bytes32[] proof)',
    'function claimed(uint256 epoch, address account) view returns (bool)',
];

export const fetchContributionBoard = (): Promise<ContributionBoard> =>
    sessionCall<ContributionBoard>(
        '/api/contributions',
        {},
        'The reward board is unreachable right now.',
    );

export const fetchContributionClaims = (
    address: string,
): Promise<{ claims: ContributionClaim[] }> =>
    sessionCall(
        `/api/contributions/claims/${address}`,
        {},
        'Claims are unreachable right now.',
    );

/** Which of these epochs the chain says are already paid to `address`. */
export const readClaimed = async (
    contract: string,
    address: string,
    epochs: number[],
): Promise<Set<number>> => {
    const rewards = new Contract(
        contract,
        ABI,
        new JsonRpcProvider(CYBERIA_PUBLIC_RPC),
    );
    const answers = await Promise.all(
        epochs.map(async (epoch) =>
            (await rewards.claimed(epoch, address)) ? epoch : null,
        ),
    );

    return new Set(answers.filter((epoch): epoch is number => epoch !== null));
};

export const claimReward = async (
    contract: string,
    address: string,
    claim: ContributionClaim,
): Promise<string> => {
    const provider = await ensureCyberiaNetwork();
    const signer = await provider.getSigner();
    const rewards = new Contract(contract, ABI, signer);
    const tx = await rewards.claim(
        claim.epoch,
        address,
        claim.amountRaw,
        claim.proof,
    );
    await tx.wait();

    return tx.hash as string;
};

export const formatAmount = (raw: string, decimals: number): string =>
    Number(formatUnits(raw, decimals)).toLocaleString(undefined, {
        maximumFractionDigits: 4,
    });
