(load "/home/lain/quicklisp/setup.lisp")
(ql:quickload :cl-ppcre :silent t)

(defpackage :singularity.modules.ollama
  (:use :cl)
  (:export :generate :set-model))

(in-package :singularity.modules.ollama)

(defparameter *ollama-url* "http://127.0.0.1:11434")
(defparameter *default-model* "gemma4:e2b")

(defun generate (prompt &optional (model *default-model*))
  (when (or (null prompt) (string= prompt ""))
    (return-from generate ""))
  (let* ((escaped (cl-ppcre:regex-replace-all "\"" prompt "\\\""))
         (json (format nil "{\"model\":\"~A\",\"prompt\":\"~A\",\"stream\":false}" model escaped)))
    (let ((result (make-string-output-stream))
          (error (make-string-output-stream)))
      (sb-ext:run-program "/usr/bin/curl" 
                          (list "-s" "-X" "POST" 
                                (concatenate 'string *ollama-url* "/api/generate")
                                "-H" "Content-Type: application/json"
                                "-d" json)
                          :output result :error error :wait t)
      (let* ((output (get-output-stream-string result))
             (response-start (search "\"response\":\"" output))
             (response-end (search "\",\"done\":" output :from-end t)))
        (if (and response-start response-end)
            (subseq output (+ response-start 11) response-end)
            "")))))
