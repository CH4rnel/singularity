import { useCallback, useEffect, useState } from 'react';

/**
 * Tiny drop-in replacement for `@orderly.network/hooks::useLocalStorage`.
 * Persists a JSON-serialisable value under `key` in window.localStorage.
 */
export function useLocalStorage<T>(
  key: string,
  initialValue: T,
): [T, (value: T | ((prev: T) => T)) => void] {
  const read = useCallback((): T => {
    if (typeof window === 'undefined') return initialValue;
    try {
      const item = window.localStorage.getItem(key);
      return item != null ? (JSON.parse(item) as T) : initialValue;
    } catch {
      return initialValue;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  const [stored, setStored] = useState<T>(read);

  const setValue = useCallback(
    (value: T | ((prev: T) => T)) => {
      setStored((prev) => {
        const next =
          typeof value === 'function'
            ? (value as (p: T) => T)(prev)
            : value;
        try {
          if (typeof window !== 'undefined') {
            window.localStorage.setItem(key, JSON.stringify(next));
          }
        } catch {
          // ignore quota / serialisation errors
        }
        return next;
      });
    },
    [key],
  );

  // Re-read on key change so different keys don't share state.
  useEffect(() => {
    setStored(read());
  }, [read]);

  return [stored, setValue];
}

export default useLocalStorage;
