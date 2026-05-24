import React, { useEffect, useMemo, useState } from 'react';
import { Link, useHistory } from 'react-router-dom';
import { Box, Button, useMediaQuery } from '@material-ui/core';
import { Close } from '@material-ui/icons';
import { useTheme } from '@material-ui/core/styles';
import { useSwitchNetwork, useWalletInfo } from '@web3modal/ethers5/react';
import { useActiveWeb3React, useConnectWallet } from 'hooks';
import QuickIcon from 'assets/images/ash.png';
import QuickLogo from 'assets/images/quickLogo.png';
import QuickLogoWebP from 'assets/images/quickLogo.webp';
import QuickPerpsLogo from 'assets/images/quickPerpsLogo.webp';
import { ReactComponent as ThreeDotIcon } from 'assets/images/ThreeDot.svg';
import 'components/styles/Header.scss';
import { useTranslation } from 'react-i18next';
import { getConfig } from 'config/index';
import useDeviceWidth from 'hooks/useDeviceWidth';
import { NEW_QUICK, USDC, USDO, USDT } from 'constants/v3/addresses';
import { ChainId, WETH } from '@uniswap/sdk';
import { MobileMenuDrawer } from './MobileMenuDrawer';
import useParsedQueryString from 'hooks/useParsedQueryString';
import { HeaderListItem, HeaderMenuItem } from './HeaderListItem';
import { HeaderDesktopItem } from './HeaderDesktopItem';
import MobileHeader from './MobileHeader';
import KeyboardArrowDownIcon from '@material-ui/icons/KeyboardArrowDown';
import { NetworkSelection } from './NetworkSelection';
import { shortenAddress, useIsSupportedNetwork } from 'utils';
import AccountDetailsModal from 'components/AccountDetails/AccountDetailsModal';

const cyberiaExternalLinks: HeaderMenuItem[] = [
  {
    link: '/lending',
    text: 'Lending',
    id: 'cyberia-lending-link',
    isExternal: true,
    target: '_blank',
    externalLink: 'https://bridge.cyberia.church/lending',
  },
  {
    link: '/cyberia',
    text: 'Cyberia',
    id: 'cyberia-site-link',
    isExternal: true,
    target: '_blank',
    externalLink: 'https://cyberia.church',
  },
  {
    link: '/cyberia-bridge',
    text: 'Bridge',
    id: 'cyberia-bridge-link',
    isExternal: true,
    target: '_blank',
    externalLink: 'https://bridge.cyberia.church',
  },
  {
    link: '/cyberia-explorer',
    text: 'Explorer',
    id: 'cyberia-explorer-link',
    isExternal: true,
    target: '_blank',
    externalLink: 'https://explorer.cyberia.church',
  },
];

