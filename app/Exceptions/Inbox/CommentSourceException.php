<?php

namespace App\Exceptions\Inbox;

use RuntimeException;

/**
 * Step M-9.1 — a comment vendor call failed (HTTP error, vendor-reported error, bad shape, or a
 * reply the network refused). The message is safe to store and show: it never contains a key,
 * token, or raw comment body.
 */
class CommentSourceException extends RuntimeException {}
