import React, { useMemo } from 'react';
import { Box, Typography } from '@material-ui/core';
import { Skeleton } from '@material-ui/lab';
import { formatCompact } from 'utils';
import { useTranslation } from 'react-i18next';
import { ChainId } from '@uniswap/sdk';
import { useActiveWeb3React } from 'hooks';
import { getConfig } from '../../config/index';
import DragonLayerInfoCard from './TradingInfoCards/DragonLayerInfoCard';
import { useAnalyticsGlobalData } from 'hooks/useFetchAnalyticsData';
import { ZkEvmTvlInfoCard } from './TradingInfoCards/ZkEvmTvlInfoCard';
import { useNewLairInfo } from 'state/stake/hooks';
import { useUSDCPriceFromAddress } from 'utils/useUSDCPrice';
import { DLQUICK } from 'constants/v3/addresses';
import { useCyberiaDexStats } from 'hooks/useCyberiaDexStats';

const TradingInfo: React.FC = () => {
  const { chainId } = useActiveWeb3React();
  const chainIdToUse = chainId ?? ChainId.MATIC;
  const config = getConfig(chainIdToUse);
  const lairAvailable = config['lair']['newLair'];

  const { t } = useTranslation();
  const quickToken = DLQUICK[chainIdToUse];
  const v2 = config['v2'];
  const v3 = config['v3'];
  const lairInfo = useNewLairInfo(!lairAvailable);
  const {
    loading: loadingQuickPrice,
    price: quickPrice,
  } = useUSDCPriceFromAddress(quickToken?.address ?? '');

  const {
    isLoading: loadingV2GlobalData,
    data: globalData,
  } = useAnalyticsGlobalData('v2', chainId);

  const {
    isLoading: loadingV3GlobalData,
    data: v3GlobalData,
  } = useAnalyticsGlobalData('v3', chainId);

  const loading =
    (v2 ? loadingV2GlobalData : false) && (v3 ? loadingV3GlobalData : false);

  const dragonReward = useMemo(() => {
    if (lairInfo && quickPrice) {
      const newReward =
        Number(lairInfo.totalQuickBalance.toExact()) * quickPrice;

      return newReward;
    }
    return 0;
  }, [lairInfo, quickPrice]);

  const rewards = useMemo(() => {
    if (lairInfo && quickPrice) {
      const balance = Number(lairInfo.totalQuickBalance.toExact());
      if (balance > 0) {
        const newReward = balance * quickPrice;
        const formattedReward = formatCompact(newReward, 18, 3, 3);
        return formattedReward;
      }
      return '0';
    }
    return '0';
  }, [lairInfo, quickPrice]);

  // Cyberia has no analytics backend; derive what we can straight from chain.
  // Placed after all hooks above so the rules of hooks are respected.
  if (chainIdToUse === ChainId.CYBERIA) {
    return <CyberiaTradingInfo chainId={chainIdToUse} />;
  }

  return (
    <>
      <Box className='tradingSection'>
        <Typography
          className='text-uppercase'
          style={{ fontSize: '11px', fontWeight: 600 }}
        >
          {t('Total VALUE Locked')}
        </Typography>
        {loading ? (
          <Skeleton variant='rect' width={100} height={45} />
        ) : (
          <Box display='flex'>
            <h3>
              $
              {formatCompact(
                (v2 && globalData && globalData.totalLiquidityUSD
                  ? Number(globalData.totalLiquidityUSD)
                  : 0) +
                  (v3 && v3GlobalData && v3GlobalData.totalLiquidityUSD
                    ? Number(v3GlobalData.totalLiquidityUSD)
                    : 0) +
                  dragonReward,
              )}
              +
            </h3>
          </Box>
        )}
      </Box>
      <Box className='tradingSection'>
        <Typography
          className='text-uppercase'
          style={{ fontSize: '11px', fontWeight: 600 }}
        >
          {t('totalVolume')}
        </Typography>
        {loading ? (
          <Skeleton variant='rect' width={100} height={45} />
        ) : (
          <Box display='flex'>
            <h3>
              $
              {formatCompact(
                (v2 && globalData && globalData.totalVolumeUSD
                  ? Number(globalData.totalVolumeUSD)
                  : 0) +
                  (v3 && v3GlobalData && v3GlobalData.totalVolumeUSD
                    ? Number(v3GlobalData.totalVolumeUSD)
                    : 0) +
                  dragonReward,
              )}
              +
            </h3>
          </Box>
        )}
      </Box>
      <Box className='tradingSection'>
        <Typography
          className='text-uppercase'
          style={{ fontSize: '11px', fontWeight: 600 }}
        >
          {t('24hTradingVol')}
        </Typography>
        {loading ? (
          <Skeleton variant='rect' width={100} height={45} />
        ) : (
          <Box display='flex'>
            <h3>
              $
              {formatCompact(
                (v2 && globalData && globalData.oneDayVolumeUSD
                  ? Number(globalData.oneDayVolumeUSD)
                  : 0) +
                  (v3 && v3GlobalData && v3GlobalData.oneDayVolumeUSD
                    ? Number(v3GlobalData.oneDayVolumeUSD)
                    : 0),
              )}
            </h3>
          </Box>
        )}
      </Box>
      <Box className='tradingSection'>
        <Typography
          className='text-uppercase'
          style={{ fontSize: '11px', fontWeight: 600 }}
        >
          {t('24hRewardsDistributed')}
        </Typography>
        {loading ? (
          <Skeleton variant='rect' width={100} height={45} />
        ) : (
          <Box display='flex'>
            <h3>
              $
              {formatCompact(
                (v2 && globalData && globalData.oneDayTxns
                  ? Number(globalData.oneDayTxns)
                  : 0) +
                  (v3 && v3GlobalData && v3GlobalData.oneDayTxns
                    ? Number(v3GlobalData.oneDayTxns)
                    : 0),
              )}
            </h3>
          </Box>
        )}
      </Box>
      <Box className='tradingSection'>
        <Typography
          className='text-uppercase'
          style={{ fontSize: '11px', fontWeight: 600 }}
        >
          {t('24hTradingFees')}
        </Typography>
        {loading ? (
          <Skeleton variant='rect' width={100} height={45} />
        ) : (
          <Box display='flex'>
            <h3>
              $
              {formatCompact(
                (v2 && globalData && globalData.feesUSD
                  ? Number(globalData.feesUSD)
                  : 0) +
                  (v3 && v3GlobalData && v3GlobalData.feesUSD
                    ? Number(v3GlobalData.feesUSD)
                    : 0),
              )}
            </h3>
          </Box>
        )}
      </Box>
      <DragonLayerInfoCard chainId={chainIdToUse} config={config} />
      {chainIdToUse === ChainId.ZKEVM && <ZkEvmTvlInfoCard />}
    </>
  );
};

