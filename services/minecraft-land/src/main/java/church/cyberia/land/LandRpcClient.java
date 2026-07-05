package church.cyberia.land;

import java.math.BigInteger;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.TimeUnit;
import java.util.logging.Logger;
import okhttp3.OkHttpClient;
import org.web3j.abi.FunctionEncoder;
import org.web3j.abi.FunctionReturnDecoder;
import org.web3j.abi.TypeReference;
import org.web3j.abi.datatypes.Address;
import org.web3j.abi.datatypes.Function;
import org.web3j.abi.datatypes.generated.Bytes32;
import org.web3j.abi.datatypes.generated.Int32;
import org.web3j.abi.datatypes.generated.Uint256;
import org.web3j.crypto.Hash;
import org.web3j.protocol.Web3j;
import org.web3j.protocol.core.DefaultBlockParameterName;
import org.web3j.protocol.core.methods.request.Transaction;
import org.web3j.protocol.http.HttpService;
import org.web3j.utils.Numeric;

final class LandRpcClient implements AutoCloseable {
    private record ParcelKey(int chunkX, int chunkZ) {}
    private record CacheEntry(ParcelState state, long expiresAtMillis) {}

    private final Web3j web3j;
    private final String contractAddress;
    private final byte[] worldId;
    private final long cacheMillis;
    private final Logger logger;
    private final Map<ParcelKey, CacheEntry> cache = new ConcurrentHashMap<>();
    private final Map<ParcelKey, CompletableFuture<ParcelState>> inFlight = new ConcurrentHashMap<>();

    LandRpcClient(
        String rpcUrl,
        String contractAddress,
        String worldKey,
        int cacheSeconds,
        int timeoutSeconds,
        Logger logger
    ) {
        OkHttpClient httpClient = HttpService.getOkHttpClientBuilder()
            .readTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .connectTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .build();
        HttpService service = new HttpService(rpcUrl, httpClient, false);
        this.web3j = Web3j.build(service);
        this.contractAddress = contractAddress;
        this.worldId = Numeric.hexStringToByteArray(Hash.sha3String(worldKey));
        this.cacheMillis = cacheSeconds * 1000L;
        this.logger = logger;
    }

    String worldIdHex() {
        return Numeric.toHexString(worldId);
    }

    ParcelState cachedOrLoad(int chunkX, int chunkZ) {
        ParcelKey key = new ParcelKey(chunkX, chunkZ);
        CacheEntry found = cache.get(key);
        if (found != null && found.expiresAtMillis > System.currentTimeMillis()) {
            return found.state;
        }
        load(chunkX, chunkZ);
        return ParcelState.loading();
    }

    CompletableFuture<ParcelState> load(int chunkX, int chunkZ) {
        ParcelKey key = new ParcelKey(chunkX, chunkZ);
        CacheEntry found = cache.get(key);
        if (found != null && found.expiresAtMillis > System.currentTimeMillis()) {
            return CompletableFuture.completedFuture(found.state);
        }
        return inFlight.computeIfAbsent(key, ignored -> query(chunkX, chunkZ)
            .exceptionally(error -> {
                logger.warning("Land RPC lookup failed for chunk " + chunkX + "," + chunkZ + ": " + error.getMessage());
                return ParcelState.error();
            })
            .thenApply(state -> {
                long ttl = state.kind() == ParcelState.Kind.ERROR ? Math.min(cacheMillis, 3000L) : cacheMillis;
                cache.put(key, new CacheEntry(state, System.currentTimeMillis() + ttl));
                return state;
            })
            .whenComplete((state, error) -> inFlight.remove(key)));
    }

    void clearCache() {
        cache.clear();
    }

    private CompletableFuture<ParcelState> query(int chunkX, int chunkZ) {
        Function parcelAt = new Function(
            "parcelAt",
            List.of(
                new Bytes32(worldId),
                new Int32(BigInteger.valueOf(chunkX)),
                new Int32(BigInteger.valueOf(chunkZ))
            ),
            List.of(new TypeReference<Uint256>() {})
        );

        return ethCall(parcelAt).thenCompose(result -> {
            BigInteger tokenId = (BigInteger) result.getValue();
            if (tokenId.signum() == 0) {
                return CompletableFuture.completedFuture(ParcelState.unclaimed());
            }
            Function ownerOf = new Function(
                "ownerOf",
                List.of(new Uint256(tokenId)),
                List.of(new TypeReference<Address>() {})
            );
            return ethCall(ownerOf).thenApply(owner -> ParcelState.claimed(tokenId.longValueExact(), owner.getValue().toString()));
        });
    }

    private CompletableFuture<org.web3j.abi.datatypes.Type<?>> ethCall(Function function) {
        String data = FunctionEncoder.encode(function);
        Transaction transaction = Transaction.createEthCallTransaction(null, contractAddress, data);
        return web3j.ethCall(transaction, DefaultBlockParameterName.LATEST).sendAsync().thenApply(response -> {
            if (response.hasError()) {
                throw new IllegalStateException(response.getError().getMessage());
            }
            var decoded = FunctionReturnDecoder.decode(response.getValue(), function.getOutputParameters());
            if (decoded.isEmpty()) {
                throw new IllegalStateException("empty eth_call response");
            }
            return decoded.getFirst();
        });
    }

    @Override
    public void close() {
        web3j.shutdown();
    }
}
