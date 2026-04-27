/**
 * Given a URI that may be ipfs, ipns, http, or https protocol, return the fetch-able http(s) URLs for the same content
 * @param uri to convert to fetch-able http url
 */
export default function uriToHttp(uri: string): string[] {
  // Relative URLs (e.g. "/ritual-tokens.json" served from public/) are allowed
  // so the app can ship its own token list bundled with the build.
  if (uri.startsWith('/')) {
    return [uri];
  }
  const protocol = uri.split(':')[0].toLowerCase();
  switch (protocol) {
    case 'https':
      return [uri];
    case 'http':
      return ['https' + uri.substr(4), uri];
    case 'ipfs':
      const hash = uri.match(/^ipfs:(\/\/)?(.*)$/i)?.[2];
      return [
        `https://cloudflare-ipfs.com/ipfs/${hash}/`,
        `https://ipfs.io/ipfs/${hash}/`,
      ];
    case 'ipns':
      const name = uri.match(/^ipns:(\/\/)?(.*)$/i)?.[2];
      return [
        `https://cloudflare-ipfs.com/ipns/${name}/`,
        `https://ipfs.io/ipns/${name}/`,
      ];
    default:
      return [];
  }
}
