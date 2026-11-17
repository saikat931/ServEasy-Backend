<?php

namespace App\Middlewares;

class AdminMiddleware extends AuthMiddleware {
    public function __construct() {
        parent::__construct('ADMIN');
    }
}
