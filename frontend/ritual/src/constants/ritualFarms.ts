/**
 * Cyberia ritual emission contracts.
 *
 * MasterChef on Cyberia (chainId 49406) mints ASH at 437 ASH/day total,
 * split between pools by allocPoint.
 *
 * Source: crypto/hardhat/deployments/cyberia-ash-emission.json
 */

import { ChainId } from '@uniswap/sdk';

export const RITUAL_MASTERCHEF_ADDRESS: { [chainId: number]: string } = {
  [ChainId.CYBERIA]: '0xd540DEa828567160FFDe5e792ca359aDD1f6B03D',
};

/**
 * Multicall3 on Cyberia — used by the farm page to batch all per-pool reads
 * into a couple of RPC round-trips. Without it, the ~20 pools × ~10 reads each
 * are issued one HTTP request at a time and never finish within a ~1s block.
 */
export const RITUAL_MULTICALL3_ADDRESS: { [chainId: number]: string } = {
  [ChainId.CYBERIA]: '0x176C70dD7CF17056596D8c4C7E2b1f2537df978F',
};

/** Approx daily emission. 437 ASH/day across all pools combined. */
export const RITUAL_TOTAL_DAILY_ASH = 437;

/** Block time on Cyberia (~1 s) — used to convert rewardPerBlock to per-day. */
export const RITUAL_BLOCK_TIME_SECONDS = 1;

/**
 * Reward token (ASH) and the stablecoins used as USD price anchors when
 * deriving token/LP prices from on-chain AMM reserves. All lowercased so they
 * can be compared directly against pair.token0()/token1() results.
 */
export const RITUAL_ASH_ADDRESS = '0x992Fca0a89DD95afb17751f6CC233Adb9B089df5'.toLowerCase();

/** Tokens worth $1 — the roots of the price-propagation graph. */
export const RITUAL_USD_ANCHORS: { [address: string]: number } = {
  ['0xdc25597B19799010047F17e9591EFE08EFd40077'.toLowerCase()]: 1, // USDC
  ['0x94845aF24a3E431593A2b941b2b31836dE45185D'.toLowerCase()]: 1, // USDT
};

export interface RitualFarmPool {
  pid: number;
  /** LP token address (or ASH itself for the solo pool). */
  lpToken: string;
  /** Display label. */
  label: string;
  /** Short hint shown next to the label. */
  description: string;
  /** Whether the staked token is itself the reward token. */
  isSolo: boolean;
  /** Token logos shown on the pool card (paths served from /public). */
  icons: string[];
  /**
   * Retired pool: its allocPoint has been zeroed on-chain (via
   * scripts/set-farm-alloc.ts) so it no longer earns ASH. MasterChef pools
   * cannot be removed, and stakers may still have LP locked in them, so we keep
   * the entry but hide it from the main /farm list. Retired pools are shown on
   * the withdraw-only /farm-empty page so stakers can pull their tokens out.
   */
  retired?: boolean;
}

