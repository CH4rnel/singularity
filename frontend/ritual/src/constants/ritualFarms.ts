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
      description: 'Stake CYBER.sol directly',
      isSolo: true,
      icons: ['/CYBER.png'],
    },
    {
      pid: 3,
      lpToken: '0x7D8e23e33c6680D5C45CA2deb8A85CcA0fe283F4', // CYBER.sol/CYBER LP
      label: 'CYBER.sol / CYBER LP',
      description: 'Stake LP from the CYBER.sol/CYBER pair',
      isSolo: false,
      icons: ['/CYBER.png', '/cyberia.png'],
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
    // pid 7 (SOL/CYBER.sol LP) retired: allocPoint zeroed on-chain via
    // scripts/set-farm-alloc.ts. The MasterChef pool still exists (pools cannot
    // be removed) but earns nothing, so it is hidden from the DEX farm list.
  ],
};
