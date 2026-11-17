<?php

namespace App\Middlewares;

class ProviderMiddleware extends AuthMiddleware {
    public function __construct() {
        parent::__construct('PROVIDER');
    }
}
