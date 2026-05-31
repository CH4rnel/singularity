import React, { useEffect, useState } from 'react';
import { Currency, CurrencyAmount, ETHER, currencyEquals } from '@uniswap/sdk';
import { NativeCurrency } from '@uniswap/sdk-core';
import { WrappedTokenInfo } from 'state/lists/v3/wrappedTokenInfo';
import { TokenInfo } from '@uniswap/token-lists';
import { Box, Typography } from '@material-ui/core';
import { useCurrencyBalance } from 'state/wallet/hooks';
import { NumericalInput } from 'components';
import { useActiveWeb3React } from 'hooks';
import useUSDCPrice from 'utils/useUSDCPrice';
import { formatTokenAmount } from 'utils';
import 'components/styles/CurrencyInput.scss';
import { useTranslation } from 'react-i18next';
import CurrencySelect from 'components/CurrencySelect';
import { default as useUSDCPriceV3 } from 'hooks/v3/useUSDCPrice';
import { WMATIC_EXTENDED } from 'constants/v3/addresses';
import { fontSizes } from 'components/Dropdown/types';
import { useAppSelector } from 'state';
import { getCurrencyBalanceImmediately } from 'state/wallet/hooks';
import { useMulticallContract } from 'hooks/useContract';
import { useBlockNumber } from 'state/application/hooks';

interface CurrencyInputProps {
  title?: string;
  handleCurrencySelect: (currency: Currency) => void;
  currency: Currency | undefined;
  otherCurrency?: Currency | undefined;
  amount: string;
  setAmount: (value: string) => void;
  onMax?: () => void;
  onHalf?: () => void;
  showHalfButton?: boolean;
  showMaxButton?: boolean;
  showPrice?: boolean;
  bgClass?: string;
  color?: string;
  id?: string;
  classNames?: string;
  balancePrev?: string;
  balanceAfter?: string;
  disabled?: boolean;
}

