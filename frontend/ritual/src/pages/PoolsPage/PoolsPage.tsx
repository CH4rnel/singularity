import React, { lazy, useEffect } from 'react';
import {
  Box,
  Grid,
  Typography,
  useMediaQuery,
  useTheme,
} from '@material-ui/core';
import { ReactComponent as HelpIcon } from 'assets/images/HelpIcon1.svg';
import SupplyLiquidity from './SupplyLiquidity';
import { useTranslation } from 'react-i18next';
import 'pages/styles/pools.scss';
import { useIsLpLock, useIsV2 } from 'state/application/hooks';
import { getConfig } from '../../config/index';
import { useActiveWeb3React } from 'hooks';
import { ChainId } from '@uniswap/sdk';
import LockLiquidity from './lpLock/LockLiquidity';
import { useParams } from 'react-router-dom';
import { SingleTokenSupplyLiquidity } from './SingleToken/SupplyLiquidity';

const YourLiquidityPools = lazy(() => import('./YourLiquidityPools'));
const MyLiquidityLocks = lazy(() => import('./lpLock/MyLiquidityLocks'));

const PoolsPage: React.FC = () => {
  const { t } = useTranslation();
  const { updateIsV2 } = useIsV2();
  const { isLpLock } = useIsLpLock();
  const { chainId } = useActiveWeb3React();
  const { breakpoints } = useTheme();
  const isMobile = useMediaQuery(breakpoints.down('xs'));

  const chainIdToUse = chainId ?? ChainId.MATIC;
  const config = getConfig(chainIdToUse);
  const params: any = useParams();
  const version = params?.version ?? 'v2';

  const helpURL = process.env.REACT_APP_HELP_URL;

  // V3 has been removed from this build; always operate in v2 mode.
  useEffect(() => {
    updateIsV2(true);
  }, [updateIsV2]);

  const showPools = config['pools']['available'];

  if (!showPools) {
    location.href = '/';
  }

  useEffect(() => {
    if (!showPools) {
      location.href = '/';
    }
  }, [showPools]);

  return (
    <Box mb={3} width='100%'>
      {isMobile ? (
        <Box mt={2} mb={2} className='pageHeading items-center'>
          <Typography variant='h6'>{t('pool')}</Typography>
          <Box>
            {helpURL && (
              <Box
                className='helpWrapper'
                onClick={() => window.open(helpURL, '_blank')}
              >
                <small>{t('help')}</small>
                <HelpIcon />
              </Box>
            )}
          </Box>
        </Box>
      ) : (
        <Box className='pageHeading'>
          <Box className='flex row items-center'>
            <Typography variant='h6'>{t('pool')}</Typography>
          </Box>
        </Box>
      )}
      <Grid container>
        <Grid
          style={{
            backgroundColor: '#1b1e29',
            borderRadius: '20px',
          }}
          className='gridCardContainer'
          item
          xs={12}
          sm={12}
          md={5}
        >
          <Box className='wrapper'>
            {version === 'singleToken' ? (
              <SingleTokenSupplyLiquidity />
            ) : isLpLock ? (
              <LockLiquidity />
            ) : (
              <SupplyLiquidity />
            )}
          </Box>
        </Grid>
        <Grid item xs={12} sm={12} md={7} className='mypoolsContainer'>
          <Box className='wrapper'>
            {isLpLock ? <MyLiquidityLocks /> : <YourLiquidityPools />}
          </Box>
        </Grid>
      </Grid>
    </Box>
  );
};

export default PoolsPage;
