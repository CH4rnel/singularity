import type { Category } from './categories';

export type Link = {
    id: number;
    name: string;
    urls: string[];
    category_id: number | null;
    category?: Category;
    created_at: string;
    updated_at: string;
};