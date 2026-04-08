#!/usr/bin/env python3
import sys
import json
import base64

try:
    from solders.pubkey import Pubkey
except ImportError:
    print("SOL/USD:0.0")
    sys.exit(0)

MINT = "E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump"

# Try multiple pumpfun program IDs
PROGRAMS = [
    "6D7b1gFhr4XTqS7t1CKkVZG4h4eYmJvR3w8Y6vK1qQ3J",
    "pumpfunV1WatS8qHqWSiPLVwRgZ3qUpDExLuW4N1jkT",
    "AqPL3KxBnGhmV3VWHwWPGhKJEiHPmKfJ5c5kG5R5N7v",
]

PDA = None
import requests

for prog in PROGRAMS:
    try:
        mint = Pubkey.from_string(MINT)
        program_id = Pubkey.from_string(prog)
        pda, bump = Pubkey.find_program_address([b"bondingcurve", bytes(mint)], program_id)
        PDA = str(pda)
        
        r = requests.post(
            "https://api.mainnet-beta.solana.com",
            json={
                "jsonrpc": "2.0",
                "id": 1,
                "method": "getAccountInfo",
                "params": [PDA, {"encoding": "base64"}]
            },
            headers={"Content-Type": "application/json"},
            timeout=10
        )
        
        if r.ok:
            data = r.json()
            if data.get("result", {}).get("value"):
                encoded = data["result"]["value"]["data"][0]
                raw = base64.b64decode(encoded)
                
                # Parse bonding curve data
                token_sold = int.from_bytes(raw[8:16], "little") / 1_000_000
                sol_raised = int.from_bytes(raw[16:24], "little") / 1_000_000_000
                
                if token_sold > 0:
                    price_sol = sol_raised / token_sold
                    
                    # Get SOL/USD price
                    r2 = requests.get(
                        "https://api.coingecko.com/api/v3/simple/price?ids=solana&vs_currencies=usd",
                        timeout=10
                    )
                    if r2.ok:
                        sol_usd = r2.json().get("solana", {}).get("usd", 0)
                        price_usd = price_sol * sol_usd
                        print(f"SOL:{price_sol:.6f} USD:{price_usd:.6f}")
                        sys.exit(0)
    except Exception as e:
        continue

print("SOL:0 USD:0")