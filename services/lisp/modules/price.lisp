(defpackage :singularity.modules.price (:use :cl) (:export :get-cyber-price))
(in-package :singularity.modules.price)

(defparameter *cyber-mint* "E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump")

(defun curl-get (url)
  (let ((r (make-string-output-stream)) (e (make-string-output-stream)))
    (sb-ext:run-program "/usr/bin/curl" (list "-s" url) :output r :error e :wait t)
    (get-output-stream-string r)))

(defun get-cyber-price ()
  (let ((json (curl-get (concatenate 'string "https://api.dexscreener.com/latest/dex/tokens/" *cyber-mint*))))
    (let ((q (code-char 34)))
      (let ((needle (format nil "~CpriceUsd:~C" q q)))
        (let ((ppos (search needle json)))
          (when ppos
            (let ((start (+ ppos 10)))
              (let ((end (position q json :start start)))
                (when end
                  (let ((price (subseq json start end)))
                    (let ((needle2 (format nil "~CpriceNative:~C" q q)))
                      (let ((ppos2 (search needle2 json)))
                        (when ppos2
                          (let ((start2 (+ ppos2 12)))
                            (let ((end2 (position q json :start start2)))
                              (when end2 (values price (subseq json start2 end2)))))))))))))))))
