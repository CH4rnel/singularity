(defpackage :singularity.modules.ipfs
  (:use :cl)
  (:export :get-peer-id :get-addresses :is-running :check-process :add-file :list-pins))

(in-package :singularity.modules.ipfs)

(defparameter *ipfs-bin* "/opt/IPFS Desktop/resources/app.asar.unpacked/node_modules/kubo/kubo/ipfs")
(defparameter *ipfs-api* "http://127.0.0.1:5001/api/v0")

(defun check-process ()
  (handler-case
      (zerop (sb-ext:process-exit-code
              (sb-ext:run-program "pgrep" '("-x" "ipfs-desktop") :wait t :output nil :error nil)))
    (error () nil)))

(defun is-running ()
  (handler-case
      (progn
        (sb-ext:run-program "pgrep" '("-x" "ipfs-desktop") :wait t :output nil :error nil)
        t)
    (error () nil)))

(defun get-peer-id ()
  (let ((result (make-string-output-stream)))
    (handler-case
        (sb-ext:run-program *ipfs-bin* '("id" "-f" "<id>") 
                          :output result :error result :wait t)
      (error () "error"))
    (let* ((output (get-output-stream-string result))
           (trimmed (string-trim '(#\Newline #\Space #\Tab) output)))
      (if (or (string= "" trimmed) (search "error" trimmed :test #'char-equal))
          "not found"
          trimmed))))

(defun get-addresses ()
  (let ((result (make-string-output-stream)))
    (handler-case
        (sb-ext:run-program "/usr/bin/curl" '("-s" "-X" "POST" "http://127.0.0.1:5001/api/v0/id") 
                          :output result :error result :wait t)
      (error () "error"))
    (let* ((output (get-output-stream-string result))
           (start (search "\"Addresses\":" output))
           (bracket-start (position #\[ output :start (or start 0)))
           (bracket-end (position #\] output :start (or bracket-start 0))))
      (if (and bracket-start bracket-end)
          (subseq output (1+ bracket-start) bracket-end)
          ""))))

(defun add-file (content filename)
  (when (or (null content) (string= content ""))
    (return-from add-file "error: empty content"))
  (let ((temp-file (format nil "/tmp/~A" filename)))
    (with-open-file (out temp-file :direction :output :if-exists :supersede)
      (write-string content out))
    (let ((result (make-string-output-stream))
          (error (make-string-output-stream)))
      (sb-ext:run-program "/usr/bin/curl"
                          (list "-s" "-X" "POST" 
                                (concatenate 'string *ipfs-api* "/add")
                                "-F" (format nil "file=@~A" temp-file))
                          :output result :error error :wait t)
      (let* ((output (get-output-stream-string result))
             (hash-start (search "\"Hash\":\"" output))
             (hash-end (and hash-start (position #\" output :start (+ hash-start 8)))))
        (if (and hash-start hash-end)
            (subseq output (+ hash-start 8) hash-end)
            "error")))))

(defun list-pins ()
  (let ((result (make-string-output-stream)))
    (handler-case
        (sb-ext:run-program "/usr/bin/curl"
                            (list "-s" "-X" "POST" 
                                  (concatenate 'string *ipfs-api* "/pin/ls"))
                            :output result :error result :wait t)
      (error () "[]"))
    (let* ((output (get-output-stream-string result))
           (keys nil)
           (search-pos 0))
      (loop do (let* ((key-start (search "\"Key\":\"" output :start2 search-pos))
                      (key-end (when key-start (search "\",\"Type\":" output :start2 (+ key-start 8)))))
                 (cond ((and key-start key-end)
                        (push (subseq output (+ key-start 8) key-end) keys)
                        (setf search-pos (+ key-end 1)))
                       (t (loop-finish)))))
      (format nil "~{~A~^~% ~}" (reverse keys)))))
