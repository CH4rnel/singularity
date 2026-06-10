// Standalone types/enums extracted from the (deleted) v3 supply-liquidity UI
// so that the v3 mint state slice can keep working without dragging the v3
// pages back in.

export enum Presets {
  SAFE,
  RISK,
  NORMAL,
  FULL,
  STABLE,
  GAMMA_NARROW,
  GAMMA_WIDE,
  GAMMA_DYNAMIC,
  GAMMA_STABLE,
  STEER_WIDE,
  STEER_NARROW,
  STEER_STABLE,
  OUT_OF_RANGE,
}

export enum PresetProfits {
  VERY_LOW,
  LOW,
  MEDIUM,
  HIGH,
}

export interface IPresetArgs {
  type: Presets;
  min: number;
  max: number;
  address?: string;
  isReversed?: boolean;
  title?: string;
  risk?: PresetProfits;
  tokenStr?: string;
}

export interface IFeeTier {
  id: string;
  text: string;
  description: string;
}
