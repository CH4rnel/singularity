package church.cyberia.land;

import java.util.HashSet;
import java.util.Set;
import java.util.regex.Pattern;
import org.bukkit.World;
import org.bukkit.command.PluginCommand;
import org.bukkit.plugin.java.JavaPlugin;

public final class CyberiaLandPlugin extends JavaPlugin {
    private static final Pattern ADDRESS = Pattern.compile("0x[0-9a-fA-F]{40}");
    private LandRpcClient rpc;
    private WalletLinks walletLinks;
    private Set<String> protectedWorlds = Set.of();
    private String contractAddress;
    private String explorerUrl;
    private boolean failClosed;

    @Override
    public void onEnable() {
        saveDefaultConfig();
        walletLinks = new WalletLinks(this);
        if (!loadLandConfig()) {
            getServer().getPluginManager().disablePlugin(this);
            return;
        }

        LandCommand command = new LandCommand(this);
        PluginCommand land = getCommand("land");
        if (land == null) {
            throw new IllegalStateException("land command missing from plugin.yml");
        }
        land.setExecutor(command);
        land.setTabCompleter(command);
        getServer().getPluginManager().registerEvents(new LandProtectionListener(this), this);
        getLogger().info("Protecting " + protectedWorlds + " with worldId " + rpc.worldIdHex());
    }

    @Override
    public void onDisable() {
        if (rpc != null) rpc.close();
    }

    void reloadLandConfig() {
        reloadConfig();
        LandRpcClient old = rpc;
        if (loadLandConfig() && old != null) old.close();
    }

    private boolean loadLandConfig() {
        String newContract = getConfig().getString("contract-address", "").trim();
        if (!ADDRESS.matcher(newContract).matches()) {
            getLogger().severe("Set a deployed MinecraftLand contract-address in plugins/CyberiaLand/config.yml");
            return false;
        }
        String rpcUrl = getConfig().getString("rpc-url", "https://rpc.cyberia.church");
        String worldKey = getConfig().getString("world-key", "cyberia-survival-v1");
        int cacheSeconds = Math.max(1, getConfig().getInt("cache-seconds", 15));
        int timeoutSeconds = Math.max(1, getConfig().getInt("rpc-timeout-seconds", 4));

        LandRpcClient replacement = new LandRpcClient(
            rpcUrl,
            newContract,
            worldKey,
            cacheSeconds,
            timeoutSeconds,
            getLogger()
        );
        rpc = replacement;
        contractAddress = newContract;
        explorerUrl = getConfig().getString("explorer-url", "https://explorer.cyberia.church").replaceAll("/+$", "");
        failClosed = getConfig().getBoolean("fail-closed", true);
        protectedWorlds = new HashSet<>(getConfig().getStringList("protected-worlds"));
        return true;
    }

    boolean isProtectedWorld(World world) {
        return protectedWorlds.contains(world.getName());
    }

    LandRpcClient rpc() {
        return rpc;
    }

    WalletLinks walletLinks() {
        return walletLinks;
    }

    String contractAddress() {
        return contractAddress;
    }

    String explorerUrl() {
        return explorerUrl;
    }

    boolean failClosed() {
        return failClosed;
    }
}