const CurrencyInput: React.FC<CurrencyInputProps> = ({
  handleCurrencySelect,
  currency,
  otherCurrency,
  amount,
  setAmount,
  onMax,
  onHalf,
  showMaxButton,
  showHalfButton,
  title,
  showPrice,
  bgClass,
  color,
  id,
  classNames,
  balancePrev,
  balanceAfter,
  disabled,
}) => {
  const { t } = useTranslation();
  const { account, chainId } = useActiveWeb3React();
  const selectedCurrencyBalance = useCurrencyBalance(
    account ?? undefined,
    currency,
  );
  const balanceUpdateSelector = useAppSelector((state) => state.userBalance);
  const [
    updatedSelectedCurrencyBalance,
    setUpdatedSelectedCurrencyBalance,
  ] = useState<CurrencyAmount | undefined>(undefined);

  const multicallContract = useMulticallContract();
  const latestBlockNumber = useBlockNumber();
  const balanceBlockNumber =
    balanceUpdateSelector.blockNumber && latestBlockNumber
      ? Math.max(balanceUpdateSelector.blockNumber, latestBlockNumber)
      : balanceUpdateSelector.blockNumber ?? latestBlockNumber;
  const currencyKey = currency
    ? `${currency.symbol ?? 'currency'}-${
        'address' in currency ? currency.address : 'native'
      }`
    : 'none';

  useEffect(() => {
    let cancelled = false;

    if (!multicallContract || !balanceBlockNumber || !account || !chainId) {
      setUpdatedSelectedCurrencyBalance(selectedCurrencyBalance);
      return undefined;
    }

    getCurrencyBalanceImmediately(
      multicallContract,
      chainId,
      balanceBlockNumber,
      account,
      currency,
    )
      .then((value) => {
        if (!cancelled) {
          setUpdatedSelectedCurrencyBalance(value);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setUpdatedSelectedCurrencyBalance(selectedCurrencyBalance);
        }
      });

    return () => {
      cancelled = true;
    };
    // The effect intentionally keys currency by currencyKey. Some SDK Currency
    // objects are rebuilt during render, and depending on the object itself can
    // create a tight balance-fetch loop.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    account,
    balanceBlockNumber,
    balanceUpdateSelector.blockNumber,
    balanceUpdateSelector.flag,
    chainId,
    currencyKey,
    multicallContract,
  ]);

  const selectedCurrencyBalanceDependency = JSON.stringify(
    selectedCurrencyBalance,
  );
  useEffect(() => {
    setUpdatedSelectedCurrencyBalance(selectedCurrencyBalance);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedCurrencyBalanceDependency]);

  const displayedCurrencyBalance =
    updatedSelectedCurrencyBalance ?? selectedCurrencyBalance;

  const usdPriceV2 = Number(useUSDCPrice(currency)?.toSignificant() ?? 0);
  const currencyV3 =
    chainId && currency
      ? currencyEquals(currency, ETHER[chainId])
        ? ({
            ...ETHER[chainId],
            isNative: true,
            isToken: false,
            wrapped: WMATIC_EXTENDED[chainId],
          } as NativeCurrency)
        : new WrappedTokenInfo(currency as TokenInfo)
      : undefined;
  const usdPriceV3Obj = useUSDCPriceV3(currencyV3);
  const usdPriceV3 = Number(usdPriceV3Obj?.toSignificant() ?? 0);
  const usdPrice = usdPriceV3 || usdPriceV2;

  return (
    <Box
      id={id}
      className={`swapBox${showPrice ? ' priceShowBox' : ''} ${bgClass ??
        'bg-secondary4'} ${classNames}`}
    >
      <Box className='flex justify-between' mb={2}>
        <p
          style={{
            color: '#fff',
            fontSize: '13px',
          }}
        >
          {title || `${t('youPay')}:`}
        </p>
        <Box className='flex'>
          <Box className='flex justify-end' sx={{ fontSize: '13px' }}>
            <small className={`${color ? `text-${color}` : 'text-secondary'}}`}>
              <span className='subtext-color'>{t('balance')}:</span>{' '}
              {formatTokenAmount(displayedCurrencyBalance)}
            </small>
          </Box>
          <Box display='flex' ml={1}>
            {account && currency && showHalfButton && (
              <Box className='maxWrapper' onClick={onHalf}>
                <small>50%</small>
              </Box>
            )}
            {account && currency && showMaxButton && (
              <Box className='maxWrapper' marginLeft='10px' onClick={onMax}>
                <small>{t('max')}</small>
              </Box>
            )}
          </Box>
        </Box>
      </Box>
      <Box
        mb={2}
        sx={{ borderRadius: '10px', padding: '8px 16px' }}
        className='bg-input1'
      >
        <Box
          className='inputWrapper'
          style={{
            pointerEvents: disabled ? 'none' : 'auto',
          }}
        >
          <NumericalInput
            value={amount}
            align='left'
            color={color}
            placeholder='0.00'
            onUserInput={(val) => {
              setAmount(val);
            }}
          />
        </Box>
        <CurrencySelect
          id={id}
          currency={currency}
          otherCurrency={otherCurrency}
          handleCurrencySelect={handleCurrencySelect}
        />
      </Box>
      <Box
        sx={{
          width: '100%',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
        }}
      >
        <Typography className='subtext-color' style={{ fontSize: '13px' }}>
          {balancePrev}
        </Typography>
        <Typography className='subtext-color' style={{ fontSize: '13px' }}>
          <small className={`${color ? `text-${color}` : 'text-secondary'}}`}>
            ${(usdPrice * Number(amount)).toLocaleString('us')}
          </small>
        </Typography>
      </Box>
      {/* <Box className='flex justify-between'>
        <small className={`${color ? `text-${color}` : 'text-secondary'}}`}>
          {t('balance')}: {formatTokenAmount(updatedSelectedCurrencyBalance)}
        </small>
        <small className={`${color ? `text-${color}` : 'text-secondary'}}`}>
          ${(usdPrice * Number(amount)).toLocaleString('us')}
        </small>
      </Box> */}
    </Box>
  );
};

export default CurrencyInput;
