(load "/home/lain/quicklisp/setup.lisp")
(load "modules/yggdrasil.lisp")
(load "modules/ipfs.lisp")
(load "modules/ollama.lisp")
(ql:quickload :hunchentoot :silent t)

(hunchentoot:define-easy-handler (status :uri "/status") ()
  (setf (hunchentoot:content-type*) "text/plain; charset=utf-8")
  (format nil "~%((yggdrasil ~A)~% (ipfs ~A))~%"
    (singularity.modules.yggdrasil:get-ipv6)
    (singularity.modules.ipfs:get-peer-id)))

(hunchentoot:define-easy-handler (generate :uri "/generate" :default-request-type :post) ()
  (setf (hunchentoot:content-type*) "text/plain; charset=utf-8")
  (let* ((prompt (hunchentoot:parameter "prompt"))
         (model (or (hunchentoot:parameter "model") "deepseek-r1:1.5b")))
    (handler-case
        (progn
          (when (or (null prompt) (string= prompt ""))
            (return-from generate "~%((error empty prompt))~%"))
          (let* ((text (singularity.modules.ollama:generate prompt model))
                 (timestamp (format nil "~A" (get-universal-time)))
                 (filename (format nil "response-~A.txt" timestamp))
                 (ipfs-hash (singularity.modules.ipfs:add-file text filename)))
            (format nil "~%((text ~A)~% (ipfs ~A))~%" text ipfs-hash)))
      (error (e)
        (format nil "~%((error ~A))~%" e)))))

(hunchentoot:define-easy-handler (pins :uri "/pins") ()
  (setf (hunchentoot:content-type*) "text/plain; charset=utf-8")
  (singularity.modules.ipfs:list-pins))

(format t "~A [HTTP] Server starting...~%" (get-universal-time))
(defvar *acceptor* (hunchentoot:start (make-instance 'hunchentoot:easy-acceptor :port 49406 :address "::")))
(format t "~A [HTTP] Server started on port 49406~%" (get-universal-time))

(loop (sleep 100))
