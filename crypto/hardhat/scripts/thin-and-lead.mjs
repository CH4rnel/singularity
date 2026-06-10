import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import fs from "node:fs"; import os from "node:os"; import path from "node:path";

const RPC="https://rpc.cyberia.church";
const chain={id:49406,name:"Cyberia",nativeCurrency:{name:"Cyber",symbol:"CYBER",decimals:18},rpcUrls:{default:{http:[RPC]}}};
const wallet=JSON.parse(fs.readFileSync(path.join(os.homedir(),".cyberia-claude-wallet.json"),"utf8"));
const account=privateKeyToAccount(wallet.privateKey);
const wc=createWalletClient({chain,account,transport:http(RPC)});
const pc=createPublicClient({chain,transport:http(RPC)});

const ROUTER="0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62";
const WCYBER="0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9";
const CLAUDE="0xD90e5d4284c763ecC8cDF7dC355d1Cd8a9D7899b";
const PAIR="0x63E3285536f3E7245891F9B1f5958BAD09921C18";
const HERMES_PRICE=1.0; const TARGET=2.0; const KEEP_BPS=1n; // keep 1/10000 = 0.01%

const erc20=[{type:"function",name:"approve",stateMutability:"nonpayable",inputs:[{type:"address"},{type:"uint256"}],outputs:[{type:"bool"}]},{type:"function",name:"balanceOf",stateMutability:"view",inputs:[{type:"address"}],outputs:[{type:"uint256"}]}];
const pairAbi=[{type:"function",name:"getReserves",stateMutability:"view",inputs:[],outputs:[{type:"uint112"},{type:"uint112"},{type:"uint32"}]},{type:"function",name:"token0",stateMutability:"view",inputs:[],outputs:[{type:"address"}]}];
const routerAbi=[
 {type:"function",name:"removeLiquidityETH",stateMutability:"nonpayable",inputs:[{type:"address"},{type:"uint256"},{type:"uint256"},{type:"uint256"},{type:"address"},{type:"uint256"}],outputs:[{type:"uint256"},{type:"uint256"}]},
 {type:"function",name:"swapExactETHForTokens",stateMutability:"payable",inputs:[{type:"uint256"},{type:"address[]"},{type:"address"},{type:"uint256"}],outputs:[{type:"uint256[]"}]}];

async function reserves(){const [r0,r1]=await pc.readContract({address:PAIR,abi:pairAbi,functionName:"getReserves"});const t0=await pc.readContract({address:PAIR,abi:pairAbi,functionName:"token0"});const is0=t0.toLowerCase()===CLAUDE.toLowerCase();return{x:is0?r0:r1,y:is0?r1:r0};}
function amountOut(ai,ri,ro){const f=ai*997n;return (f*ro)/(ri*1000n+f);}
function solve(r,target,maxIn){let lo=0n,hi=maxIn,best=null;for(let i=0;i<64;i++){const mid=(lo+hi)/2n;if(mid===0n){lo=1n;continue;}const out=amountOut(mid,r.y,r.x);const np=Number(r.y+mid)/Number(r.x-out);if(np>=target){best={spend:mid,out,np};hi=mid;}else lo=mid+1n;}return best;}
const dl=()=>BigInt(Math.floor(Date.now()/1000)+300);

(async()=>{
  const lp=await pc.readContract({address:PAIR,abi:erc20,functionName:"balanceOf",args:[account.address]});
  const remove=lp - (lp*KEEP_BPS)/10000n;
  console.log("LP total:",lp.toString(),"removing:",remove.toString(),`(keeping ${KEEP_BPS}/10000)`);

  // 1. approve LP to router
  let h=await wc.writeContract({address:PAIR,abi:erc20,functionName:"approve",args:[ROUTER,lp],gas:80000n});
  await pc.waitForTransactionReceipt({hash:h}); console.log("approve LP:",h);

  // 2. removeLiquidityETH (mins 0 — single actor, low-traffic chain)
  h=await wc.writeContract({address:ROUTER,abi:routerAbi,functionName:"removeLiquidityETH",args:[CLAUDE,remove,0n,0n,account.address,dl()],gas:400000n});
  let rc=await pc.waitForTransactionReceipt({hash:h}); console.log("removeLiquidityETH:",h,rc.status);

  // 3. recompute thin pool, solve buy to TARGET price
  const r=await reserves();
  console.log("post-remove pool: CLAUDE",Number(r.x)/1e18,"/ CYBER",Number(r.y)/1e18,"price",(Number(r.y)/Number(r.x)).toExponential(4));
  const bal=await pc.getBalance({address:account.address});
  const budget=bal - 5n*10n**16n; // keep 0.05 CYBER gas
  const sol=solve(r,TARGET,budget);
  if(!sol){console.log("cannot reach target within budget; aborting buy");return;}
  const minOut=(sol.out*99n)/100n;
  console.log(`buy: spend ${(Number(sol.spend)/1e18).toFixed(6)} CYBER -> price ~${sol.np.toExponential(4)}`);
  h=await wc.writeContract({address:ROUTER,abi:routerAbi,functionName:"swapExactETHForTokens",args:[minOut,[WCYBER,CLAUDE],account.address,dl()],value:sol.spend,gas:300000n});
  rc=await pc.waitForTransactionReceipt({hash:h}); console.log("swap:",h,rc.status);

  const r2=await reserves();
  console.log("FINAL CLAUDE price:",(Number(r2.y)/Number(r2.x)).toExponential(4),"CYBER  (HERMES",HERMES_PRICE,") =>",(Number(r2.y)/Number(r2.x))>HERMES_PRICE?"CLAUDE LEADS":"behind");
  const claudeBal=await pc.readContract({address:CLAUDE,abi:erc20,functionName:"balanceOf",args:[account.address]});
  const fin=await pc.getBalance({address:account.address});
  console.log("treasury now: CLAUDE",(Number(claudeBal)/1e18).toFixed(0),"/ native CYBER",(Number(fin)/1e18).toFixed(4));
})().catch(e=>{console.error("ERR:",e.shortMessage||e.message);process.exit(1);});
