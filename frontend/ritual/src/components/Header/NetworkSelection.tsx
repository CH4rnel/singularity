import React from 'react';
import { Box } from '@material-ui/core';
import 'components/styles/Header.scss';
import ActiveDotImage from 'assets/images/chainActiveDot.png';
import { useIsSupportedNetwork } from 'utils';
import { useActiveWeb3React } from 'hooks';
import { getConfig } from 'config/index';
import { useTranslation } from 'react-i18next';

// Ritual is deployed on Cyberia only. We still render the network pill so
// users see which network they are on, but the dropdown + chain switcher
// is removed (there is only one chain to switch to).
export const NetworkSelection: React.FC = () => {
  const isSupportedNetwork = useIsSupportedNetwork();
  const { chainId } = useActiveWeb3React();
  const config = getConfig(chainId);
  const { t } = useTranslation();

  return (
    <div className='headerDropdownWrapper'>
      <Box
        className='headerDropdown'
        style={{ cursor: 'default' }}
      >
        {isSupportedNetwork && (
          <Box className='networkSelectionImage'>
            {chainId && (
              <img
                src={ActiveDotImage}
                alt='chain active'
                className='networkActiveDot'
              />
            )}
            <img src={config['nativeCurrencyImage']} alt='network Image' />
          </Box>
        )}
        <small className='network-name'>
          {isSupportedNetwork ? config['networkName'] : t('wrongNetwork')}
        </small>
      </Box>
    </div>
  );
};
