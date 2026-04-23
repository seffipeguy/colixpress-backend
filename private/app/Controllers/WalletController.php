<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\User;
use App\Services\WalletService;

class WalletController extends Controller
{
    /**
     * GET /api/wallet
     * Solde du portefeuille de l'utilisateur connecté
     */
    public function balance(Request $request): void
    {
        $walletModel = new Wallet();
        $wallet = $walletModel->getOrCreate($this->userId());

        Response::success([
            'balance'    => (int) $wallet['balance'],
            'currency'   => $wallet['currency'],
            'updated_at' => $wallet['updated_at'],
        ]);
    }

    /**
     * GET /api/wallet/transactions
     * Historique des transactions paginé
     */
    public function transactions(Request $request): void
    {
        $walletModel = new Wallet();
        $wallet = $walletModel->getOrCreate($this->userId());

        $txModel = new WalletTransaction();
        $result  = $txModel->getByWallet((int) $wallet['id'], $request->page(), $request->perPage());

        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * POST /api/admin/wallet/top-up
     * Admin crédite manuellement le portefeuille d'un utilisateur
     * Body: { "user_id": 5, "amount": 5000, "description": "Rechargement manuel" }
     */
    public function topUp(Request $request): void
    {
        $this->requireRole('admin');
        $request->validate(['user_id', 'amount']);

        $userId = (int) $request->input('user_id');
        $amount = (int) $request->input('amount');

        $userModel = new User();
        $user = $userModel->find($userId);
        if (!$user) {
            Response::notFound('Utilisateur introuvable');
        }

        $description = $request->input('description', 'Rechargement manuel par admin');

        $walletService = new WalletService();
        $result = $walletService->credit(
            $userId,
            $amount,
            'top_up',
            $description,
            null,
            $this->userId()
        );

        Response::success([
            'user_id'        => $userId,
            'amount_credited'=> $amount,
            'balance_before' => $result['balance_before'],
            'balance_after'  => $result['balance_after'],
            'transaction_id' => $result['transaction_id'],
            'currency'       => 'XAF',
        ], 'Portefeuille crédité avec succès');
    }

    /**
     * GET /api/admin/wallet/{user_id}
     * Admin consulte le portefeuille d'un utilisateur
     */
    public function adminShow(Request $request): void
    {
        $this->requireRole('admin');

        $userId = (int) $request->param('user_id');
        $userModel = new User();
        $user = $userModel->find($userId);
        if (!$user) {
            Response::notFound('Utilisateur introuvable');
        }

        $walletModel = new Wallet();
        $wallet = $walletModel->getOrCreate($userId);

        $txModel = new WalletTransaction();
        $result  = $txModel->getByWallet((int) $wallet['id'], $request->page(), $request->perPage());

        Response::success([
            'user'         => [
                'id'         => $user['id'],
                'first_name' => $user['first_name'],
                'last_name'  => $user['last_name'],
                'phone'      => $user['phone'],
            ],
            'wallet'       => [
                'balance'    => (int) $wallet['balance'],
                'currency'   => $wallet['currency'],
                'updated_at' => $wallet['updated_at'],
            ],
            'transactions' => $result['data'],
            'total'        => $result['total'],
        ]);
    }
}
