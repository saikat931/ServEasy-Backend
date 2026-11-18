<?php
require_once __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? 'categories';
$controller = new \App\Controllers\ServiceController();

if ($action === 'overview') {
    $controller->getCategoriesOverview();
} elseif ($action === 'subcategories' || isset($_GET['category_id'])) {
    $controller->getSubcategories($_GET);
} else {
    $controller->getCategories($_GET);
}