const Header: React.FC<{ onUpdateNewsletter?: (val: boolean) => void }> = () => {
  const { t } = useTranslation();
  const history = useHistory();
  const { chainId, account } = useActiveWeb3React();
  const { switchNetwork } = useSwitchNetwork();
  const { walletInfo } = useWalletInfo();
  const isSupportedNetwork = useIsSupportedNetwork();
  const { connectWallet } = useConnectWallet(isSupportedNetwork);
  const [showAccountDetailsModal, setShowAccountDetailsModal] = useState(false);

  const theme = useTheme();

  const tabletWindowSize = useMediaQuery(theme.breakpoints.down('sm'));
  const mobileWindowSize = useMediaQuery(theme.breakpoints.down('xs'));
  const deviceWidth = useDeviceWidth();
  const [headerClass, setHeaderClass] = useState('');

  const changeHeaderBg = () => {
    if (window.scrollY > 0) {
      setHeaderClass('bg-palette');
    } else {
      setHeaderClass('');
    }
  };

  useEffect(() => {
    changeHeaderBg();
    window.addEventListener('scroll', changeHeaderBg);
  }, []);

  const menuItemCountToShow = useMemo(() => {
    if (deviceWidth > 1580) {
      return 8;
    } else if (deviceWidth > 1500) {
      return 7;
    } else if (deviceWidth > 1320) {
      return 5;
    } else if (deviceWidth > 1190) {
      return 4;
    } else if (deviceWidth > 1100) {
      return 3;
    } else if (deviceWidth > 1020) {
      return 2;
    }
    return 1;
  }, [deviceWidth]);

  const config = getConfig(chainId);
  const showSwap = config['swap']['available'];
  const showPool = config['pools']['available'];
  const showFarm = config['farm']['available'];
  const showBridge = config['bridge']['available'];
  const showLending = config['lending']['available'];
  const showDappOs = config['dappos']['available'];
  const showGamingHub = config['gamingHub']['available'];
  const menuItems: Array<HeaderMenuItem> = [];

  const swapCurrencyStr = useMemo(() => {
    if (!chainId) return '';
    if (chainId === ChainId.ZKTESTNET)
      return `&currency1=${USDT[chainId].address}`;
    if (chainId === ChainId.DOGECHAIN)
      return `&currency1=${USDO[chainId].address}`;
    if (NEW_QUICK[chainId]) return `&currency1=${NEW_QUICK[chainId].address}`;
    if (USDC[chainId]) return `&currency1=${USDC[chainId].address}`;
    return '';
  }, [chainId]);

  if (showSwap) {
    menuItems.push({
      link: `/swap?currency0=ETH${swapCurrencyStr}`,
      text: t('swap'),
      id: 'swap-page-link',
    });
    if (chainId && WETH[chainId]) {
      menuItems.push({
        link: `/wrap`,
        text: t('wrap'),
        id: 'wrap-page-link',
      });
    }
  }

  if (showPool) {
    menuItems.push({
      link: `/pools`,
      text: t('pool'),
      id: 'pools-page-link',
    });
  }
  if (showFarm) {
    menuItems.push({
      link: `/farm`,
      text: t('farm') as string,
      id: 'farm-page-link',
    });
  }

  if (showBridge && isSupportedNetwork)
    menuItems.push({
      link: '/bridge',
      text: t('Bridge'),
      id: 'bridge-page-link',
    });

  if (showGamingHub) {
    menuItems.push({
      link: '/gamehub',
      text: 'Gaming Hub',
      id: 'gamehub-page-link',
      isExternal: true,
      target: '_top',
      externalLink: process?.env?.REACT_APP_GAMEHUB_URL || '',
    });
  }
  if (showDappOs) {
    menuItems.push({
      link: '/dappOS',
      text: 'DappOS',
      id: 'dappos-page-link',
      isExternal: true,
      target: '_blank',
      externalLink: process?.env?.REACT_APP_DAPPOS_URL || '',
    });
  }

  if (showLending) {
    menuItems.push({
      link: '/lend',
      text: t('lend'),
      id: 'lend-page-link',
      isNew: true,
    });
  }

  menuItems.push(...cyberiaExternalLinks);

  const parsedQuery = useParsedQueryString();
  const parsedChain =
    parsedQuery && parsedQuery.chainId
      ? Number(parsedQuery.chainId)
      : undefined;

  useEffect(() => {
    (async () => {
      if (parsedChain && chainId !== parsedChain) {
        switchNetwork(parsedChain);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chainId, parsedChain]);

  return (
    <Box className='header'>
      <Box className={`menuBar ${tabletWindowSize ? '' : headerClass}`}>
        <AccountDetailsModal
          open={showAccountDetailsModal}
          onClose={() => setShowAccountDetailsModal(false)}
        />
        <Box style={{ display: 'flex', alignItems: 'center', gap: '20px' }}>
          <Link to='/'>
            {mobileWindowSize && (
              <img src={QuickIcon} alt='Ritual' height={32} width={32} />
            )}
            {!mobileWindowSize && (
              <Box
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 10,
                  textDecoration: 'none',
                }}
              >
                <img src={QuickIcon} alt='Ritual' height={32} width={32} />
                <span
                  style={{
                    fontSize: 22,
                    fontWeight: 700,
                    color: '#fff',
                    letterSpacing: '0.04em',
                  }}
                >
                  Ritual
                </span>
              </Box>
            )}
          </Link>
          {!tabletWindowSize && (
            <Box className='mainMenu'>
              {menuItems.slice(0, menuItemCountToShow).map((val, i) => (
                <HeaderDesktopItem
                  key={`header-desktop-item-${i}`}
                  item={val}
                />
              ))}
              {menuItems.slice(menuItemCountToShow, menuItems.length).length >
                0 && (
                <Box display='flex' className='menuItem subMenuItem'>
                  <ThreeDotIcon />
                  <Box className='subMenuWrapper'>
                    <Box className='subMenu'>
                      {menuItems
                        .slice(menuItemCountToShow, menuItems.length)
                        .map((val, i) => (
                          <HeaderListItem key={'sub-menu' + i} item={val} />
                        ))}
                    </Box>
                  </Box>
                </Box>
              )}
            </Box>
          )}
        </Box>
        <Box>
          <Box style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
            <NetworkSelection />
            {!!account ? (
              <Box
                id='web3-status-connected'
                className='accountDetails'
                onClick={() => setShowAccountDetailsModal(true)}
                style={{ gap: '8px' }}
              >
                {walletInfo?.icon && (
                  <img src={walletInfo?.icon} width={24} alt='wallet icon' />
                )}
                <p>{shortenAddress(account)}</p>
                <KeyboardArrowDownIcon />
              </Box>
            ) : (
              <Box
                className='connectButton bg-primary'
                onClick={() => {
                  connectWallet();
                }}
              >
                {t('connectWallet')}
              </Box>
            )}
          </Box>
        </Box>
      </Box>

      {(mobileWindowSize || tabletWindowSize) && (
        <MobileHeader
          isMobile={mobileWindowSize}
          isTablet={tabletWindowSize}
          menuItems={menuItems}
        />
      )}
    </Box>
  );
};

export default Header;
