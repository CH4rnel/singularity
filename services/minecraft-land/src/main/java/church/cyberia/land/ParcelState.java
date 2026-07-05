package church.cyberia.land;

record ParcelState(Kind kind, long tokenId, String owner) {
    enum Kind {
        UNCLAIMED,
        CLAIMED,
        LOADING,
        ERROR
    }

    static ParcelState unclaimed() {
        return new ParcelState(Kind.UNCLAIMED, 0, null);
    }

    static ParcelState claimed(long tokenId, String owner) {
        return new ParcelState(Kind.CLAIMED, tokenId, owner.toLowerCase());
    }

    static ParcelState loading() {
        return new ParcelState(Kind.LOADING, 0, null);
    }

    static ParcelState error() {
        return new ParcelState(Kind.ERROR, 0, null);
    }
}
