#!/bin/bash
# Get Cyber token price from PumpFun bonding curve

MINT="E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump"
PROGRAM_ID="PMum4zxiyP3QnD6Vdt1UdLaZrvzpn2Xaj7W5zT4F1CL"

# Derive bonding curve PDA using Python
PDA=$(python3 -c "
import base64
import hashlib

mint_bytes = bytes.fromhex('$(echo $MINT | cut -c2-)')
seed = b'bon' + mint_bytes

# Try different bumps
for bump in range(256):
    data = seed + bytes([bump]) + bytes.fromhex('$(echo $PROGRAM_ID | cut -c2-)')
    h = hashlib.sha256(data).digest()
    if int.from_bytes(h[:4], 'little') & 1 == 0:
        print(base64.b64encode(bytes([bump]) + h[:31]).decode().rstrip('='))
        break
")

# Try with solana-test-validator or rpc
echo "Using simpler approach with findProgramAddress"
curl -s -X POST https://api.mainnet-beta.solana.com -H "Content-Type: application/json" -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"getAccountInfo\",\"params\":[\"Cobbswp4mTaHPpAtJ5hVLfmE7wA6uHz7yR4rU88XLEH\",{\"encoding\":\"base64\"}]}" | head -c 300