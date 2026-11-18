<?php

namespace App\Controllers;

use App\Repositories\ServiceRepository;
use App\Repositories\ProviderRepository;
use App\Helpers\Response;
use App\Helpers\Auth;
use Exception;

class ServiceController {
    private ServiceRepository $serviceRepo;
    private ProviderRepository $providerRepo;

    public function __construct() {
        $this->serviceRepo = new ServiceRepository();
        $this->providerRepo = new ProviderRepository();
    }

    public function getCategories(array $request): void {
        try {
            $categories = $this->serviceRepo->getCategories();
            Response::success($categories, "Categories retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function getSubcategories(array $request): void {
        if (empty($request['category_id'])) {
            Response::error("Category ID is required", 422);
        }

        try {
            $subs = $this->serviceRepo->getSubcategories((int)$request['category_id']);
            Response::success($subs, "Subcategories retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function getCategoriesOverview(): void {
        try {
            $overview = $this->serviceRepo->getCategoriesOverview();
            Response::success($overview, "Categories overview retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function show(array $request): void {
        if (empty($request['id'])) {
            Response::error("Service ID is required", 422);
        }

        try {
            $service = $this->serviceRepo->findServiceById((int)$request['id']);
            if (!$service) {
                Response::error("Service not found", 404);
            }
            Response::success($service, "Service details retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function search(array $request): void {
        try {
            $results = $this->serviceRepo->search($request);
            Response::success($results, "Search completed");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function store(array $request): void {
        $userId = Auth::id();
        $provider = $this->providerRepo->findProviderByUserId($userId);
        if (!$provider || $provider['kyc_status'] !== 'approved') {
            Response::error("Only verified providers can create services", 403);
        }

        $required = ['subcategory_id', 'name', 'base_price'];
        foreach ($required as $field) {
            if (empty($request[$field])) {
                Response::error("Field '{$field}' is required", 422);
            }
        }

        try {
            $request['provider_id'] = $provider['id'];
            $serviceId = $this->serviceRepo->createService($request);

            // Add service image if supplied
            if (!empty($request['image_url'])) {
                $this->serviceRepo->addImage($serviceId, $request['image_url'], true);
            }

            // Add packages if supplied
            if (!empty($request['packages']) && is_array($request['packages'])) {
                foreach ($request['packages'] as $pkg) {
                    $pkg['service_id'] = $serviceId;
                    $this->serviceRepo->addPackage($pkg);
                }
            }

            Response::success(['service_id' => $serviceId], "Service created successfully", 21);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function update(array $request): void {
        if (empty($request['id'])) {
            Response::error("Service ID is required", 422);
        }

        try {
            $service = $this->serviceRepo->findServiceById((int)$request['id']);
            if (!$service) {
                Response::error("Service not found", 404);
            }

            $provider = $this->providerRepo->findProviderByUserId(Auth::id());
            if (!$provider || $service['provider_id'] !== $provider['id']) {
                Response::error("Unauthorized to modify this service", 403);
            }

            $this->serviceRepo->updateService((int)$request['id'], $request);
            Response::success(null, "Service updated successfully");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function destroy(array $request): void {
        if (empty($request['id'])) {
            Response::error("Service ID is required", 422);
        }

        try {
            $service = $this->serviceRepo->findServiceById((int)$request['id']);
            if (!$service) {
                Response::error("Service not found", 404);
            }

            $provider = $this->providerRepo->findProviderByUserId(Auth::id());
            if (!$provider || $service['provider_id'] !== $provider['id']) {
                Response::error("Unauthorized to delete this service", 403);
            }

            $this->serviceRepo->deleteService((int)$request['id']);
            Response::success(null, "Service deleted successfully");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
