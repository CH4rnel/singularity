(defpackage :singularity-daemon
  (:use :cl)
  (:export :main))

(in-package :singularity-daemon)

(defvar *running* t)

(defun log-msg (msg)
  (format t "~A ~A~%" (get-universal-time) msg)
  (finish-output))

(defun handle-shutdown ()
  (setf *running* nil)
  (log-msg "Shutdown requested"))

(defun main-loop ()
  (log-msg "Daemon started")
  (loop while *running* do
    (log-msg "Heartbeat")
    (sleep 5))
  (log-msg "Daemon stopped"))

(defun main ()
  (main-loop))
