package church.cyberia.land;

import java.io.File;
import java.io.IOException;
import java.time.Instant;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;
import org.bukkit.configuration.file.YamlConfiguration;
import org.bukkit.entity.Player;
import org.bukkit.plugin.java.JavaPlugin;

final class WalletLinks {
    record Challenge(String address, String message, long expiresAt) {}

    private static final long CHALLENGE_SECONDS = 300;
    private final JavaPlugin plugin;
    private final File file;
    private final YamlConfiguration wallets;
    private final Map<UUID, Challenge> challenges = new ConcurrentHashMap<>();

    WalletLinks(JavaPlugin plugin) {
        this.plugin = plugin;
        this.file = new File(plugin.getDataFolder(), "wallets.yml");
        this.wallets = YamlConfiguration.loadConfiguration(file);
    }

    Optional<String> wallet(UUID playerId) {
        return Optional.ofNullable(wallets.getString(playerId.toString()));
    }

    Challenge issue(Player player, String address) {
        long expiresAt = Instant.now().getEpochSecond() + CHALLENGE_SECONDS;
        String message = String.join("\n",
            "Cyberia Minecraft wallet link",
            "Player: " + player.getUniqueId(),
            "Address: " + address.toLowerCase(),
            "Nonce: " + UUID.randomUUID(),
            "Expires: " + expiresAt
        );
        Challenge challenge = new Challenge(address.toLowerCase(), message, expiresAt);
        challenges.put(player.getUniqueId(), challenge);
        return challenge;
    }

    boolean verify(Player player, String signature) {
        Challenge challenge = challenges.get(player.getUniqueId());
        if (challenge == null || challenge.expiresAt < Instant.now().getEpochSecond()) {
            challenges.remove(player.getUniqueId());
            return false;
        }
        if (!SignatureVerifier.verifyPersonalSign(challenge.message, signature, challenge.address)) {
            return false;
        }
        wallets.set(player.getUniqueId().toString(), challenge.address);
        challenges.remove(player.getUniqueId());
        save();
        return true;
    }

    void unlink(UUID playerId) {
        challenges.remove(playerId);
        wallets.set(playerId.toString(), null);
        save();
    }

    private void save() {
        try {
            wallets.save(file);
        } catch (IOException error) {
            plugin.getLogger().severe("Could not save wallets.yml: " + error.getMessage());
        }
    }
}
