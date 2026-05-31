import type { User } from './auth';

export type Dao = {
    id: number;
    address: string;
    name: string;
    proposals_count?: number;
    created_at: string;
    updated_at: string;
};

export type Proposal = {
    id: number;
    dao_id: number;
    user_id: number;
    title: string;
    description: string | null;
    status: 'open' | 'closed';
    dao?: Dao;
    user?: User;
    comments?: ProposalComment[];
    votes?: ProposalVote[];
    comments_count?: number;
    power_for?: string;
    power_against?: string;
    created_at: string;
    updated_at: string;
};

export type ProposalComment = {
    id: number;
    proposal_id: number;
    user_id: number;
    body: string;
    user?: User;
    created_at: string;
    updated_at: string;
};

export type ProposalVote = {
    id: number;
    proposal_id: number;
    user_id: number;
    wallet_address: string;
    voting_power: string;
    support: boolean;
    user?: User;
    created_at: string;
    updated_at: string;
};
