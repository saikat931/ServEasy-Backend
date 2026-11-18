<?php

namespace App\Repositories;

use App\Helpers\DB;
use PDO;

class ServiceRepository {
    public function getCategories(): array {
        $stmt = DB::getConnection()->prepare("SELECT * FROM `serveasy-categories` WHERE is_active = 1 ORDER BY name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getSubcategories(int $categoryId): array {
        $stmt = DB::getConnection()->prepare("SELECT * FROM `serveasy-subcategories` WHERE category_id = :category_id AND is_active = 1 ORDER BY name ASC");
        $stmt->execute(['category_id' => $categoryId]);
        return $stmt->fetchAll();
    }

    public function getCategoriesOverview(): array {
        $db = DB::getConnection();

        $categories = $db->query("SELECT * FROM `serveasy-categories` WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

        $subcategories = $db->query("
            SELECT 
                sub.id AS subcategory_id,
                sub.category_id,
                sub.name AS subcategory_name,
                sub.slug AS subcategory_slug,
                sub.icon_class,
                cat.name AS category_name,
                cat.slug AS category_slug,
                s.id AS service_id,
                s.name AS service_name,
                s.slug AS service_slug,
                s.base_price AS service_price
            FROM `serveasy-subcategories` sub
            JOIN `serveasy-categories` cat ON sub.category_id = cat.id
            LEFT JOIN `serveasy-services` s 
                ON s.subcategory_id = sub.id AND s.is_active = 1
            WHERE sub.is_active = 1
            ORDER BY sub.category_id ASC, sub.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $statsRows = $db->query("
            SELECT 
                sub.category_id,
                COUNT(DISTINCT s.id) AS service_count,
                MIN(s.base_price) AS min_price
            FROM `serveasy-services` s
            JOIN `serveasy-subcategories` sub ON s.subcategory_id = sub.id
            WHERE s.is_active = 1
            GROUP BY sub.category_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'categories'    => $categories,
            'subcategories' => $subcategories,
            'stats'         => $statsRows,
        ];
    }

    public function findServiceById(int $id): ?array {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            SELECT s.*, COALESCE(c.company_name, p.full_name) as business_name, 4.5 as provider_rating, sub.name as subcategory_name, cat.name as category_name
            FROM `serveasy-services` s
            LEFT JOIN `serveasy-providers` p ON s.provider_id = p.id
            LEFT JOIN `serveasy-company` c ON c.provider_id = p.id
            LEFT JOIN `serveasy-subcategories` sub ON s.subcategory_id = sub.id
            LEFT JOIN `serveasy-categories` cat ON sub.category_id = cat.id
            WHERE s.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $service = $stmt->fetch();
        if (!$service) return null;

        // Fetch Packages
        $stmtPkg = $db->prepare("SELECT * FROM `serveasy-service_packages` WHERE service_id = :service_id");
        $stmtPkg->execute(['service_id' => $id]);
        $service['packages'] = $stmtPkg->fetchAll();

        // Fetch Images
        $stmtImg = $db->prepare("SELECT * FROM `serveasy-service_images` WHERE service_id = :service_id");
        $stmtImg->execute(['service_id' => $id]);
        $service['images'] = $stmtImg->fetchAll();

        return $service;
    }

    public function createService(array $data): int {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            INSERT INTO `serveasy-services` (provider_id, subcategory_id, name, slug, description, base_price, duration_minutes, is_active) 
            VALUES (:provider_id, :subcategory_id, :name, :slug, :description, :base_price, :duration_minutes, 1)
        ");
        $stmt->execute([
            'provider_id' => $data['provider_id'],
            'subcategory_id' => $data['subcategory_id'],
            'name' => $data['name'],
            'slug' => strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['name']))),
            'description' => $data['description'] ?? null,
            'base_price' => $data['base_price'],
            'duration_minutes' => $data['duration_minutes'] ?? 60
        ]);
        return (int)$db->lastInsertId();
    }

