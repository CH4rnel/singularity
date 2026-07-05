package church.cyberia.land;

import java.util.List;
import java.util.Locale;
import java.util.regex.Pattern;
import net.kyori.adventure.text.Component;
import net.kyori.adventure.text.event.ClickEvent;
import net.kyori.adventure.text.format.NamedTextColor;
import org.bukkit.Chunk;
import org.bukkit.command.Command;
import org.bukkit.command.CommandExecutor;
import org.bukkit.command.CommandSender;
import org.bukkit.command.TabCompleter;
import org.bukkit.entity.Player;
import org.jetbrains.annotations.NotNull;

final class LandCommand implements CommandExecutor, TabCompleter {
    private static final Pattern ADDRESS = Pattern.compile("0x[0-9a-fA-F]{40}");
    private static final Pattern SIGNATURE = Pattern.compile("0x[0-9a-fA-F]{130}");
    private final CyberiaLandPlugin plugin;

    LandCommand(CyberiaLandPlugin plugin) {
        this.plugin = plugin;
    }

    @Override
    public boolean onCommand(
        @NotNull CommandSender sender,
        @NotNull Command command,
        @NotNull String label,
        @NotNull String[] args
    ) {
        String action = args.length == 0 ? "info" : args[0].toLowerCase(Locale.ROOT);
        if (action.equals("reload")) {
            if (!sender.hasPermission("cyberialand.reload")) {
                sender.sendMessage(Component.text("Нет прав.", NamedTextColor.RED));
                return true;
            }
            plugin.reloadLandConfig();
            sender.sendMessage(Component.text("CyberiaLand config перезагружен.", NamedTextColor.GREEN));
            return true;
        }
        if (!(sender instanceof Player player)) {
            sender.sendMessage("Player-only command (except /land reload).");
            return true;
        }
        if (!plugin.isProtectedWorld(player.getWorld())) {
            player.sendMessage(Component.text("В этом мире NFT-участки отключены.", NamedTextColor.YELLOW));
            return true;
        }

        return switch (action) {
            case "info" -> info(player);
            case "link" -> link(player, args);
            case "verify" -> verify(player, args);
            case "unlink" -> unlink(player);
            default -> {
                player.sendMessage(Component.text("/land <info|link 0x…|verify 0x…|unlink>", NamedTextColor.YELLOW));
                yield true;
            }
        };
    }

    private boolean info(Player player) {
        Chunk chunk = player.getLocation().getChunk();
        String linked = plugin.walletLinks().wallet(player.getUniqueId()).orElse("не привязан");
        player.sendMessage(Component.text("Участок: chunk " + chunk.getX() + ", " + chunk.getZ(), NamedTextColor.AQUA));
        player.sendMessage(Component.text("worldId: " + plugin.rpc().worldIdHex(), NamedTextColor.GRAY));
        player.sendMessage(Component.text("Кошелёк: " + linked, NamedTextColor.GRAY));

        plugin.rpc().load(chunk.getX(), chunk.getZ()).thenAccept(state -> plugin.getServer().getScheduler().runTask(plugin, () -> {
            if (!player.isOnline()) return;
            switch (state.kind()) {
                case UNCLAIMED -> player.sendMessage(Component.text("Свободный участок.", NamedTextColor.GREEN));
                case CLAIMED -> {
                    boolean yours = linked.equalsIgnoreCase(state.owner());
                    player.sendMessage(Component.text(
                        "NFT #" + state.tokenId() + " · " + state.owner() + (yours ? " · ваш" : ""),
                        yours ? NamedTextColor.GREEN : NamedTextColor.GOLD
                    ));
                    String url = plugin.explorerUrl() + "/token/" + plugin.contractAddress() + "/instance/" + state.tokenId();
                    player.sendMessage(Component.text("Открыть NFT", NamedTextColor.AQUA)
                        .clickEvent(ClickEvent.openUrl(url)));
                }
                case ERROR -> player.sendMessage(Component.text("RPC временно недоступен.", NamedTextColor.RED));
                case LOADING -> { }
            }
        }));
        return true;
    }

    private boolean link(Player player, String[] args) {
        if (args.length != 2 || !ADDRESS.matcher(args[1]).matches()) {
            player.sendMessage(Component.text("Использование: /land link 0xАдрес", NamedTextColor.YELLOW));
            return true;
        }
        WalletLinks.Challenge challenge = plugin.walletLinks().issue(player, args[1]);
        player.sendMessage(Component.text("Подпишите personal_sign это точное сообщение (действует 5 минут):", NamedTextColor.GOLD));
        player.sendMessage(Component.text(challenge.message(), NamedTextColor.AQUA)
            .clickEvent(ClickEvent.copyToClipboard(challenge.message())));
        player.sendMessage(Component.text("Затем: /land verify 0xПодпись", NamedTextColor.GRAY));
        return true;
    }

    private boolean verify(Player player, String[] args) {
        if (args.length != 2 || !SIGNATURE.matcher(args[1]).matches()) {
            player.sendMessage(Component.text("Использование: /land verify 0xПодпись", NamedTextColor.YELLOW));
            return true;
        }
        if (!plugin.walletLinks().verify(player, args[1])) {
            player.sendMessage(Component.text("Подпись неверна или challenge истёк.", NamedTextColor.RED));
            return true;
        }
        player.sendMessage(Component.text("Кошелёк подтверждён.", NamedTextColor.GREEN));
        return true;
    }

    private boolean unlink(Player player) {
        plugin.walletLinks().unlink(player.getUniqueId());
        player.sendMessage(Component.text("Кошелёк отвязан.", NamedTextColor.GREEN));
        return true;
    }

    @Override
    public List<String> onTabComplete(
        @NotNull CommandSender sender,
        @NotNull Command command,
        @NotNull String alias,
        @NotNull String[] args
    ) {
        if (args.length == 1) {
            return List.of("info", "link", "verify", "unlink", "reload").stream()
                .filter(item -> item.startsWith(args[0].toLowerCase(Locale.ROOT)))
                .toList();
        }
        return List.of();
    }
}
