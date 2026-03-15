<?php

namespace Drupal\instruckt_drupal\Exception;

/**
 * Thrown by InstrucktStore when a storage operation fails (I/O, lock, encode).
 *
 * Callers that receive NULL from a store method interpret it as "not found".
 * Callers that catch InstrucktStorageException interpret it as a 500-class error.
 */
class InstrucktStorageException extends \RuntimeException {}