const CyberiaTradingInfo: React.FC<{ chainId: ChainId }> = ({ chainId }) => {
  const { t } = useTranslation();
  const { isLoading, data } = useCyberiaDexStats(chainId);

  return (
    <>
      <Box className='tradingSection'>
        <Typography
          className='text-uppercase'
          style={{ fontSize: '11px', fontWeight: 600 }}
        >
          {t('Total VALUE Locked')}
        </Typography>
        {isLoading ? (
          <Skeleton variant='rect' width={100} height={45} />
        ) : (
          <Box display='flex'>
            <h3>${formatCompact(data?.tvlUSD ?? 0)}</h3>
          </Box>
        )}
      </Box>
      <Box className='tradingSection'>
        <Typography
          className='text-uppercase'
          style={{ fontSize: '11px', fontWeight: 600 }}
        >
          {t('Liquidity Pairs')}
        </Typography>
        {isLoading ? (
          <Skeleton variant='rect' width={100} height={45} />
        ) : (
          <Box display='flex'>
            <h3>{(data?.pairCount ?? 0).toLocaleString()}</h3>
          </Box>
        )}
      </Box>
      <Box className='tradingSection'>
        <Typography
          className='text-uppercase'
          style={{ fontSize: '11px', fontWeight: 600 }}
        >
          {t('Tokens')}
        </Typography>
        {isLoading ? (
          <Skeleton variant='rect' width={100} height={45} />
        ) : (
          <Box display='flex'>
            <h3>{(data?.tokenCount ?? 0).toLocaleString()}</h3>
          </Box>
        )}
      </Box>
    </>
  );
};

export default TradingInfo;
