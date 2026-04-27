import React, { useMemo, useState } from 'react';
import { Box, Button } from '@material-ui/core';
import { SwapVert } from '@material-ui/icons';
import { ETHER, WETH } from '@uniswap/sdk';
import { useActiveWeb3React, useConnectWallet } from 'hooks';
import { useCurrencyBalance } from 'state/wallet/hooks';
import { NumericalInput } from 'components';
import useWrapCallback, { WrapType } from 'hooks/useWrapCallback';
import { formatTokenAmount } from 'utils';
import { useTranslation } from 'react-i18next';
import 'pages/styles/swap.scss';

/**
 * Dedicated native ↔ wrapped-native conversion page.
 * Uses the existing useWrapCallback hook (WETH9-style deposit/withdraw).
 */
const WrapPage: React.FC = () => {
  const { chainId, account } = useActiveWeb3React();
  const { connectWallet } = useConnectWallet(false);
  const { t } = useTranslation();

  // true = CYBER -> WCYBER (wrap), false = WCYBER -> CYBER (unwrap)
  const [isWrap, setIsWrap] = useState(true);
  const [typedValue, setTypedValue] = useState('');

  const native = chainId ? ETHER[chainId] : undefined;
  const wrapped = chainId ? WETH[chainId] : undefined;

  const inputCurrency = isWrap ? native : wrapped;
  const outputCurrency = isWrap ? wrapped : native;

  const balance = useCurrencyBalance(account ?? undefined, inputCurrency);

  const { wrapType, execute, inputError } = useWrapCallback(
    inputCurrency,
    outputCurrency,
    typedValue,
  );

  const busy =
    wrapType === WrapType.WRAPPING || wrapType === WrapType.UNWRAPPING;
  const disabled = !typedValue || !execute || busy;

  const buttonLabel = useMemo(() => {
    if (!account) return t('connectWallet');
    if (inputError) return inputError;
    if (wrapType === WrapType.WRAPPING) return t('wrappingMATIC', { symbol: native?.symbol ?? '' });
    if (wrapType === WrapType.UNWRAPPING) return t('unwrappingMATIC', { symbol: wrapped?.symbol ?? '' });
    return isWrap
      ? t('wrapMATIC', { symbol: native?.symbol ?? '' })
      : t('unwrapMATIC', { symbol: wrapped?.symbol ?? '' });
  }, [account, inputError, wrapType, isWrap, native, wrapped, t]);

  const handleMax = () => {
    if (balance) {
      // Leave a dust reserve for gas when wrapping native currency.
      setTypedValue(balance.toExact());
    }
  };

  const flip = () => {
    setIsWrap((v) => !v);
    setTypedValue('');
  };

  const handleClick = async () => {
    if (!account) {
      connectWallet();
      return;
    }
    if (execute) {
      await execute();
      setTypedValue('');
    }
  };

  return (
    <Box width='100%' mb={3} id='wrap-page'>
      <Box
        sx={{
          maxWidth: 560,
          margin: '24px auto',
          padding: 3,
          borderRadius: 16,
          bgcolor: '#1b1e29',
          border: '1px solid #242938',
        }}
      >
        <Box mb={2} display='flex' alignItems='center' justifyContent='space-between'>
          <h5 style={{ margin: 0, color: '#fff' }}>{t('wrap')}</h5>
          <small className='text-secondary'>
            {isWrap ? `${native?.symbol} → ${wrapped?.symbol}` : `${wrapped?.symbol} → ${native?.symbol}`}
          </small>
        </Box>

        <Box className='swapBox' sx={{ padding: 2, borderRadius: 12, bgcolor: '#12131a' }}>
          <Box display='flex' justifyContent='space-between' alignItems='center' mb={1}>
            <small className='text-secondary'>{t('from')}</small>
            <Box display='flex' alignItems='center' gridGap={8}>
              <small className='text-secondary'>
                {t('balance')}: {balance ? formatTokenAmount(balance) : '—'}
              </small>
              {balance && (
                <Button
                  size='small'
                  onClick={handleMax}
                  style={{ minWidth: 0, padding: '2px 8px', fontSize: 12 }}
                >
                  {t('max')}
                </Button>
              )}
            </Box>
          </Box>
          <Box display='flex' justifyContent='space-between' alignItems='center'>
            <NumericalInput
              value={typedValue}
              onUserInput={setTypedValue}
              fontSize={24}
              align='left'
              placeholder='0.0'
            />
            <Box
              sx={{
                minWidth: 80,
                textAlign: 'right',
                color: '#fff',
                fontWeight: 600,
              }}
            >
              {inputCurrency?.symbol}
            </Box>
          </Box>
        </Box>

        <Box display='flex' justifyContent='center' my={1}>
          <Button
            onClick={flip}
            style={{
              minWidth: 0,
              padding: 8,
              borderRadius: '50%',
              background: '#242938',
            }}
          >
            <SwapVert style={{ color: '#fff' }} />
          </Button>
        </Box>

        <Box className='swapBox' sx={{ padding: 2, borderRadius: 12, bgcolor: '#12131a' }}>
          <Box mb={1}>
            <small className='text-secondary'>{t('to')}</small>
          </Box>
          <Box display='flex' justifyContent='space-between' alignItems='center'>
            <Box style={{ fontSize: 24, color: '#fff' }}>{typedValue || '0.0'}</Box>
            <Box
              sx={{
                minWidth: 80,
                textAlign: 'right',
                color: '#fff',
                fontWeight: 600,
              }}
            >
              {outputCurrency?.symbol}
            </Box>
          </Box>
        </Box>

        <Box mt={3}>
          <Button
            fullWidth
            onClick={handleClick}
            disabled={account ? disabled : false}
            style={{
              height: 56,
              fontSize: 16,
              fontWeight: 600,
              borderRadius: 12,
            }}
          >
            {buttonLabel}
          </Button>
        </Box>

        <Box mt={2} textAlign='center'>
          <small className='text-secondary'>
            {t('wrap')} 1:1 — {native?.symbol} ↔ {wrapped?.symbol}
          </small>
        </Box>
      </Box>
    </Box>
  );
};

export default WrapPage;
