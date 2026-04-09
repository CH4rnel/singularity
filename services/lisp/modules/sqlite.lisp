(defpackage :singularity.modules.sqlite
  (:use :cl)
  (:export :open-db :close-db :exec-query :exec-insert :exec-select :with-db))

(in-package :singularity.modules.sqlite)

(defvar *db* nil)

(defun open-db (path)
  (when *db* (close-db))
  (setf *db* (sqlite:connect path)))

(defun close-db ()
  (when *db* 
    (sqlite:disconnect *db*)
    (setf *db* nil)))

(defmacro with-db (path &body body)
  `(let ((*db* (sqlite:connect ,path)))
     (unwind-protect (progn ,@body)
       (sqlite:disconnect *db*))))

(defun exec-query (sql)
  (when *db*
    (sqlite:execute *db* sql)))

(defun exec-insert (table &rest pairs)
  (when *db*
    (let ((cols (mapcar #'car pairs))
          (vals (mapcar (lambda (x) (if (stringp x) (format nil "'~A'" x) x)) pairs)))
      (sqlite:execute *db* 
        (format nil "INSERT INTO ~A (~{~A~^,~}) VALUES (~{~A~^,~})" 
          table cols vals)))))

(defun exec-select (sql)
  (when *db*
    (sqlite:execute-to-list *db* sql)))