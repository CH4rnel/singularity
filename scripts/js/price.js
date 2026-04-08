const { bondingCurvePda } = require('@pump-fun/pump-sdk');
const { Connection } = require('@solana/web3.js');
const BN = require('bn.js');
const fetch = require('node-fetch');

const MINT = 'E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump';
// Используй платный RPC или Helius/QuickNode, публичный нестабильный
const RPC = 'https://api.mainnet-beta.solana.com';

async function getSolPriceUSD() {
  const res = await fetch('https://api.coingecko.com/api/v3/simple/price?ids=solana&vs_currencies=usd');
  const data = await res.json();
  return data.solana.usd;
}

async function getCyberPrice() {
  const conn = new Connection(RPC);
  const curvePda = bondingCurvePda(MINT);
  const info = await conn.getAccountInfo(curvePda);

  const d = info.data;
  const virtualTokenReserves = new BN(d.slice(8,  16), 'le');
  const virtualSolReserves   = new BN(d.slice(16, 24), 'le');

  const priceSOL = virtualSolReserves.toNumber() / virtualTokenReserves.toNumber() / 1e3;
  return priceSOL;
}

async function main() {
  const [cyberPriceSOL, solPriceUSD] = await Promise.all([
    getCyberPrice(),
    getSolPriceUSD()
  ]);

  const priceInSOL = cyberPriceSOL * 1e3;
  const priceInUSD = priceInSOL * solPriceUSD;

  console.log(`Cyber price: ${priceInSOL.toFixed(6)} SOL ($${priceInUSD.toFixed(6)})`);
}

main();