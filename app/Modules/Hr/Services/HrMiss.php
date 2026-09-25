<?php

namespace App\Modules\Hr\Services;

use RuntimeException;

/** Thrown inside a rev-guarded write to abort it: 'not_found' or 'exists'. Rolls the transaction back. */
class HrMiss extends RuntimeException {}
