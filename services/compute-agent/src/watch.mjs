// The payer's half: probe the service a lease pays for, and stop paying for
// a service that is not there. The most a provider can earn while lying about
// running a container is the rate times how long this takes to notice.

/**
 * @param {boolean[]} history most recent last; true = healthy
 * @param {{ paused: boolean, failuresToPause?: number, successesToResume?: number }} o
 * @returns {"pause" | "resume" | "none"}
 */
export function watchDecision(history, { paused, failuresToPause = 3, successesToResume = 3 }) {
  const tail = (n, ok) => history.length >= n && history.slice(-n).every((h) => h === ok);
  if (!paused && tail(failuresToPause, false)) return "pause";
  // A paused lease is not paid, so a provider has no reason to keep the
  // container up — resuming on health only works when the provider kept it
  // running in good faith (a network blip on the watcher's side).
  if (paused && tail(successesToResume, true)) return "resume";
  return "none";
}
