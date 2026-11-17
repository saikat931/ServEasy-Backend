<?php

namespace App\Middlewares;

class CustomerMiddleware extends AuthMiddleware {
    public function __construct() {
        parent::__construct('CUSTOMER');
    }
}
