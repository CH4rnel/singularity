<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CYBER.sol whale verification</title>
<style>
  :root { color-scheme: dark; }
  body { margin:0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
         background:#0a0a0f; color:#e6e6f0; display:flex; min-height:100vh;
         align-items:center; justify-content:center; padding:24px; }
  .card { width:100%; max-width:460px; background:#13131c; border:1px solid #262636;
          border-radius:14px; padding:28px; }
  h1 { font-size:18px; margin:0 0 8px; }
  p { color:#9a9ab0; font-size:13px; line-height:1.55; }
  button { width:100%; padding:13px; margin-top:16px; border:0; border-radius:10px;
           background:#7c3aed; color:#fff; font-size:15px; font-weight:600; cursor:pointer; }
  button:disabled { opacity:.5; cursor:not-allowed; }
  .out { margin-top:16px; padding:14px; border-radius:10px; background:#0f0f17;
         border:1px solid #262636; font-size:13px; white-space:pre-wrap; display:none; }
  .ok { border-color:#1f7a4d; } .bad { border-color:#7a1f1f; }
  .addr { color:#a78bfa; word-break:break-all; }
</style>
</head>
<body>
<div class="card">
  <h1>Whales chat — CYBER.sol verification</h1>
  <p>Prove you hold <b>{{ number_format($threshold) }}+ CYBER.sol</b> by signing with Phantom.
     Nothing is sent on-chain — the signature only proves you control the wallet.</p>
  <button id="go">Connect Phantom &amp; sign</button>
  <div id="out" class="out"></div>
</div>

<script>
const TOKEN = @json($token);
const VALID = @json($valid);
const THRESHOLD = @json($threshold);
const out = document.getElementById('out');
const btn = document.getElementById('go');

function show(msg, kind) {
  out.style.display = 'block';
  out.className = 'out' + (kind ? ' ' + kind : '');
  out.innerHTML = msg;
}
function provider() {
  const p = window.phantom?.solana || window.solana;
  return (p && p.isPhantom) ? p : null;
}
function b64(bytes) {
  let s = '';
  for (const byte of bytes) s += String.fromCharCode(byte);
  return btoa(s);
}

if (!VALID) {
  btn.disabled = true;
  show('This link is invalid or expired. Send /whale to the bot for a fresh one.', 'bad');
}

btn.onclick = async () => {
  const p = provider();
  if (!p) {
    show('Phantom not found. Open this page in the Phantom in-app browser, or install the Phantom extension.', 'bad');
    return;
  }
  btn.disabled = true;
  try {
    show('Connecting to Phantom…');
    const { publicKey } = await p.connect();
    const address = publicKey.toString();

    show('Requesting a challenge…');
    const nRes = await fetch('/api/tg/cyber-sol/nonce', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ wallet_address: address }),
    });
    if (!nRes.ok) throw new Error('Could not get a challenge, try again.');
    const { nonce } = await nRes.json();

    show('Approve the signature in Phantom…');
    const message = 'Sign this message to authenticate with your wallet. Nonce: ' + nonce;
    const signed = await p.signMessage(new TextEncoder().encode(message), 'utf8');
    const signature = b64(signed.signature);

    show('Verifying signature & reading balance…');
    const vRes = await fetch('/api/tg/cyber-sol/verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ t: TOKEN, wallet_address: address, signature }),
    });
    const data = await vRes.json();
    if (!vRes.ok) throw new Error(data.message || 'Verification failed.');

    const bal = Number(data.balance).toLocaleString(undefined, { maximumFractionDigits: 2 });
    const addr = '<span class="addr">' + address + '</span>';
    if (data.is_whale) {
      show('✅ Verified whale!\n\n' + addr + '\n' + bal + ' CYBER.sol\n\n'
         + 'Return to Telegram — the bot will DM you a one-time invite shortly.', 'ok');
    } else {
      show('Wallet verified, but the balance is below the threshold.\n\n' + addr + '\n'
         + bal + ' CYBER.sol (need ' + THRESHOLD.toLocaleString() + ')\n\n'
         + 'Top up and run /whale again.', 'bad');
      btn.disabled = false;
    }
  } catch (e) {
    show('Error: ' + (e.message || e), 'bad');
    btn.disabled = false;
  }
};
</script>
</body>
</html>
