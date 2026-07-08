import type { User } from './auth';

export type Dao = {
    id: number;
    user_id?: number | null;
    address: string;
    name: string;
    proposals_count?: number;
    created_at: string;
    updated_at: string;
};

export type Reaction = {
    id: number;
    user_id: number;
    reactable_type: string;
    reactable_id: number;
    emoji: string;
};

export type Proposal = {
    id: number;
    dao_id: number;
    user_id: number;
    title: string;
    description: string | null;
    description_html: string | null;
    ends_at: string | null;
    /** Computed server-side from ends_at. */
    status: 'open' | 'closed';
    dao?: Dao;
    user?: User;
    comments?: ProposalComment[];
    votes?: ProposalVote[];
    reactions?: Reaction[];
    comments_count?: number;
    votes_count?: number;
    power_for?: string;
    power_against?: string;
    created_at: string;
    updated_at: string;
};

export type ProposalComment = {
    id: number;
    proposal_id: number;
    user_id: number;
    parent_id: number | null;
    body: string;
    body_html: string | null;
    user?: User;
    replies?: ProposalComment[];
    reactions?: Reaction[];
    proposal?: Pick<Proposal, 'id' | 'title'>;
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
    proposal?: Pick<Proposal, 'id' | 'title'>;
    created_at: string;
    updated_at: string;
};

export type ActivityType = 'proposal.created' | 'vote.cast' | 'comment.posted';

export type Activity = {
    id: number;
    type: ActivityType;
    user_id: number;
    dao_id: number | null;
    user?: User;
    dao?: Pick<Dao, 'id' | 'name'> | null;
    subject: Proposal | ProposalComment | ProposalVote | null;
    created_at: string;
};
