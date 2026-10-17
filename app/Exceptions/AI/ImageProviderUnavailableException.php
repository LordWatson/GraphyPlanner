<?php

namespace App\Exceptions\AI;

/**
 * The vendor kept answering 429/5xx after backoff with jitter (max 2 retries), or rejected the request.
 */
class ImageProviderUnavailableException extends ImageProviderException {}
