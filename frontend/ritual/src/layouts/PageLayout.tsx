import React, { lazy, useEffect, useMemo, useState } from 'react';
import { Box, Button, useMediaQuery, useTheme } from '@material-ui/core';
import { useIsProMode } from 'hooks';
import { useHistory } from 'react-router-dom';
const Header = lazy(() => import('components/Header'));
const Footer = lazy(() => import('components/Footer'));
const BetaWarningBanner = lazy(() => import('components/BetaWarningBanner'));
const CustomModal = lazy(() => import('components/CustomModal'));
const Background = lazy(() => import('./Background'));

export interface PageLayoutProps {
  children: any;
  name?: string;
}

const PageLayout: React.FC<PageLayoutProps> = ({ children, name }) => {
  const [headerClass] = useState('');
  const isProMode = useIsProMode();
  const [openPassModal, setOpenPassModal] = useState(false);
  const { location } = useHistory();
  const pageWrapperClassName = useMemo(() => {
    if (isProMode) {
      return 'pageWrapper-proMode';
    } else if (location.pathname.includes('/swap')) {
      return 'pageWrapper-no-max';
    }
    return name == 'prdt' ? 'pageWrapper-no-max' : 'pageWrapper';
  }, [isProMode, location, name]);

  useEffect(() => {
    if (
      window.location.host !== 'quickswap.exchange' &&
      window.location.host !== 'beta.quickswap.exchange' &&
      window.location.host !== 'dogechain.quickswap.exchange' &&
      window.location.host !== 'localhost:3000' &&
      window.location.host !==
        'feature-immutable-mainnet-1.interface-v2-01.pages.dev'
    ) {
      setOpenPassModal(true);
    }
  }, []);

  const PasswordModal = () => {
    const [devPass, setDevPass] = useState('');
    const confirmPassword = () => {
      if (devPass === 'gammaPass' || devPass === 'testPass') {
        setOpenPassModal(false);
      }
    };
    return (
      <CustomModal open={openPassModal} onClose={confirmPassword}>
        <Box className='devPassModal'>
          <p>Please input password to access dev site.</p>
          <input
            type='password'
            value={devPass}
            onChange={(e) => {
              setDevPass(e.target.value);
            }}
          />
          <Box textAlign='right'>
            <Button onClick={confirmPassword}>Confirm</Button>
          </Box>
        </Box>
      </CustomModal>
    );
  };

  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('sm'));

  const showBetaBanner = false;

  return (
    <Box className='page'>
      {/* {openPassModal && <PasswordModal />} */}
      {showBetaBanner && <BetaWarningBanner />}
      <Header />
      {!isProMode && <Background fallback={false} />}
      <Box
        className={`${pageWrapperClassName} ${headerClass}`}
        sx={{ marginTop: isMobile ? '-124px' : '0px' }}
      >
        {children}
      </Box>
      <Footer />
    </Box>
  );
};

export default PageLayout;
