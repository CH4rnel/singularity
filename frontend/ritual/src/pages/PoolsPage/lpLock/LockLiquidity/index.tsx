import React, { lazy } from 'react';
import { Box } from '@material-ui/core';
import { QuestionHelper } from 'components';
import { useTranslation } from 'react-i18next';

const LockV2LiquidityComponent = lazy(() =>
  import('./components/LockV2Liquidity'),
);

const LockLiquidity: React.FC = () => {
  const { t } = useTranslation();

  return (
    <>
      <Box className='flex justify-between items-center'>
        <p className='weight-600'>{t('lockLiquidity')}</p>
        <Box className='flex items-center'>
          <Box className='headingItem'>
            <QuestionHelper
              size={24}
              className='text-secondary'
              text={t('lockLiquidityHelp')}
            />
          </Box>
        </Box>
      </Box>
      <Box mt={2.5}>
        <LockV2LiquidityComponent />
      </Box>
    </>
  );
};

export default LockLiquidity;
