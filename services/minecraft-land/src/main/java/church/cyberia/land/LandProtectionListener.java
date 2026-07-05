package church.cyberia.land;

import java.util.Map;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;
import org.bukkit.Chunk;
import org.bukkit.block.Block;
import org.bukkit.entity.Player;
import org.bukkit.event.EventHandler;
import org.bukkit.event.EventPriority;
import org.bukkit.event.Listener;
import org.bukkit.event.block.BlockBreakEvent;
import org.bukkit.event.block.BlockExplodeEvent;
import org.bukkit.event.block.BlockPlaceEvent;
import org.bukkit.event.entity.EntityChangeBlockEvent;
import org.bukkit.event.entity.EntityExplodeEvent;
import org.bukkit.event.player.PlayerBucketEmptyEvent;
import org.bukkit.event.player.PlayerBucketFillEvent;
import org.bukkit.event.player.PlayerInteractEvent;
import org.bukkit.event.player.PlayerMoveEvent;

final class LandProtectionListener implements Listener {
    private static final long MESSAGE_COOLDOWN_MILLIS = 1500;
    private final CyberiaLandPlugin plugin;
    private final Map<UUID, Long> lastMessage = new ConcurrentHashMap<>();

    LandProtectionListener(CyberiaLandPlugin plugin) {
        this.plugin = plugin;
    }

    @EventHandler(priority = EventPriority.HIGH, ignoreCancelled = true)
    public void onBreak(BlockBreakEvent event) {
        if (!canModify(event.getPlayer(), event.getBlock())) event.setCancelled(true);
    }

    @EventHandler(priority = EventPriority.HIGH, ignoreCancelled = true)
    public void onPlace(BlockPlaceEvent event) {
        if (!canModify(event.getPlayer(), event.getBlock())) event.setCancelled(true);
    }

    @EventHandler(priority = EventPriority.HIGH, ignoreCancelled = true)
    public void onInteract(PlayerInteractEvent event) {
        Block clicked = event.getClickedBlock();
        if (clicked != null && !canModify(event.getPlayer(), clicked)) event.setCancelled(true);
    }

    @EventHandler(priority = EventPriority.HIGH, ignoreCancelled = true)
    public void onBucketEmpty(PlayerBucketEmptyEvent event) {
        if (!canModify(event.getPlayer(), event.getBlockClicked().getRelative(event.getBlockFace()))) {
            event.setCancelled(true);
        }
    }

    @EventHandler(priority = EventPriority.HIGH, ignoreCancelled = true)
    public void onBucketFill(PlayerBucketFillEvent event) {
        if (!canModify(event.getPlayer(), event.getBlockClicked())) event.setCancelled(true);
    }

    @EventHandler(priority = EventPriority.HIGH, ignoreCancelled = true)
    public void onEntityChangeBlock(EntityChangeBlockEvent event) {
        if (plugin.isProtectedWorld(event.getBlock().getWorld()) && isClaimedOrUnknown(event.getBlock())) {
            event.setCancelled(true);
        }
    }

    @EventHandler(priority = EventPriority.HIGH, ignoreCancelled = true)
    public void onEntityExplosion(EntityExplodeEvent event) {
        event.blockList().removeIf(this::isClaimedOrUnknown);
    }

    @EventHandler(priority = EventPriority.HIGH, ignoreCancelled = true)
    public void onBlockExplosion(BlockExplodeEvent event) {
        event.blockList().removeIf(this::isClaimedOrUnknown);
    }

    @EventHandler(priority = EventPriority.MONITOR, ignoreCancelled = true)
    public void onMove(PlayerMoveEvent event) {
        if (!event.hasChangedBlock() || !plugin.isProtectedWorld(event.getPlayer().getWorld())) return;
        Chunk from = event.getFrom().getChunk();
        Chunk to = event.getTo().getChunk();
        if (from.getX() != to.getX() || from.getZ() != to.getZ()) {
            plugin.rpc().load(to.getX(), to.getZ());
        }
    }

    private boolean canModify(Player player, Block block) {
        if (!plugin.isProtectedWorld(block.getWorld()) || player.hasPermission("cyberialand.bypass")) {
            return true;
        }
        ParcelState state = plugin.rpc().cachedOrLoad(block.getChunk().getX(), block.getChunk().getZ());
        if (state.kind() == ParcelState.Kind.UNCLAIMED) return true;
        if (state.kind() == ParcelState.Kind.CLAIMED) {
            boolean owner = plugin.walletLinks().wallet(player.getUniqueId())
                .map(wallet -> wallet.equalsIgnoreCase(state.owner()))
                .orElse(false);
            if (!owner) notify(player, "Этот NFT-участок принадлежит " + shortAddress(state.owner()) + ".");
            return owner;
        }
        if (!plugin.failClosed()) return true;
        notify(player, state.kind() == ParcelState.Kind.LOADING
            ? "Проверяю владельца участка, повторите действие."
            : "RPC недоступен: защита участка работает в закрытом режиме.");
        return false;
    }

    private boolean isClaimedOrUnknown(Block block) {
        if (!plugin.isProtectedWorld(block.getWorld())) return false;
        ParcelState state = plugin.rpc().cachedOrLoad(block.getChunk().getX(), block.getChunk().getZ());
        return state.kind() == ParcelState.Kind.CLAIMED
            || (plugin.failClosed() && state.kind() != ParcelState.Kind.UNCLAIMED);
    }

    private void notify(Player player, String message) {
        long now = System.currentTimeMillis();
        if (now - lastMessage.getOrDefault(player.getUniqueId(), 0L) < MESSAGE_COOLDOWN_MILLIS) return;
        lastMessage.put(player.getUniqueId(), now);
        player.sendActionBar(net.kyori.adventure.text.Component.text(
            message,
            net.kyori.adventure.text.format.NamedTextColor.RED
        ));
    }

    private static String shortAddress(String address) {
        return address.substring(0, 6) + "…" + address.substring(address.length() - 4);
    }
}
