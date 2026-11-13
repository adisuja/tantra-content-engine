// tantra: the public roadmap's data, as served by GET /api/roadmap and
// /roadmap.json (app/Tantra/Roadmap/Roadmap::payload). Kept free of app
// imports so the roadmap components can move to usetantra.com as they are.

export type RoadmapColumnKey =
    | 'q'
    | 'planned'
    | 'in_progress'
    | 'beta'
    | 'shipped';

export interface RoadmapColumn {
    key: RoadmapColumnKey;
    label: string;
    blurb: string;
    count: number;
}

export interface RoadmapProduct {
    key: string;
    label: string;
}

export interface RoadmapItem {
    id: string;
    title: string;
    summary: string | null;
    detail: string | null;
    column: RoadmapColumnKey;
    column_label: string;
    product: string;
    product_label: string;
    category: string | null;
    position: number;
    source: 'team' | 'request';
    requested_by: string | null;
    created_at: string | null;
    updated_at: string | null;
    shipped_at: string | null;
}

export interface RoadmapPayload {
    name: string;
    url: string;
    product: string | null;
    columns: RoadmapColumn[];
    products: RoadmapProduct[];
    categories: string[];
    items: RoadmapItem[];
    generated_at: string;
}
