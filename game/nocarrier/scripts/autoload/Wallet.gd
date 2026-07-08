extends Node
## Browser wallet bridge (MetaMask / any EIP-1193 provider) via JavaScriptBridge.
## Adapted from Wired's proven Wallet autoload. Web export only: on desktop it
## no-ops and reports itself unavailable, so the game runs fully offline there.
##
## A JS shim (`window.NcWallet`) holds wallet state as primitives; GDScript
## polls those primitives with eval — no async callback plumbing.

signal address_changed(address: String)
signal status_changed(status: String)
signal wallet_error(message: String)
signal tx_sent(tx_hash: String)
signal tx_failed(message: String)

const CYBERIA_CHAIN_HEX := "0xC0FE"  # 49406

var _js = null  # JavaScriptBridge singleton when on web, else null
var _last_address := ""
var _last_status := "idle"
var _last_tx := ""
var _last_txerr := ""

const JS_SHIM := """
(function(){
  if (window.NcWallet) return;
  var W = window.NcWallet = { status:'idle', address:'', chainId:'', error:'', txHash:'', txError:'' };
  W.hasProvider = function(){ return !!window.ethereum; };
  W.connect = async function(){
    if(!window.ethereum){ W.status='no_wallet'; return; }
    try{
      W.status='connecting';
      var accs = await window.ethereum.request({method:'eth_requestAccounts'});
      W.address = (accs && accs[0]) ? accs[0] : '';
      W.chainId = await window.ethereum.request({method:'eth_chainId'});
      try{
        await window.ethereum.request({method:'wallet_addEthereumChain', params:[{
          chainId:'0xC0FE', chainName:'Cyberia',
          nativeCurrency:{name:'Cyber',symbol:'CYBER',decimals:18},
          rpcUrls:['https://rpc.cyberia.church'],
          blockExplorerUrls:['https://explorer.cyberia.church']
        }]});
        W.chainId = await window.ethereum.request({method:'eth_chainId'});
      }catch(e){ /* user may decline adding the chain; not fatal */ }
      W.status = W.address ? 'connected' : 'idle';
      if(window.ethereum.on){
        window.ethereum.on('accountsChanged', function(a){ W.address=(a&&a[0])?a[0]:''; if(!W.address){ W.status='idle'; } });
        window.ethereum.on('chainChanged', function(c){ W.chainId=c; });
      }
    }catch(e){ W.status='error'; W.error=(e&&e.message)?e.message:String(e); }
  };
  W.sendTx = async function(to, data, value){
    if(!window.ethereum || !W.address) return;
    W.txHash=''; W.txError='';
    try{
      var p = { from: W.address, to: to, data: data };
      if(value){ p.value = value; }
      W.txHash = await window.ethereum.request({method:'eth_sendTransaction', params:[p]});
    }catch(e){ W.txError=(e&&e.message)?e.message:String(e); }
  };
})();
"""


func _ready() -> void:
	if OS.has_feature("web") and Engine.has_singleton("JavaScriptBridge"):
		_js = Engine.get_singleton("JavaScriptBridge")
		_js.eval(JS_SHIM, true)
		var timer := Timer.new()
		timer.wait_time = 0.5
		timer.autostart = true
		add_child(timer)
		timer.timeout.connect(_poll)


## True only in the browser build with a JS bridge available.
func available() -> bool:
	return _js != null


func is_connected_wallet() -> bool:
	return _last_status == "connected" and _last_address != ""


func get_address() -> String:
	return _last_address


func short_address() -> String:
	if _last_address.length() < 12:
		return _last_address
	return _last_address.substr(0, 6) + "…" + _last_address.substr(_last_address.length() - 4)


func connect_wallet() -> void:
	if _js == null:
		wallet_error.emit("wallet is only available in the browser build")
		return
	_js.eval("window.NcWallet && window.NcWallet.connect()", true)


## Send a transaction through the connected wallet. `data` is 0x-prefixed
## calldata; `value` is optional 0x-prefixed wei. The user approves in wallet UI.
func send_transaction(to: String, data: String, value: String = "") -> void:
	if _js == null:
		wallet_error.emit("wallet is only available in the browser build")
		return
	var js := "window.NcWallet && window.NcWallet.sendTx(%s,%s,%s)" % [
		JSON.stringify(to), JSON.stringify(data), JSON.stringify(value),
	]
	_js.eval(js, true)


func _poll() -> void:
	var status := _eval_str("window.NcWallet ? window.NcWallet.status : 'none'")
	var addr := _eval_str("window.NcWallet ? window.NcWallet.address : ''")
	var err := _eval_str("window.NcWallet ? window.NcWallet.error : ''")
	var tx := _eval_str("window.NcWallet ? window.NcWallet.txHash : ''")
	var txerr := _eval_str("window.NcWallet ? window.NcWallet.txError : ''")

	if tx != "" and tx != _last_tx:
		_last_tx = tx
		tx_sent.emit(tx)
	if txerr != "" and txerr != _last_txerr:
		_last_txerr = txerr
		tx_failed.emit(txerr)

	if status != _last_status:
		_last_status = status
		status_changed.emit(status)
		if status == "error" and err != "":
			wallet_error.emit(err)
		elif status == "no_wallet":
			wallet_error.emit("no browser wallet found (install MetaMask)")
	if addr != _last_address:
		_last_address = addr
		address_changed.emit(addr)


func _eval_str(expr: String) -> String:
	if _js == null:
		return ""
	var v: Variant = _js.eval(expr, true)
	return "" if v == null else str(v)