    public function addPackage(array $data): int {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            INSERT INTO `serveasy-service_packages` (service_id, name, description, price, features) 
            VALUES (:service_id, :name, :description, :price, :features)
        ");
        $stmt->execute([
            'service_id' => $data['service_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'features' => json_encode($data['features'] ?? [])
        ]);
        return (int)$db->lastInsertId();
    }

    public function addImage(int $serviceId, string $imageUrl, bool $isPrimary = false): int {
        $db = DB::getConnection();
        $stmt = $db->prepare("
            INSERT INTO `serveasy-service_images` (service_id, image_url, is_primary) 
            VALUES (:service_id, :image_url, :is_primary)
        ");
        $stmt->execute([
            'service_id' => $serviceId,
            'image_url' => $imageUrl,
            'is_primary' => $isPrimary ? 1 : 0
        ]);
        return (int)$db->lastInsertId();
    }

    public function updateService(int $id, array $data): bool {
        $stmt = DB::getConnection()->prepare("
            UPDATE `serveasy-services`
            SET name = :name, description = :description, base_price = :base_price, duration_minutes = :duration_minutes, is_active = :is_active 
            WHERE id = :id
        ");
        return $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'base_price' => $data['base_price'],
            'duration_minutes' => $data['duration_minutes'],
            'is_active' => $data['is_active']
        ]);
    }

    public function deleteService(int $id): bool {
        $stmt = DB::getConnection()->prepare("DELETE FROM `serveasy-services` WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    public function getServicesByProvider(int $providerId): array {
        $stmt = DB::getConnection()->prepare("SELECT * FROM `serveasy-services` WHERE provider_id = :provider_id ORDER BY id DESC");
        $stmt->execute(['provider_id' => $providerId]);
        return $stmt->fetchAll();
    }

    public function search(array $filters): array {
        $db = DB::getConnection();
        $query = "
            SELECT s.*, COALESCE(c.company_name, p.full_name) as business_name, 4.5 as provider_rating, img.image_url as primary_image,
                   p.location_address as provider_address, p.phone as provider_phone,
                   sub.name as subcategory_name, sub.icon_class as subcategory_icon,
                   cat.name as category_name, cat.id as category_id, cat.slug as category_slug
            FROM `serveasy-services` s
            LEFT JOIN `serveasy-providers` p ON s.provider_id = p.id
            LEFT JOIN `serveasy-company` c ON c.provider_id = p.id
            LEFT JOIN `serveasy-subcategories` sub ON s.subcategory_id = sub.id
            LEFT JOIN `serveasy-categories` cat ON sub.category_id = cat.id
            LEFT JOIN `serveasy-service_images` img ON s.id = img.service_id AND img.is_primary = 1
            WHERE 1=1
        ";
        
        $params = [];

        if (!empty($filters['provider_id'])) {
            $query .= " AND s.provider_id = :provider_id";
            $params['provider_id'] = (int)$filters['provider_id'];
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query .= " AND s.is_active = :is_active";
            $params['is_active'] = (int)$filters['is_active'];
        }

        if (!empty($filters['subcategory_id'])) {
            $query .= " AND s.subcategory_id = :subcategory_id";
            $params['subcategory_id'] = $filters['subcategory_id'];
        }

        if (!empty($filters['category_id']) && $filters['category_id'] !== 'all') {
            if (is_numeric($filters['category_id'])) {
                $query .= " AND s.subcategory_id IN (SELECT id FROM `serveasy-subcategories` WHERE category_id = :category_id)";
                $params['category_id'] = (int)$filters['category_id'];
            } else {
                $query .= " AND s.subcategory_id IN (SELECT sub.id FROM `serveasy-subcategories` sub JOIN `serveasy-categories` cat ON sub.category_id = cat.id WHERE cat.slug = :category_slug)";
                $params['category_slug'] = $filters['category_id'];
            }
        }

        if (!empty($filters['slug'])) {
            $query .= " AND s.slug = :service_slug";
            $params['service_slug'] = $filters['slug'];
        }

        if (!empty($filters['query'])) {
            $query .= " AND (s.name LIKE :search_query OR s.description LIKE :search_query OR COALESCE(c.company_name, p.full_name) LIKE :search_query)";
            $params['search_query'] = '%' . $filters['query'] . '%';
        }

        if (isset($filters['min_price'])) {
            $query .= " AND s.base_price >= :min_price";
            $params['min_price'] = $filters['min_price'];
        }

        if (isset($filters['max_price'])) {
            $query .= " AND s.base_price <= :max_price";
            $params['max_price'] = $filters['max_price'];
        }

        // Apply Sorting
        $sort = $filters['sort'] ?? 'default';
        if ($sort === 'title-asc') {
            $query .= " ORDER BY s.name ASC, s.id ASC";
        } elseif ($sort === 'title-desc') {
            $query .= " ORDER BY s.name DESC, s.id ASC";
        } elseif ($sort === 'newest') {
            $query .= " ORDER BY s.id DESC";
        } elseif ($sort === 'price-asc') {
            $query .= " ORDER BY s.base_price ASC, s.id ASC";
        } elseif ($sort === 'price-desc') {
            $query .= " ORDER BY s.base_price DESC, s.id ASC";
        } else {
            // Default: natural order of services listed in the database
            $query .= " ORDER BY s.id ASC";
        }

        // Apply Pagination
        $limit = isset($filters['limit']) ? (int)$filters['limit'] : null;
        $offset = isset($filters['offset']) ? (int)$filters['offset'] : null;

        if ($limit !== null) {
            $query .= " LIMIT " . $limit;
            if ($offset !== null) {
                $query .= " OFFSET " . $offset;
            }
        }

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $services = $stmt->fetchAll();
        foreach ($services as &$srv) {
            $stmtPkg = $db->prepare("SELECT * FROM `serveasy-service_packages` WHERE service_id = :service_id ORDER BY price ASC");
            $stmtPkg->execute(['service_id' => $srv['id']]);
            $srv['packages'] = $stmtPkg->fetchAll();

            $stmtAdd = $db->prepare("SELECT * FROM `serveasy-service_addons` WHERE service_id = :service_id ORDER BY id ASC");
            $stmtAdd->execute(['service_id' => $srv['id']]);
            $srv['addons'] = $stmtAdd->fetchAll();
        }
        unset($srv);
        return $services;
    }
}
