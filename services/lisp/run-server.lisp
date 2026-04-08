(load "/home/lain/quicklisp/setup.lisp")
(ql:quickload :hunchentoot :silent t)
(load "modules/yggdrasil.lisp")
(load "modules/ipfs.lisp")
(load "modules/ollama.lisp")

(defparameter *url-price* "https://api.dexscreener.com/latest/dex/tokens/E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump")

(defun get-json ()
  (let ((r (make-string-output-stream)) (e (make-string-output-stream)))
    (sb-ext:run-program "/usr/bin/curl" (list "-s" *url-price*) :output r :error e :wait t)
    (get-output-stream-string r)))

(defun extract-price (json which)
  (let ((needle (cond ((eq which 'usd) "\"priceUsd\":\"") (t "\"priceNative\":\""))))
    (let ((pos (search needle json)))
      (when pos
        (let ((start (+ pos (length needle))))
          (let ((end (position #\" json :start start)))
            (when end (subseq json start end))))))))

(hunchentoot:define-easy-handler (status :uri "/status") ()
  (setf (hunchentoot:content-type*) "text/plain; charset=utf-8")
  (let ((json (get-json)))
    (let ((price-usd (or (extract-price json 'usd) "N/A")))
      (let ((price-sol (or (extract-price json 'native) "N/A")))
        (format nil "~%((yggdrasil ~A)~% (ipfs ~A)~% (price-sol ~A)~% (price-usd ~A))~%"
          (singularity.modules.yggdrasil:get-ipv6)
          (singularity.modules.ipfs:get-peer-id)
          price-sol
          price-usd)))))

(format t "~A [HTTP] Server starting...~%" (get-universal-time))
(defvar *acceptor* (hunchentoot:start (make-instance (quote hunchentoot:easy-acceptor) :port 49406 :address "::")))
(format t "~A [HTTP] Server started on port 49406~%" (get-universal-time))

(loop (sleep 100))