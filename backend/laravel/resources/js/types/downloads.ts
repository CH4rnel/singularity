export type DownloadBuild = {
    id: string;
    platform: string;
    /** The file this platform's main button points at. */
    primary: boolean;
    file: string;
    url: string;
    /** Bytes, or null when the release could not be read. */
    size: number | null;
};

export type DownloadCatalog = {
    version: string | null;
    publishedAt: string | null;
    releaseUrl: string;
    repoUrl: string;
    checksumsUrl: string | null;
    /**
     * `published` — the files below come from a release;
     * `none` — GitHub answered and no app release exists yet (no files);
     * `unknown` — GitHub could not be read, so the permanent URLs are offered
     * without a version.
     */
    status: 'published' | 'none' | 'unknown';
    builds: DownloadBuild[];
};
