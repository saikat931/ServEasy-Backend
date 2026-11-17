<?php
/**
 * ServEasy - Provider Slot Availability API
 * Returns booked slots for single-person service providers on a specific date.
 * Registered companies are not slot-restricted.
 */
require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Response;
use App\Controllers\BookingController;

$request = array_merge($_GET, $_POST);
$json = file_get_contents('php://input');
if (!empty($json)) {
    $body = json_decode($json, true);
    if (is_array($body)) {
        $request = array_merge($request, $body);
    }
}

$controller = new BookingController();
$controller->checkAvailability($request);

