<?php

namespace App\Controllers;

use App\Services\ProviderService;
use App\Repositories\ProviderRepository;
use App\Helpers\Response;
use App\Helpers\Auth;
use Exception;

class ProviderController {
    private ProviderService $providerService;
    private ProviderRepository $providerRepo;

    public function __construct() {
        $this->providerService = new ProviderService();
        $this->providerRepo = new ProviderRepository();
    }

    public function submitKyc(array $request): void {
        $required = ['business_name', 'documents'];
        foreach ($required as $field) {
            if (empty($request[$field])) {
                Response::error("Field '{$field}' is required", 422);
            }
        }

        try {
            $this->providerService->submitKyc(Auth::id(), $request);
            Response::success(null, "KYC profile and documents submitted for review");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function withdraw(array $request): void {
        if (empty($request['amount']) || (float)$request['amount'] <= 0 || empty($request['bank_details'])) {
            Response::error("Amount and bank details are required", 422);
        }

        try {
            $this->providerService->requestWithdrawal(Auth::id(), (float)$request['amount'], $request['bank_details']);
            Response::success(null, "Payout request submitted successfully");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function analytics(array $request): void {
        try {
            $data = $this->providerService->getAnalytics(Auth::id());
            Response::success($data, "Analytics snapshot retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function updateAvailability(array $request): void {
        if (!isset($request['availability']) || !is_array($request['availability'])) {
            Response::error("Availability array is required", 422);
        }

        try {
            $provider = $this->providerRepo->findProviderByUserId(Auth::id());
            if (!$provider) {
                Response::error("Provider profile not found", 404);
            }

            $this->providerRepo->saveAvailability((int)$provider['id'], $request['availability']);
            Response::success(null, "Availability hours updated successfully");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
