<?php

namespace App\Controllers;

use App\Repositories\WalletRepository;
use App\Helpers\Response;
use App\Helpers\Auth;
use App\Helpers\DB;
use Exception;

class WalletController {
    private WalletRepository $walletRepo;

    public function __construct() {
        $this->walletRepo = new WalletRepository();
    }

    public function show(array $request): void {
        try {
            $wallet = $this->walletRepo->findWalletByUserId(Auth::id());
            if (!$wallet) {
                // Proactively build a wallet if missing
                $walletId = $this->walletRepo->createWallet(Auth::id());
                $wallet = $this->walletRepo->findWalletByUserId(Auth::id());
            }
            Response::success($wallet, "Wallet balance retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function addFunds(array $request): void {
        if (empty($request['amount']) || (float)$request['amount'] <= 0) {
            Response::error("Invalid load amount", 422);
        }

        DB::beginTransaction();
        try {
            $wallet = $this->walletRepo->findWalletByUserId(Auth::id());
            if (!$wallet) {
                Response::error("Wallet not found", 404);
            }

            $amount = (float)$request['amount'];
            
            // Simulating gateway settlement success
            $this->walletRepo->updateBalance(
                $wallet['id'],
                $amount,
                'credit',
                "Loaded funds via online gateway",
                'TXN-' . uniqid()
            );

            DB::commit();
            
            $updatedWallet = $this->walletRepo->findWalletByUserId(Auth::id());
            Response::success($updatedWallet, "Funds added successfully to wallet");
        } catch (Exception $e) {
            DB::rollBack();
            Response::error($e->getMessage());
        }
    }

    public function transactions(array $request): void {
        try {
            $wallet = $this->walletRepo->findWalletByUserId(Auth::id());
            if (!$wallet) {
                Response::error("Wallet not found", 404);
            }

            $page = isset($request['page']) ? (int)$request['page'] : 1;
            $limit = isset($request['limit']) ? (int)$request['limit'] : 20;
            $offset = ($page - 1) * $limit;

            $txs = $this->walletRepo->getTransactionsByWalletId((int)$wallet['id'], $limit, $offset);
            Response::success($txs, "Wallet transactions list retrieved");
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