export const RITUAL_FARM_POOLS: { [chainId: number]: RitualFarmPool[] } = {
  [ChainId.CYBERIA]: [
    {
      pid: 0,
      lpToken: '0x992Fca0a89DD95afb17751f6CC233Adb9B089df5', // ASH
      label: 'ASH',
      description: 'Stake ASH directly',
      isSolo: true,
      icons: ['/ash.png'],
    },
    {
      pid: 1,
      lpToken: '0xB3b6d8f38beC836e5629848223f1848A324188f0', // ASH/WCYBER LP
      label: 'ASH / WCYBER LP',
      description: 'Stake LP from the ASH/WCYBER pair',
      isSolo: false,
      icons: ['/ash.png', '/CYBER.png'],
    },
    {
      pid: 2,
      lpToken: '0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA', // CYBER.sol
      label: 'CYBER.sol',
      description: 'Retired — withdraw only',
      isSolo: true,
      icons: ['/CYBER.png'],
      retired: true,
    },
    {
      pid: 3,
      lpToken: '0x7D8e23e33c6680D5C45CA2deb8A85CcA0fe283F4', // CYBER.sol/CYBER LP
      label: 'CYBER.sol / CYBER LP',
      description: 'Retired — withdraw only',
      isSolo: false,
      icons: ['/CYBER.png', '/cyberia.png'],
      retired: true,
    },
    {
      pid: 4,
      lpToken: '0x4491A41C7D75c15cEbC7a321e392fcD57ADeABe8', // USDT/USDC LP
      label: 'USDT / USDC LP',
      description: 'Stake LP from the USDT/USDC pair',
      isSolo: false,
      icons: ['/usdt.svg', '/usdc.svg'],
    },
    {
      pid: 5,
      lpToken: '0x07b935a3Ba330Cb3Bd56B43F1032b57d3Ae0e04f', // CYBER/USDT LP
      label: 'CYBER / USDT LP',
      description: 'Stake LP from the CYBER/USDT pair',
      isSolo: false,
      icons: ['/cyberia.png', '/usdt.svg'],
    },
    {
      pid: 6,
      lpToken: '0x79B039b5E146E878683039D9387E212afc9FFC85', // CYBER/USDC LP
      label: 'CYBER / USDC LP',
      description: 'Stake LP from the CYBER/USDC pair',
      isSolo: false,
      icons: ['/cyberia.png', '/usdc.svg'],
    },
    {
      pid: 7,
      lpToken: '0xBc9cbe6B1876480D094221eb32C9887df4E62ea6', // SOL/CYBER.sol LP
      label: 'SOL / CYBER.sol LP',
      description: 'Retired — withdraw only',
      isSolo: false,
      icons: ['/sol.svg', '/CYBER.png'],
      retired: true,
    },
    {
      pid: 8,
      lpToken: '0x86199CD222E0d3412b77323772B4D1cFd0a35242', // MINE/LAIN LP
      label: 'MINE / LAIN LP',
      description: 'Stake LP from the MINE/LAIN pair',
      isSolo: false,
      icons: ['/mine.jpg', '/lain.jpg'],
    },
    {
      pid: 9,
      lpToken: '0x2AC6779E02ED515360a984A52441ba77fa7e3cAB', // CYBER/HATCHER LP
      label: 'CYBER / HATCHER LP',
      description: 'Stake LP from the CYBER/HATCHER pair',
      isSolo: false,
      icons: ['/cyberia.png', '/hatcher.jpg'],
    },
    {
      pid: 10,
      lpToken: '0x9298d13f57D1e5bD14C443144b500aaa210a1175', // CYBER/LAIN LP
      label: 'CYBER / LAIN LP',
      description: 'Stake LP from the CYBER/LAIN pair',
      isSolo: false,
      icons: ['/cyberia.png', '/lain.jpg'],
    },
    {
      pid: 11,
      lpToken: '0xF18bA050eFF63B2be2D244A423691D44BDDeF60d', // CYBER/MINE LP
      label: 'CYBER / MINE LP',
      description: 'Stake LP from the CYBER/MINE pair',
      isSolo: false,
      icons: ['/cyberia.png', '/mine.jpg'],
    },
    {
      pid: 12,
      lpToken: '0x7598B5A2421E7A9cA443368DB939Ec860Dc9d536', // CYBER/GOAL LP
      label: 'CYBER / GOAL LP',
      description: 'Stake LP from the CYBER/GOAL pair',
      isSolo: false,
      icons: ['/cyberia.png', '/goal.webp'],
    },
    {
      pid: 13,
      lpToken: '0xda176FEd5D6D1e1eB8C37556A01678f9e18B941F', // YTN/CYBER LP
      label: 'YTN / CYBER LP',
      description: 'Stake LP from the YTN/CYBER pair',
      isSolo: false,
      icons: ['/Yenten-logo.png', '/cyberia.png'],
    },
    {
      pid: 14,
      lpToken: '0x828b0c5D46CfDf4Adc78E2cB4139547aca56845c', // KRSQ/CYBER LP
      label: 'KRSQ / CYBER LP',
      description: 'Stake LP from the KRSQ/CYBER pair',
      isSolo: false,
      icons: ['/KARASIQUE.webp', '/cyberia.png'],
    },
    {
      pid: 15,
      lpToken: '0xb6184A51C0fAa2810D4A8Eb8C25bB18CB0bD4E33', // TRX/CYBER LP
      label: 'TRX / CYBER LP',
      description: 'Stake LP from the TRX/CYBER pair',
      isSolo: false,
      icons: ['/tron.svg', '/cyberia.png'],
    },
    {
      pid: 16,
      lpToken: '0x86dC072E44556c4F5cE948b94368b6631bCB0332', // CYBER/XMR LP
      label: 'CYBER / XMR LP',
      description: 'Stake LP from the CYBER/XMR pair',
      isSolo: false,
      icons: ['/cyberia.png', '/monero.svg'],
    },
    {
      pid: 17,
      lpToken: '0x1DF2329f6b9E94f9fdB5D76ea006DffF70C378Ec', // LTC/CYBER LP
      label: 'LTC / CYBER LP',
      description: 'Stake LP from the LTC/CYBER pair',
      isSolo: false,
      icons: ['/ltc.svg', '/cyberia.png'],
    },
    {
      pid: 18,
      lpToken: '0x144211C1476e1Da2eD55B19ff25d1ea18AA75aBC', // CYBER/BTC LP
      label: 'CYBER / BTC LP',
      description: 'Stake LP from the CYBER/BTC pair',
      isSolo: false,
      icons: ['/cyberia.png', '/btc.svg'],
    },
    {
      pid: 19,
      lpToken: '0xF94a92ED03fB44578f5246920D7fc1463Df2cF6D', // CYBER/SILVER LP
      label: 'CYBER / SILVER LP',
      description: 'Stake LP from the CYBER/SILVER pair',
      isSolo: false,
      icons: ['/cyberia.png', '/silver.png'],
    },
    {
      pid: 20,
      lpToken: '0x64252D0d9D9d2f27146c01CA15dD8680ACFFdEa2', // CYBER/ETH LP
      label: 'CYBER / ETH LP',
      description: 'Stake LP from the CYBER/ETH pair',
      isSolo: false,
      icons: ['/cyberia.png', '/ethereum-eth-logo-colored.svg'],
    },
    {
      pid: 21,
      lpToken: '0x28521507A465Da62B73348C9FBB5561cCB1f311c', // BTC/ETH LP
      label: 'BTC / ETH LP',
      description: 'Stake LP from the BTC/ETH pair',
      isSolo: false,
      icons: ['/btc.svg', '/ethereum-eth-logo-colored.svg'],
    },
  ],
};
