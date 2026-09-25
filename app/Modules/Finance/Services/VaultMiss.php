<?php

namespace App\Modules\Finance\Services;

use RuntimeException;

/** Aborts a vault mutation: 'not_found' or 'exists'. Rolls the transaction back. */
class VaultMiss extends RuntimeException {}
