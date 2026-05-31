import React, { lazy, Suspense } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Switch, Route } from 'react-router-dom';
import {
  ThemeProvider as MuiThemeProvider,
  CssBaseline,
} from '@material-ui/core';
import { Provider } from 'react-redux';
import store from 'state';

const RitualFarmPage = lazy(() => import('./pages/RitualFarmPage'));
const LandingPage = lazy(() => import('./pages/LandingPage'));
const PoolsPage = lazy(() => import('./pages/PoolsPage'));
const SwapPage = lazy(() => import('./pages/SwapPage'));
const WrapPage = lazy(() => import('./pages/WrapPage'));
const TOSPage = lazy(() => import('./pages/TOSPage'));

import { PageLayout } from 'layouts';
import { Popups, TermsWrapper } from 'components';
import ApplicationUpdater from 'state/application/updater';
import TransactionUpdater from 'state/transactions/updater';
import ListsUpdater from 'state/lists/updater';
import UserUpdater from 'state/user/updater';
import MulticallUpdater from 'state/multicall/updater';
import MultiCallV3Updater from 'state/multicall/v3/updater';
import SyrupUpdater from 'state/syrups/updater';
import 'slick-carousel/slick/slick.css';
import 'slick-carousel/slick/slick-theme.css';
import './i18n';
import { mainTheme } from './theme';
import Background from 'layouts/Background';
import { RedirectExternal } from 'components/RedirectExternal/RedirectExternal';
import NotFound404Page from 'pages/NotFound404Page';
import ForbiddenPage from 'pages/ForbiddenPage';
import './index.scss';
import { createWeb3Modal, defaultConfig } from '@web3modal/ethers5/react';
import { ChainId } from '@uniswap/sdk';
import { SUPPORTED_CHAINIDS } from 'constants/index';
import { getConfig } from 'config/index';
import 'connectors/passport';
import { BridgePage } from 'pages';

const projectId = process.env.REACT_APP_WALLETCONNECT_PROJECT_ID ?? '';

const metadata = {
  name: 'Ritual',
  description:
    'The sacrificial DEX on Cyberia. Every swap burns an offering — out of the fire rises Ash.',
  url: 'https://ritual.cyberia.church',
  icons: ['/logo_circle.png'],
};

const ethersConfig = defaultConfig({
  metadata,
  defaultChainId: ChainId.CYBERIA,
});

const chainsToShow = SUPPORTED_CHAINIDS.filter((chainId) => {
  const config = getConfig(chainId);
  return !!config;
});
const chains = chainsToShow.map((chainId) => {
  const config = getConfig(chainId);
  return {
    chainId,
    name: config['networkName'],
    currency: config['nativeCurrency']['symbol'],
    explorerUrl: config['blockExplorer'],
    rpcUrl: config['rpc'],
  };
});

const chainImages: { [chainId: number]: string } = {};
chainsToShow.forEach((chainId) => {
  const config = getConfig(chainId);
  chainImages[chainId] = config['nativeCurrencyImage'];
});

createWeb3Modal({
  ethersConfig,
  chains,
  chainImages,
  projectId,
  enableAnalytics: true,
  allowUnsupportedChain: true,
  enableOnramp: true,
});

const ThemeProvider: React.FC<{ children: any }> = ({ children }) => {
  const theme = mainTheme;

  return <MuiThemeProvider theme={theme}>{children}</MuiThemeProvider>;
};

const Providers: React.FC<{ children: any }> = ({ children }) => {
  return (
    <Suspense fallback={<Background fallback={true} />}>
      <ThemeProvider>
        <CssBaseline />
        {children}
      </ThemeProvider>
    </Suspense>
  );
};

function Updaters() {
  return (
    <>
      <ApplicationUpdater />
      <TransactionUpdater />
      <ListsUpdater />
      <MulticallUpdater />
      <MultiCallV3Updater />
      <UserUpdater />
      <SyrupUpdater />
    </>
  );
}

const queryClient = new QueryClient();

const App: React.FC = () => {
  return (
    <QueryClientProvider client={queryClient}>
      <Provider store={store}>
        <Providers>
          <TermsWrapper>
            <Updaters />
            <Popups />
            <Switch>
              <Route exact path='/'>
                <PageLayout>
                  <LandingPage />
                </PageLayout>
              </Route>
              <Route exact path='/swap/:version?'>
                <PageLayout>
                  <SwapPage />
                </PageLayout>
              </Route>
              <Route exact path='/wrap'>
                <PageLayout>
                  <WrapPage />
                </PageLayout>
              </Route>
              <Route exact path='/bridge'>
                <PageLayout>
                  <BridgePage />
                </PageLayout>
              </Route>
              <Route exact path='/pools/:version?'>
                <PageLayout>
                  <PoolsPage />
                </PageLayout>
              </Route>
              <Route
                exact
                path='/add/:currencyIdA?/:currencyIdB?/:version?'
              >
                <PageLayout>
                  <PoolsPage />
                </PageLayout>
              </Route>
              <Route exact path='/farm/:version?'>
                <PageLayout>
                  <RitualFarmPage />
                </PageLayout>
              </Route>
              <Route exact path='/tos'>
                <PageLayout>
                  <TOSPage />
                </PageLayout>
              </Route>
              <Route exact path='/gamehub'>
                <RedirectExternal
                  to={`${process.env.REACT_APP_GAMEHUB_URL}`}
                  target={'_top'}
                ></RedirectExternal>
              </Route>
              <Route path='/forbidden'>
                <PageLayout>
                  <ForbiddenPage />
                </PageLayout>
              </Route>
              <Route path='*'>
                <PageLayout>
                  <NotFound404Page />
                </PageLayout>
              </Route>
            </Switch>
          </TermsWrapper>
        </Providers>
      </Provider>
    </QueryClientProvider>
  );
};

export default App;
