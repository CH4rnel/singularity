import React, { useEffect, useMemo, useState } from 'react';
import { Box, Button } from '@material-ui/core';
import { SwapVert } from '@material-ui/icons';
import { ETHER, WETH } from '@uniswap/sdk';
import { BigNumber, Contract } from 'ethers';
import { formatUnits, parseUnits } from 'ethers/lib/utils';
import { useActiveWeb3React, useConnectWallet } from 'hooks';
import { useWETHContract } from 'hooks/useContract';
import { NumericalInput } from 'components';
import { useTranslation } from 'react-i18next';
import { useBlockNumber } from 'state/application/hooks';
import { RPC_PROVIDERS } from 'constants/providers';
import ERC20_ABI from 'constants/abis/erc20.json';
import './WrapPage.scss';

/**
 * Native ↔ wrapped-native conversion page.
 *
 * Talks to the WETH9-style wrapped-native contract directly via ethers
 * (deposit() to wrap, withdraw(amount) to unwrap). Balances are read
 * straight from the chain RPC every new block, so the page works even
 * when the wallet is connected to a different network.
 */
const WrapPage: React.FC = () => {
  const { chainId, account } = useActiveWeb3React();
  const { connectWallet } = useConnectWallet(false);
  const { t } = useTranslation();
  const blockNumber = useBlockNumber();

  // true = native -> wrapped (wrap); false = wrapped -> native (unwrap)
  const [isWrap, setIsWrap] = useState(true);
  const [typedValue, setTypedValue] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const native = chainId ? ETHER[chainId] : undefined;
  const wrapped = chainId ? WETH[chainId] : undefined;

  const inputSymbol = isWrap ? native?.symbol : wrapped?.symbol;
  const outputSymbol = isWrap ? wrapped?.symbol : native?.symbol;

  const wethContract = useWETHContract();

  const [nativeBalance, setNativeBalance] = useState<BigNumber>(BigNumber.from(0));
  const [wrappedBalance, setWrappedBalance] = useState<BigNumber>(BigNumber.from(0));

  // Read balances from the chain's own RPC (works irrespective of wallet net).
  useEffect(() => {
    let cancelled = false;
    if (!account || !chainId || !wrapped?.address) return;
    const rpc = RPC_PROVIDERS[chainId];
    if (!rpc) return;
    (async () => {
      try {
        const wrappedRead = new Contract(wrapped.address, ERC20_ABI, rpc);
        const [n, w] = await Promise.all([
          rpc.getBalance(account),
          wrappedRead.balanceOf(account),
        ]);
        if (!cancelled) {
          setNativeBalance(n);
          setWrappedBalance(w);
        }
      } catch {
        // RPC hiccup — retry next block
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [account, chainId, wrapped?.address, blockNumber]);

  const inputBalance = isWrap ? nativeBalance : wrappedBalance;

  const inputAmount = useMemo(() => {
    if (!typedValue) return null;
    try {
      return parseUnits(typedValue, 18);
    } catch {
      return null;
    }
  }, [typedValue]);

  const sufficient = !!inputAmount && inputBalance.gte(inputAmount);

  const buttonLabel = useMemo(() => {
    if (!account) return t('connectWallet');
    if (busy)
      return isWrap
        ? `Wrapping ${inputSymbol}…`
        : `Unwrapping ${inputSymbol}…`;
    if (!inputAmount || inputAmount.isZero()) return 'Enter amount';
    if (!sufficient) return `Insufficient ${inputSymbol}`;
    return isWrap ? `Wrap ${inputSymbol}` : `Unwrap ${inputSymbol}`;
  }, [account, busy, inputSymbol, inputAmount, sufficient, isWrap, t]);

  const handleMax = () => {
    if (isWrap) {
      const reserve = parseUnits('0.005', 18);
      const max = inputBalance.gt(reserve)
        ? inputBalance.sub(reserve)
        : BigNumber.from(0);
      setTypedValue(formatUnits(max, 18));
    } else {
      setTypedValue(formatUnits(inputBalance, 18));
    }
  };

  const flip = () => {
    setIsWrap((v) => !v);
    setTypedValue('');
    setError(null);
  };

  const handleClick = async () => {
    setError(null);
    if (!account) {
      connectWallet();
      return;
    }
    if (!wethContract || !inputAmount || inputAmount.isZero() || !sufficient)
      return;
    setBusy(true);
    try {
      const tx = isWrap
        ? await wethContract.deposit({ value: inputAmount })
        : await wethContract.withdraw(inputAmount);
      await tx.wait();
      setTypedValue('');
    } catch (e: any) {
      setError(e?.message ?? String(e));
    } finally {
      setBusy(false);
    }
  };

  const disabled = account
    ? !inputAmount || inputAmount.isZero() || !sufficient || busy
    : false;

  return (
    <Box className='wrapPage'>
      <Box className='wrapCard'>
        <Box className='wrapHeader'>
          <h5>{t('wrap')}</h5>
          <small>
            {isWrap
              ? `${native?.symbol} → ${wrapped?.symbol}`
              : `${wrapped?.symbol} → ${native?.symbol}`}
          </small>
        </Box>

        <Box className='wrapInputBox'>
          <Box className='wrapInputRow'>
            <small>{t('from')}</small>
            <Box className='balanceWrap'>
              <small>
                {t('balance')}:{' '}
                {Number(formatUnits(inputBalance, 18)).toFixed(6)}
              </small>
              <button className='maxBtn' onClick={handleMax} type='button'>
                {t('max')}
              </button>
            </Box>
          </Box>
          <Box className='wrapAmountRow'>
            <NumericalInput
              value={typedValue}
              onUserInput={setTypedValue}
              fontSize={28}
              align='left'
              placeholder='0.0'
            />
            <span className='symbol'>{inputSymbol}</span>
          </Box>
        </Box>

        <Box className='wrapSwitcher'>
          <Box className='switcherInner' onClick={flip}>
            <SwapVert />
          </Box>
        </Box>

        <Box className='wrapInputBox'>
          <Box className='wrapInputRow'>
            <small>{t('to')}</small>
          </Box>
          <Box className='wrapAmountRow'>
            <span className='amountStatic'>{typedValue || '0.0'}</span>
            <span className='symbol'>{outputSymbol}</span>
          </Box>
        </Box>

        {error && (
          <Box className='wrapErrorBox'>
            <small>{error}</small>
          </Box>
        )}

        <Box className='wrapAction'>
          <Button onClick={handleClick} disabled={disabled}>
            {buttonLabel}
          </Button>
        </Box>

        <div className='wrapHint'>
          {t('wrap')} 1:1 — {native?.symbol} ↔ {wrapped?.symbol}
        </div>
      </Box>
    </Box>
  );
};

export default WrapPage;
