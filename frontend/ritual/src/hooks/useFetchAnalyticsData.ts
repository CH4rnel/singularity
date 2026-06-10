import { useQuery } from '@tanstack/react-query';
import { ChainId } from '@uniswap/sdk';
import { getConfig } from 'config/index';

const useLeaderboardAvailable = (chainId: ChainId) =>
  Boolean(getConfig(chainId)?.leaderboard?.available);

export const useAnalyticsGlobalData = (version: string, chainId: ChainId) => {
  const leaderboardAvailable = useLeaderboardAvailable(chainId);
  const fetchGlobalData = async () => {
    if (!leaderboardAvailable) return null;
    const res = await fetch(
      `${process.env.REACT_APP_LEADERBOARD_APP_URL}/analytics/global-data/${version}?chainId=${chainId}`,
    );
    if (!res.ok) {
      return null;
    }

    const data = await res.json();
    return data && data.data ? data.data : null;
  };

  const { isLoading, data } = useQuery({
    queryKey: ['fetchAnalyticsGlobalData', version, chainId],
    queryFn: fetchGlobalData,
    enabled: leaderboardAvailable,
    refetchInterval: 300000,
  });

  return { isLoading, data };
};

export const useAnalyticsTopTokens = (
  version: string,
  chainId: ChainId,
  limit?: number,
) => {
  const leaderboardAvailable = useLeaderboardAvailable(chainId);
  const fetchTopTokens = async () => {
    if (!leaderboardAvailable) return null;
    const res = await fetch(
      `${
        process.env.REACT_APP_LEADERBOARD_APP_URL
      }/analytics/top-tokens/${version}?chainId=${chainId}${
        limit ? `&limit=${limit}` : ''
      }`,
    );
    if (!res.ok) {
      return null;
    }
    const data = await res.json();
    return data && data.data ? data.data : null;
  };

  const { isLoading, data } = useQuery({
    queryKey: ['fetchAnalyticsTopTokens', version, chainId, limit],
    queryFn: fetchTopTokens,
    enabled: leaderboardAvailable,
    refetchInterval: 300000,
  });

  return { isLoading, data };
};

export const useAnalyticsTopPairs = (version: string, chainId: ChainId) => {
  const leaderboardAvailable = useLeaderboardAvailable(chainId);
  const fetchTopPairs = async () => {
    if (!leaderboardAvailable) return null;
    const res = await fetch(
      `${process.env.REACT_APP_LEADERBOARD_APP_URL}/analytics/top-pairs/${version}?chainId=${chainId}`,
    );
    if (!res.ok) {
      return null;
    }
    const data = await res.json();
    return data && data.data ? data.data : null;
  };

  const { isLoading, data } = useQuery({
    queryKey: ['fetchAnalyticsTopPairs', version, chainId],
    queryFn: fetchTopPairs,
    enabled: leaderboardAvailable,
    refetchInterval: 300000,
  });

  return { isLoading, data };
};

export const useAnalyticsTokenDetails = (
  tokenAddress: string,
  version: string,
  chainId: ChainId,
) => {
  const leaderboardAvailable = useLeaderboardAvailable(chainId);
  const fetchTokenDetails = async () => {
    if (chainId && version && leaderboardAvailable) {
      const res = await fetch(
        `${process.env.REACT_APP_LEADERBOARD_APP_URL}/analytics/top-token-details/${tokenAddress}/${version}?chainId=${chainId}`,
      );
      if (!res.ok) {
        return null;
      }
      const data = await res.json();
      return data && data.data ? data.data : null;
    }
    return;
  };

  const { isLoading, data } = useQuery({
    queryKey: ['fetchAnalyticsTokenDetails', tokenAddress, version, chainId],
    queryFn: fetchTokenDetails,
    enabled: leaderboardAvailable,
    refetchInterval: 300000,
  });

  return { isLoading, data };
};
