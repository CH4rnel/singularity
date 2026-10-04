<script setup lang="ts">
import {
    ArrowLeftRight,
    CalendarCheck,
    Droplets,
    Fuel,
    Landmark,
    LayoutGrid,
    PieChart,
    Settings,
} from 'lucide-vue-next';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { walletMessages } from '@/lib/walletMessages';

/**
 * Everything the portfolio no longer carries on its face.
 *
 * Nothing here was deleted from the wallet — it was moved one level down. The
 * portfolio used to end in eleven destinations, each drawn as a card or a tile
 * with a second line explaining what it was for, which is a page of captions
 * under the balances. Seven of those are the ones people actually open and they
 * stayed, as names in a grid; the rest are here, as a list.
 *
 * A row is an icon, a name, and a value only where there is one — the amber dot
 * on Security is the same fact the portfolio's one warning row states, shown
 * here because this screen is where somebody arrives looking for it.
 *
 * The fine print at the bottom is where the price sources went. They were
 * printed under the total on every visit, to everyone, saying nothing about
 * that wallet; they belong on the screen somebody reads when they want to know
 * where a number came from.
 */

defineProps<{
    wallet: MultiWallet;
}>();

const emit = defineEmits<{
    back: [];
    daily: [];
    analytics: [];
    crosschain: [];
    liquidity: [];
    browse: [];
    dao: [];
    gas: [];
    preferences: [];
}>();

const { t } = useLocale(walletMessages);
</script>

<template>
    <div class="cw-stack">
        <button type="button" class="cw-back" @click="emit('back')">
            ← {{ t('navPortfolio') }}
        </button>

        <h2 class="cw-title" style="margin: 22px 0 18px">{{ t('navMore') }}</h2>

        <div class="cw-stack" style="gap: 0">
            <button type="button" class="cw-line-row" @click="emit('daily')">
                <CalendarCheck
                    :size="20"
                    :stroke-width="1.5"
                    aria-hidden="true"
                />
                <span style="flex: 1">{{ t('dailyTitle') }}</span>
            </button>
            <button
                type="button"
                class="cw-line-row"
                @click="emit('analytics')"
            >
                <PieChart :size="20" :stroke-width="1.5" aria-hidden="true" />
                <span style="flex: 1">{{ t('navAnalytics') }}</span>
            </button>
            <button
                type="button"
                class="cw-line-row"
                @click="emit('crosschain')"
            >
                <ArrowLeftRight
                    :size="20"
                    :stroke-width="1.5"
                    aria-hidden="true"
                />
                <span style="flex: 1">{{ t('crossTile') }}</span>
            </button>
            <!--
              Where an LP token comes from. It sits next to the cross-chain
              swap rather than under Earn, because the farm is what you do
              with a position and this is where the position is made.
            -->
            <button
                type="button"
                class="cw-line-row"
                @click="emit('liquidity')"
            >
                <Droplets :size="20" :stroke-width="1.5" aria-hidden="true" />
                <span style="flex: 1">{{ t('poolTitle') }}</span>
            </button>
            <button type="button" class="cw-line-row" @click="emit('dao')">
                <Landmark :size="20" :stroke-width="1.5" aria-hidden="true" />
                <span style="flex: 1">{{ t('dao') }}</span>
            </button>
            <button type="button" class="cw-line-row" @click="emit('browse')">
                <LayoutGrid :size="20" :stroke-width="1.5" aria-hidden="true" />
                <span style="flex: 1">{{ t('browseTitle') }}</span>
            </button>
            <button type="button" class="cw-line-row" @click="emit('gas')">
                <Fuel :size="20" :stroke-width="1.5" aria-hidden="true" />
                <span style="flex: 1">{{ t('gasStation') }}</span>
            </button>
        </div>

        <div class="cw-stack" style="gap: 0; margin-top: 26px">
            <button
                type="button"
                class="cw-line-row"
                @click="emit('preferences')"
            >
                <Settings :size="20" :stroke-width="1.5" aria-hidden="true" />
                <span style="flex: 1">{{ t('navPreferences') }}</span>
            </button>
        </div>

        <p
            class="cw-label"
            style="
                margin-top: 30px;
                color: var(--cw-faint);
                text-transform: none;
                letter-spacing: 0;
            "
        >
            {{ t('priceSource') }}
        </p>
    </div>
</template>
